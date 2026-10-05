<?php

namespace App\Modules\Customers\Http\Controllers;

use App\Modules\CashRegister\Exceptions\InsufficientCashException;
use App\Modules\Customers\Exceptions\CashRegisterNotOpenException;
use App\Modules\Customers\Exceptions\InvalidCustomerPaymentException;
use App\Modules\Customers\Http\Requests\RecordCustomerPaymentRequest;
use App\Modules\Customers\Http\Resources\CustomerAccountEntryResource;
use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Services\CustomerAccountService;
use App\Modules\Tenancy\Models\Store;
use App\Shared\Http\Controllers\ApiController;
use Illuminate\Http\Request;

/** Compte client (crédit) — docs/customers.md §12. */
class CustomerAccountController extends ApiController
{
    public function __construct(private readonly CustomerAccountService $accounts) {}

    /** GET clients/{customer}/compte — historique (plus récent d'abord) et solde. */
    public function show(Store $store, Customer $customer, Request $request)
    {
        $this->authorize('view', [$customer, $store]);

        $entries = $customer->accountEntries()
            ->with(['createdBy', 'reference'])
            ->latest('id')
            ->paginate(min((int) $request->integer('par_page', 20), 100));

        // Liste paginée standard ; le solde est dans meta.solde.
        return $this->success(CustomerAccountEntryResource::collection($entries), null, ['solde' => $this->accounts->balance($customer)]);
    }

    /** POST clients/{customer}/paiements — le client rembourse tout ou partie de sa dette. */
    public function pay(Store $store, Customer $customer, RecordCustomerPaymentRequest $request)
    {
        $this->authorize('manageCredit', [$customer, $store]);

        try {
            $entry = $this->accounts->recordPayment($customer, $request->validated(), $request->user()?->id);
        } catch (InvalidCustomerPaymentException $e) {
            return $this->error($e->getMessage(), [], 422, 'PAIEMENT_INVALIDE');
        } catch (CashRegisterNotOpenException $e) {
            return $this->error($e->getMessage(), [], 422, 'AUCUNE_SESSION_CAISSE_OUVERTE');
        } catch (InsufficientCashException $e) {
            return $this->error($e->getMessage(), [], 422, 'SOLDE_CAISSE_INSUFFISANT');
        }

        return $this->success([
            'mouvement' => new CustomerAccountEntryResource($entry->load('createdBy')),
            'solde' => $this->accounts->balance($customer),
        ], 'Paiement enregistré.', [], 201);
    }
}

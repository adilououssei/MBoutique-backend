<?php

namespace App\Modules\Customers\Services;

use App\Modules\CashRegister\Exceptions\CashRegisterSessionClosedException;
use App\Modules\CashRegister\Models\CashRegister;
use App\Modules\CashRegister\Models\CashRegisterSession;
use App\Modules\CashRegister\Services\CashRegisterService;
use App\Modules\Customers\Exceptions\CashRegisterNotOpenException;
use App\Modules\Customers\Exceptions\InvalidCustomerPaymentException;
use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Models\CustomerAccountEntry;
use App\Modules\Sales\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Seul point d'écriture du compte client (docs/customers.md §12). Chaque
 * écriture verrouille la ligne `clients` : deux remboursements simultanés
 * ne peuvent pas dépasser la dette. Ordre des verrous pour une vente :
 * Inventory, puis client, puis CashRegister.
 */
class CustomerAccountService
{
    public const MODE_CASH_REGISTER = 'caisse';

    public const MODE_EXTERNAL = 'externe';

    public function __construct(private readonly CashRegisterService $cashRegisters) {}

    /** Solde dû : positif = le client doit, négatif = avoir. */
    public function balance(Customer $customer): string
    {
        return number_format((float) CustomerAccountEntry::query()->where('client_id', $customer->id)->sum('montant'), 2, '.', '');
    }

    /** Part non payée d'une vente à crédit : la dette augmente. */
    public function recordCreditSale(Customer $customer, string $amount, ?int $userId, Model $sale, ?string $note = null): CustomerAccountEntry
    {
        return DB::transaction(function () use ($customer, $amount, $userId, $sale, $note) {
            $this->lock($customer);

            return $this->entry($customer, CustomerAccountEntry::CREDIT_SALE, $amount, $userId, $sale, $note);
        });
    }

    /**
     * Annulation d'une vente à crédit : la part à crédit est retirée de la
     * dette. Si le client avait déjà remboursé, le solde devient un avoir.
     */
    public function reverseCreditSale(Customer $customer, string $amount, ?int $userId, Model $sale, ?string $note = null): CustomerAccountEntry
    {
        return DB::transaction(function () use ($customer, $amount, $userId, $sale, $note) {
            $this->lock($customer);

            return $this->entry($customer, CustomerAccountEntry::SALE_CANCELLED, bcmul($amount, '-1', 2), $userId, $sale, $note);
        });
    }

    /**
     * Remboursement d'une dette. En mode `caisse`, l'argent entre dans la
     * session ouverte (mouvement référençant cette écriture) ; en mode
     * `externe` (mobile money, virement…), seul le compte client bouge.
     */
    /**
     * @param  array{montant: string|float|int, mode: string, caisse_id?: int|null, note?: string|null}  $data
     */
    public function recordPayment(Customer $customer, array $data, ?int $userId): CustomerAccountEntry
    {
        return DB::transaction(function () use ($customer, $data, $userId) {
            $this->lock($customer);

            $amount = Money::round((string) $data['montant']);
            $mode = $data['mode'];
            $note = $data['note'] ?? null;
            $session = null;

            $due = $this->balance($customer);
            if (bccomp($amount, '0', 2) <= 0) {
                throw new InvalidCustomerPaymentException('Le montant doit être supérieur à zéro.');
            }
            if (bccomp($amount, $due, 2) > 0) {
                throw new InvalidCustomerPaymentException(bccomp($due, '0', 2) > 0
                    ? "Le montant dépasse la dette du client ({$due})."
                    : "{$customer->nom} n'a aucune dette.");
            }

            if ($mode === self::MODE_CASH_REGISTER) {
                $register = CashRegister::query()->findOrFail($data['caisse_id']);
                if ($register->session_ouverte_id === null) {
                    throw new CashRegisterNotOpenException("La caisse \"{$register->nom}\" n'a pas de session ouverte.");
                }
                $session = CashRegisterSession::query()->findOrFail($register->session_ouverte_id);
            }

            $entry = $this->entry($customer, CustomerAccountEntry::PAYMENT, bcmul($amount, '-1', 2), $userId, null, $note, $mode, $session);

            if ($session !== null) {
                try {
                    $this->cashRegisters->recordReceipt($session, $amount, $userId, $entry, "Remboursement de {$customer->nom}");
                } catch (CashRegisterSessionClosedException $e) {
                    throw new CashRegisterNotOpenException($e->getMessage(), previous: $e);
                }
            }

            return $entry;
        });
    }

    private function lock(Customer $customer): void
    {
        Customer::withTrashed()->whereKey($customer->id)->lockForUpdate()->firstOrFail();
    }

    private function entry(
        Customer $customer,
        string $type,
        string $amount,
        ?int $userId,
        ?Model $reference,
        ?string $note,
        ?string $mode = null,
        ?CashRegisterSession $session = null,
    ): CustomerAccountEntry {
        return CustomerAccountEntry::create([
            'client_id' => $customer->id,
            'type' => $type,
            'montant' => $amount,
            'mode' => $mode,
            'session_caisse_id' => $session?->id,
            'reference_type' => $reference?->getMorphClass(),
            'reference_id' => $reference?->getKey(),
            'note' => $note,
            'cree_par_id' => $userId,
        ]);
    }
}

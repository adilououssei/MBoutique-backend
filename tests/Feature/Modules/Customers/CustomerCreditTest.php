<?php

namespace Tests\Feature\Modules\Customers;

use App\Models\User;
use App\Modules\CashRegister\Models\CashMovement;
use App\Modules\CashRegister\Models\CashRegister;
use App\Modules\Catalog\Models\Service;
use App\Modules\Customers\Models\Customer;
use App\Modules\Tenancy\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesStoresWithFeatures;
use Tests\TestCase;

/** Vente à crédit et compte client — docs/customers.md §12, docs/sales.md §24. */
class CustomerCreditTest extends TestCase
{
    use CreatesStoresWithFeatures, RefreshDatabase;

    /** @return array{owner: User, store: Store, register: CashRegister, service: Service, customer: Customer} */
    private function setUpStore(): array
    {
        ['proprietaire' => $owner, 'store' => $store] = $this->createStoreWithFeatures(['services', 'clients', 'caisse', 'ventes']);
        Sanctum::actingAs($owner);

        $register = CashRegister::factory()->for($store)->create();
        $this->postJson("/api/boutiques/{$store->id}/caisses/{$register->id}/sessions", ['montant_ouverture' => 0])->assertStatus(201);
        $service = Service::factory()->for($store)->create(['prix' => 10000]);
        $customer = Customer::factory()->for($store)->create(['nom' => 'Awa']);

        return ['owner' => $owner, 'store' => $store, 'register' => $register, 'service' => $service, 'customer' => $customer];
    }

    private function sellOnCredit(Store $store, CashRegister $register, Service $service, Customer $customer, float $deposit = 0): array
    {
        return $this->postJson("/api/boutiques/{$store->id}/ventes/encaisser", [
            'lignes' => [['service_id' => $service->id, 'quantite' => 1]],
            'caisse_id' => $register->id,
            'client_id' => $customer->id,
            'mode_paiement' => 'credit',
            'acompte' => $deposit,
        ])->assertStatus(201)->json('donnees');
    }

    private function drawer(CashRegister $register): string
    {
        return (string) CashMovement::where('session_caisse_id', $register->fresh()->session_ouverte_id)->latest('id')->value('solde_apres');
    }

    public function test_a_credit_sale_puts_the_unpaid_part_on_the_customer_account(): void
    {
        ['store' => $store, 'register' => $register, 'service' => $service, 'customer' => $customer] = $this->setUpStore();

        $sale = $this->sellOnCredit($store, $register, $service, $customer, 3000);

        $this->assertSame('credit', $sale['mode_paiement']);
        $this->assertSame('3000.00', $sale['acompte']);
        $this->assertSame('7000.00', $sale['montant_credit']);
        $this->assertSame('3000.00', $this->drawer($register));

        $this->getJson("/api/boutiques/{$store->id}/clients/{$customer->id}/compte")
            ->assertStatus(200)
            ->assertJsonPath('meta.solde', '7000.00')
            ->assertJsonPath('donnees.0.type', 'vente_credit')
            ->assertJsonPath('donnees.0.vente.reference', $sale['reference']);
        $this->getJson("/api/boutiques/{$store->id}/clients/{$customer->id}")->assertJsonPath('donnees.solde', '7000.00');
    }

    public function test_a_credit_sale_needs_a_customer_and_a_deposit_within_the_total(): void
    {
        ['store' => $store, 'register' => $register, 'service' => $service, 'customer' => $customer] = $this->setUpStore();
        $payload = ['lignes' => [['service_id' => $service->id, 'quantite' => 1]], 'caisse_id' => $register->id, 'mode_paiement' => 'credit'];

        $this->postJson("/api/boutiques/{$store->id}/ventes/encaisser", $payload)
            ->assertStatus(422)->assertJsonValidationErrors('client_id', 'erreurs');
        $this->postJson("/api/boutiques/{$store->id}/ventes/encaisser", [...$payload, 'client_id' => $customer->id, 'acompte' => 12000])
            ->assertStatus(422)->assertJsonPath('code', 'ACOMPTE_INVALIDE');
    }

    public function test_payments_reduce_the_debt_and_cash_ones_enter_the_drawer(): void
    {
        ['store' => $store, 'register' => $register, 'service' => $service, 'customer' => $customer] = $this->setUpStore();
        $this->sellOnCredit($store, $register, $service, $customer);
        $url = "/api/boutiques/{$store->id}/clients/{$customer->id}/paiements";

        $this->postJson($url, ['montant' => 4000, 'mode' => 'caisse', 'caisse_id' => $register->id])
            ->assertStatus(201)->assertJsonPath('donnees.solde', '6000.00');
        $this->assertSame('4000.00', $this->drawer($register));

        $this->postJson($url, ['montant' => 1000, 'mode' => 'externe', 'note' => 'Mobile money'])
            ->assertStatus(201)->assertJsonPath('donnees.solde', '5000.00');
        $this->assertSame('4000.00', $this->drawer($register));

        $this->postJson($url, ['montant' => 5001, 'mode' => 'externe'])->assertStatus(422)->assertJsonPath('code', 'PAIEMENT_INVALIDE');
        $this->postJson($url, ['montant' => 10, 'mode' => 'caisse'])->assertStatus(422)->assertJsonValidationErrors('caisse_id', 'erreurs');
    }

    public function test_cancelling_a_credit_sale_refunds_only_the_deposit_and_clears_the_debt(): void
    {
        ['store' => $store, 'register' => $register, 'service' => $service, 'customer' => $customer] = $this->setUpStore();
        $sale = $this->sellOnCredit($store, $register, $service, $customer, 2000);

        $this->postJson("/api/boutiques/{$store->id}/ventes/{$sale['id']}/annuler", ['motif' => 'Erreur'])->assertStatus(200);

        $this->assertSame('-2000.00', (string) CashMovement::where('type', 'remboursement')->value('montant'));
        $this->assertSame('0.00', $this->drawer($register));
        $this->getJson("/api/boutiques/{$store->id}/clients/{$customer->id}/compte")->assertJsonPath('meta.solde', '0.00');
    }

    public function test_debtors_filter_lists_only_customers_who_owe(): void
    {
        ['store' => $store, 'register' => $register, 'service' => $service, 'customer' => $customer] = $this->setUpStore();
        Customer::factory()->for($store)->create(['nom' => 'Bruno']);
        $this->sellOnCredit($store, $register, $service, $customer);

        $this->getJson("/api/boutiques/{$store->id}/clients?debiteurs=1")
            ->assertJsonCount(1, 'donnees')
            ->assertJsonPath('donnees.0.nom', 'Awa')
            ->assertJsonPath('donnees.0.solde', '10000.00');
        $this->getJson("/api/boutiques/{$store->id}/clients")->assertJsonCount(2, 'donnees');
    }

    public function test_an_employee_cannot_sell_on_credit_or_record_payments(): void
    {
        ['store' => $store, 'register' => $register, 'service' => $service, 'customer' => $customer] = $this->setUpStore();
        $this->sellOnCredit($store, $register, $service, $customer);
        $employee = User::factory()->create();
        $cashier = User::factory()->create();
        $this->postJson("/api/boutiques/{$store->id}/membres", ['email' => $employee->email, 'role' => 'employe'])->assertStatus(201);
        $this->postJson("/api/boutiques/{$store->id}/membres", ['email' => $cashier->email, 'role' => 'caissier'])->assertStatus(201);
        $url = "/api/boutiques/{$store->id}/clients/{$customer->id}/paiements";

        Sanctum::actingAs($employee);
        $this->postJson($url, ['montant' => 100, 'mode' => 'externe'])->assertStatus(403);

        Sanctum::actingAs($cashier);
        $this->postJson($url, ['montant' => 100, 'mode' => 'externe'])->assertStatus(201);
        $this->sellOnCredit($store, $register, $service, $customer);
    }
}

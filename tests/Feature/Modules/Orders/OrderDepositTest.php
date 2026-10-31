<?php

namespace Tests\Feature\Modules\Orders;

use App\Models\User;
use App\Modules\CashRegister\Models\CashMovement;
use App\Modules\CashRegister\Models\CashRegister;
use App\Modules\Catalog\Models\Product;
use App\Modules\Inventory\Models\Stock;
use App\Modules\Orders\Models\Order;
use App\Modules\Sales\Models\Sale;
use App\Modules\Tenancy\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesStoresWithFeatures;
use Tests\TestCase;

/** Acompte sur une commande (préparation de boucherie) — docs/modules.md §Orders. */
class OrderDepositTest extends TestCase
{
    use CreatesStoresWithFeatures, RefreshDatabase;

    /** @return array{owner: User, store: Store, register: CashRegister, filet: Product} */
    private function butcher(): array
    {
        ['proprietaire' => $owner, 'store' => $store] = $this->createStoreWithFeatures(['produits', 'stock', 'commandes', 'ventes', 'caisse', 'clients']);
        Sanctum::actingAs($owner);
        $register = CashRegister::factory()->for($store)->create();
        $this->postJson("/api/boutiques/{$store->id}/caisses/{$register->id}/sessions", ['montant_ouverture' => 0])->assertStatus(201);

        $filet = Product::factory()->for($store)->create(['nom' => 'Filet de bœuf', 'unite' => 'kg', 'vente_detail_active' => true, 'prix_detail' => 3500, 'vente_gros_active' => false]);
        $this->postJson("/api/boutiques/{$store->id}/stocks/{$filet->id}/mouvements", ['type' => 'initial', 'quantite' => 20])->assertStatus(201);

        return ['owner' => $owner, 'store' => $store, 'register' => $register, 'filet' => $filet];
    }

    private function url(Store $store, string $path = ''): string
    {
        return "/api/boutiques/{$store->id}/commandes{$path}";
    }

    /** 2,5 kg de filet à 3 500 FCFA/kg = 8 750 FCFA. */
    private function openWeightOrder(Store $store, Product $filet): int
    {
        return $this->postJson($this->url($store), [
            'type' => 'a_emporter',
            'nom_client' => 'Awa',
            'date_promise' => now()->addDay()->toIso8601String(),
            'lignes' => [['produit_id' => $filet->id, 'quantite' => 2.5, 'note' => 'En tranches fines']],
        ])->assertStatus(201)->assertJsonPath('donnees.total', '8750.00')->json('donnees.id');
    }

    public function test_the_deposit_enters_the_register_and_only_the_rest_is_collected_at_checkout(): void
    {
        ['store' => $store, 'register' => $register, 'filet' => $filet] = $this->butcher();
        $id = $this->openWeightOrder($store, $filet);

        $this->postJson($this->url($store, "/{$id}/acompte"), ['montant' => 3000, 'caisse_id' => $register->id])
            ->assertStatus(200)
            ->assertJsonPath('donnees.acompte', '3000.00')
            ->assertJsonPath('donnees.reste_a_payer', '5750.00');

        $receipt = CashMovement::where('type', 'entree')->firstOrFail();
        $this->assertSame('3000.00', (string) $receipt->montant);
        $this->assertSame('commande', $receipt->reference_type);
        $this->assertSame($id, (int) $receipt->reference_id);

        $order = $this->postJson($this->url($store, "/{$id}/encaisser"), ['caisse_id' => $register->id])
            ->assertStatus(200)->assertJsonPath('donnees.statut', 'payee')->json('donnees');

        // La vente garde son total complet (chiffre d'affaires), la caisse ne
        // reçoit que le reste : 3 000 + 5 750 = 8 750 encaissés une seule fois.
        $sale = Sale::findOrFail($order['vente_id']);
        $this->assertSame('8750.00', (string) $sale->montant_total);
        $this->assertSame('5750.00', (string) CashMovement::where('type', 'vente')->value('montant'));
        $this->assertSame(17.5, (float) Stock::where('produit_id', $filet->id)->value('quantite'));
    }

    public function test_a_fully_prepaid_order_checks_out_without_a_second_cash_entry(): void
    {
        ['store' => $store, 'register' => $register, 'filet' => $filet] = $this->butcher();
        $id = $this->openWeightOrder($store, $filet);
        $this->postJson($this->url($store, "/{$id}/acompte"), ['montant' => 8750, 'caisse_id' => $register->id])->assertStatus(200);

        $this->postJson($this->url($store, "/{$id}/encaisser"), ['caisse_id' => $register->id])->assertStatus(200)->assertJsonPath('donnees.statut', 'payee');
        $this->assertSame(0, CashMovement::where('type', 'vente')->count());
    }

    public function test_the_deposit_can_never_exceed_the_order_total(): void
    {
        ['store' => $store, 'register' => $register, 'filet' => $filet] = $this->butcher();
        $id = $this->openWeightOrder($store, $filet);

        $this->postJson($this->url($store, "/{$id}/acompte"), ['montant' => 9000, 'caisse_id' => $register->id])
            ->assertStatus(422)->assertJsonPath('code', 'ACOMPTE_INVALIDE');
        $this->assertSame(0, CashMovement::where('type', 'entree')->count());

        $this->postJson($this->url($store, "/{$id}/acompte"), ['montant' => 5000, 'caisse_id' => $register->id])->assertStatus(200);
        $line = Order::findOrFail($id)->items()->value('id');

        // Réduire la commande sous l'acompte est refusé.
        $this->putJson($this->url($store, "/{$id}/lignes/{$line}"), ['quantite' => 1])
            ->assertStatus(422)->assertJsonPath('code', 'ACOMPTE_INVALIDE');
        $this->deleteJson($this->url($store, "/{$id}/lignes/{$line}"))
            ->assertStatus(422)->assertJsonPath('code', 'ACOMPTE_INVALIDE');

        // Une remise qui ramène le total sous l'acompte bloque l'encaissement, sans rien écrire.
        $this->postJson($this->url($store, "/{$id}/encaisser"), ['caisse_id' => $register->id, 'montant_remise' => 4000])
            ->assertStatus(422)->assertJsonPath('code', 'ACOMPTE_INVALIDE');
        $this->assertSame(0, Sale::count());
        $this->getJson($this->url($store, "/{$id}"))->assertJsonPath('donnees.statut', 'en_attente');
    }

    public function test_cancelling_refunds_the_deposit_unless_the_shop_keeps_it(): void
    {
        ['store' => $store, 'register' => $register, 'filet' => $filet] = $this->butcher();

        $refunded = $this->openWeightOrder($store, $filet);
        $this->postJson($this->url($store, "/{$refunded}/acompte"), ['montant' => 3000, 'caisse_id' => $register->id])->assertStatus(200);
        $this->postJson($this->url($store, "/{$refunded}/annuler"), ['motif' => 'Client a changé d’avis'])
            ->assertStatus(422)->assertJsonPath('code', 'ACOMPTE_INVALIDE');
        $this->postJson($this->url($store, "/{$refunded}/annuler"), ['motif' => 'Client a changé d’avis', 'caisse_id' => $register->id])
            ->assertStatus(200)->assertJsonPath('donnees.statut', 'annulee');
        $this->assertSame('-3000.00', (string) CashMovement::where('type', 'remboursement')->value('montant'));

        $kept = $this->openWeightOrder($store, $filet);
        $this->postJson($this->url($store, "/{$kept}/acompte"), ['montant' => 2000, 'caisse_id' => $register->id])->assertStatus(200);
        $this->postJson($this->url($store, "/{$kept}/annuler"), ['motif' => 'Jamais venu', 'conserver_acompte' => true])
            ->assertStatus(200)->assertJsonPath('donnees.acompte', '2000.00');
        $this->assertSame(1, CashMovement::where('type', 'remboursement')->count());
    }

    public function test_an_order_without_deposit_still_cancels_without_a_register(): void
    {
        ['store' => $store, 'filet' => $filet] = $this->butcher();
        $id = $this->openWeightOrder($store, $filet);

        $this->postJson($this->url($store, "/{$id}/annuler"), ['motif' => 'Erreur'])->assertStatus(200)->assertJsonPath('donnees.statut', 'annulee');
        $this->assertSame(0, CashMovement::where('type', 'remboursement')->count());
    }

    public function test_a_deposit_needs_an_open_session_and_the_checkout_right(): void
    {
        ['owner' => $owner, 'store' => $store, 'register' => $register, 'filet' => $filet] = $this->butcher();
        $id = $this->openWeightOrder($store, $filet);
        $closed = CashRegister::factory()->for($store)->create();

        $this->postJson($this->url($store, "/{$id}/acompte"), ['montant' => 1000, 'caisse_id' => $closed->id])
            ->assertStatus(422)->assertJsonPath('code', 'AUCUNE_SESSION_CAISSE_OUVERTE');

        $employee = User::factory()->create();
        $this->postJson("/api/boutiques/{$store->id}/membres", ['email' => $employee->email, 'role' => 'employe'])->assertSuccessful();
        Sanctum::actingAs($employee);
        $this->postJson($this->url($store, "/{$id}/acompte"), ['montant' => 1000, 'caisse_id' => $register->id])->assertStatus(403);

        Sanctum::actingAs($owner);
        $this->assertSame('0.00', (string) Order::findOrFail($id)->montant_acompte);
    }
}

<?php

namespace Tests\Feature\Modules\Equipment;

use App\Models\User;
use App\Modules\CashRegister\Models\CashMovement;
use App\Modules\CashRegister\Models\CashRegister;
use App\Modules\Catalog\Models\Product;
use App\Modules\Conservation\Models\StockLot;
use App\Modules\Expenses\Models\Expense;
use App\Modules\Tenancy\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesStoresWithFeatures;
use Tests\TestCase;

/** Équipements, entretiens, pannes et espaces de conservation — docs/modules.md §Equipment. */
class EquipmentTest extends TestCase
{
    use CreatesStoresWithFeatures, RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return array{owner: User, store: Store, register: CashRegister} */
    private function butcher(): array
    {
        Carbon::setTestNow('2026-11-02 09:00:00');
        ['proprietaire' => $owner, 'store' => $store] = $this->createStoreWithFeatures(['produits', 'stock', 'caisse', 'conservation', 'depenses', 'equipements']);
        Sanctum::actingAs($owner);
        $register = CashRegister::factory()->for($store)->create();
        $this->postJson("/api/boutiques/{$store->id}/caisses/{$register->id}/sessions", ['montant_ouverture' => 100000])->assertStatus(201);

        return ['owner' => $owner, 'store' => $store, 'register' => $register];
    }

    private function url(Store $store, string $path = ''): string
    {
        return "/api/boutiques/{$store->id}/equipements{$path}";
    }

    private function freezer(Store $store): int
    {
        return $this->postJson($this->url($store), ['nom' => 'Congélateur coffre', 'type' => 'congelateur', 'marque' => 'Liebherr', 'frequence_entretien_jours' => 30])
            ->assertStatus(201)
            ->assertJsonPath('donnees.stockage', true)
            ->assertJsonPath('donnees.statut', 'en_service')
            ->assertJsonPath('donnees.prochain_entretien_le', '2026-12-02')
            ->json('donnees.id');
    }

    public function test_breakdown_repair_and_maintenance_follow_the_equipment_status_and_schedule(): void
    {
        ['owner' => $owner, 'store' => $store, 'register' => $register] = $this->butcher();
        $id = $this->freezer($store);

        // Le boucher signale la panne, mais ne déclare pas la réparation.
        $employee = User::factory()->create();
        $this->postJson("/api/boutiques/{$store->id}/membres", ['email' => $employee->email, 'role' => 'employe'])->assertStatus(201);
        Sanctum::actingAs($employee);
        $this->postJson($this->url($store, "/{$id}/interventions"), ['type' => 'panne', 'description' => 'Ne refroidit plus'])
            ->assertStatus(201)->assertJsonPath('donnees.statut', 'en_panne');
        $this->postJson($this->url($store, "/{$id}/interventions"), ['type' => 'reparation', 'description' => 'Réparé'])->assertStatus(403);
        $this->postJson($this->url($store), ['nom' => 'X', 'type' => 'balance'])->assertStatus(403);

        // Réparation payée en caisse : une dépense « Entretien et réparations », une seule fois.
        Sanctum::actingAs($owner);
        $this->postJson($this->url($store, "/{$id}/interventions"), [
            'type' => 'reparation', 'description' => 'Compresseur changé', 'cout' => 15000, 'prestataire' => 'Froid Service',
            'paiement' => ['mode' => 'caisse', 'caisse_id' => $register->id],
        ])->assertStatus(201)->assertJsonPath('donnees.statut', 'en_service')->assertJsonPath('donnees.interventions.0.cout', '15000.00');
        $expense = Expense::firstOrFail();
        $this->assertSame('entretien', $expense->categorie->value);
        $this->assertSame('Réparation : Congélateur coffre', $expense->libelle);
        $this->assertSame('-15000.00', (string) CashMovement::where('type', 'sortie')->value('montant'));

        // Entretien : la prochaine échéance avance de 30 jours.
        $this->postJson($this->url($store, "/{$id}/interventions"), ['type' => 'entretien', 'description' => 'Dégivrage'])
            ->assertStatus(201)
            ->assertJsonPath('donnees.dernier_entretien_le', '2026-11-02')
            ->assertJsonPath('donnees.prochain_entretien_le', '2026-12-02');

        $this->getJson($this->url($store, "/{$id}"))->assertJsonCount(3, 'donnees.interventions');
    }

    public function test_lots_are_placed_in_storage_equipment_only(): void
    {
        ['store' => $store] = $this->butcher();
        $freezer = $this->freezer($store);
        $scale = $this->postJson($this->url($store), ['nom' => 'Balance', 'type' => 'balance'])->assertJsonPath('donnees.stockage', false)->json('donnees.id');

        $chicken = Product::factory()->for($store)->create(['unite' => 'kg', 'conservation' => 'congele', 'duree_conservation_jours' => 90]);
        $this->postJson("/api/boutiques/{$store->id}/stocks/{$chicken->id}/mouvements", ['type' => 'initial', 'quantite' => 20])->assertStatus(201);
        $lot = StockLot::firstOrFail();

        $this->putJson("/api/boutiques/{$store->id}/lots/{$lot->id}", ['equipement_id' => $scale])->assertStatus(422);
        $this->putJson("/api/boutiques/{$store->id}/lots/{$lot->id}", ['equipement_id' => $freezer])
            ->assertStatus(200)->assertJsonPath('donnees.equipement.nom', 'Congélateur coffre');
        $this->getJson("/api/boutiques/{$store->id}/lots?equipement_id={$freezer}")->assertJsonCount(1, 'donnees');
        $this->getJson($this->url($store, '?stockage=1'))->assertJsonCount(1, 'donnees');
    }

    public function test_maintenance_alerts_are_sent_once_per_due_date(): void
    {
        ['store' => $store] = $this->butcher();
        $this->postJson($this->url($store), ['nom' => 'Balance', 'type' => 'balance', 'prochain_entretien_le' => '2026-11-03'])->assertStatus(201);

        $this->artisan('equipements:alertes-entretien')->assertSuccessful();
        $this->artisan('equipements:alertes-entretien')->assertSuccessful();
        $this->assertSame(1, DatabaseNotification::count());
        $this->assertSame('Entretien à faire', DatabaseNotification::first()->data['titre']);

        // Une épicerie sans la feature n'a pas accès.
        ['store' => $grocery] = $this->createStoreWithFeatures(['produits']);
        $this->getJson($this->url($grocery))->assertStatus(403)->assertJsonPath('code', 'FONCTIONNALITE_DESACTIVEE');
    }
}

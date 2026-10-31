<?php

namespace Tests\Feature\Modules\Conservation;

use App\Models\User;
use App\Modules\CashRegister\Models\CashRegister;
use App\Modules\Catalog\Models\Product;
use App\Modules\Conservation\Models\StockLot;
use App\Modules\Inventory\Models\Stock;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Tenancy\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesStoresWithFeatures;
use Tests\TestCase;

/** Lots, dates limites, pertes valorisées et alertes — docs/modules.md §Conservation. */
class LotTrackingTest extends TestCase
{
    use CreatesStoresWithFeatures, RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return array{owner: User, store: Store, register: CashRegister, filet: Product} */
    private function butcher(array $features = ['produits', 'stock', 'ventes', 'caisse', 'decoupe', 'preparations', 'conservation']): array
    {
        Carbon::setTestNow('2026-11-01 09:00:00');
        ['proprietaire' => $owner, 'store' => $store] = $this->createStoreWithFeatures($features);
        Sanctum::actingAs($owner);
        $register = CashRegister::factory()->for($store)->create();
        $this->postJson("/api/boutiques/{$store->id}/caisses/{$register->id}/sessions", ['montant_ouverture' => 0])->assertStatus(201);

        $filet = $this->product($store, 'Filet', 7000, 4000, 3);

        return ['owner' => $owner, 'store' => $store, 'register' => $register, 'filet' => $filet];
    }

    private function product(Store $store, string $name, ?int $salePrice, ?int $purchasePrice, ?int $shelfLife): Product
    {
        return Product::factory()->for($store)->create([
            'nom' => $name, 'unite' => 'kg', 'conservation' => 'frais', 'duree_conservation_jours' => $shelfLife,
            'prix_achat' => $purchasePrice, 'vente_detail_active' => $salePrice !== null, 'prix_detail' => $salePrice, 'vente_gros_active' => false,
        ]);
    }

    private function move(Store $store, Product $product, array $data): void
    {
        $this->postJson("/api/boutiques/{$store->id}/stocks/{$product->id}/mouvements", $data)->assertStatus(201);
    }

    private function sell(array $p, Product $product, float $quantity): void
    {
        $this->postJson("/api/boutiques/{$p['store']->id}/ventes/encaisser", [
            'lignes' => [['produit_id' => $product->id, 'mode_prix' => 'detail', 'quantite' => $quantity]],
            'caisse_id' => $p['register']->id,
        ])->assertStatus(201);
    }

    public function test_each_entry_is_a_dated_lot_and_sales_consume_the_nearest_date_first(): void
    {
        $p = $this->butcher();
        $this->move($p['store'], $p['filet'], ['type' => 'initial', 'quantite' => 10]);
        Carbon::setTestNow('2026-11-02 09:00:00');
        $this->move($p['store'], $p['filet'], ['type' => 'achat', 'quantite' => 5]);

        $lots = StockLot::orderBy('id')->get();
        $this->assertCount(2, $lots);
        $this->assertSame('2026-11-04', $lots[0]->date_limite->toDateString()); // reçu le 1er + 3 jours
        $this->assertSame('2026-11-05', $lots[1]->date_limite->toDateString());
        $this->assertSame('frais', $lots[0]->conservation->value);
        $this->assertStringStartsWith('LOT-261101-', $lots[0]->numero);

        // 12 kg vendus : le premier lot (date la plus proche) est vidé, puis 2 kg du second.
        $this->sell($p, $p['filet'], 12);
        $this->assertSame('0.000', (string) $lots[0]->refresh()->quantite_restante);
        $this->assertSame('3.000', (string) $lots[1]->refresh()->quantite_restante);

        $this->getJson("/api/boutiques/{$p['store']->id}/lots")
            ->assertStatus(200)
            ->assertJsonCount(1, 'donnees')
            ->assertJsonPath('donnees.0.quantite_restante', '3.000')
            ->assertJsonPath('donnees.0.jours_restants', 3)
            ->assertJsonPath('donnees.0.etat', 'ok');
    }

    public function test_an_expired_lot_is_declared_lost_with_its_value(): void
    {
        $p = $this->butcher();
        $this->move($p['store'], $p['filet'], ['type' => 'initial', 'quantite' => 10]);
        $this->move($p['store'], $p['filet'], ['type' => 'achat', 'quantite' => 4]);
        [$old, $fresh] = StockLot::orderBy('id')->get()->all();

        // L'étiquette du second lot indique une date déjà passée.
        $this->putJson("/api/boutiques/{$p['store']->id}/lots/{$fresh->id}", ['date_limite' => '2026-10-31'])
            ->assertStatus(200)->assertJsonPath('donnees.etat', 'perime')->assertJsonPath('donnees.jours_restants', -1);
        $this->getJson("/api/boutiques/{$p['store']->id}/lots/alertes")->assertJsonPath('donnees.perimes', 1)->assertJsonPath('donnees.bientot', 0);

        // La perte vise ce lot-là, pas le plus ancien.
        $this->postJson("/api/boutiques/{$p['store']->id}/lots/{$fresh->id}/perte", [])
            ->assertStatus(200)->assertJsonPath('donnees.quantite_restante', '0.000');
        $this->assertSame('10.000', (string) $old->refresh()->quantite_restante);
        $this->assertSame(10.0, (float) Stock::where('produit_id', $p['filet']->id)->value('quantite'));

        $loss = StockMovement::where('type', 'perte')->firstOrFail();
        $this->assertSame('peremption', $loss->origine_perte->value);
        $this->assertSame('4000.00', (string) $loss->cout_unitaire);
        $this->assertSame('lot_stock', $loss->reference_type);

        $this->postJson("/api/boutiques/{$p['store']->id}/lots/{$old->id}/perte", ['quantite' => 11])
            ->assertStatus(422)->assertJsonPath('code', 'QUANTITE_INVALIDE');
    }

    public function test_the_losses_report_values_stock_losses_by_origin_and_cutting_losses(): void
    {
        $p = $this->butcher();
        $carcass = $this->product($p['store'], 'Carcasse', null, 2500, null);
        $this->move($p['store'], $carcass, ['type' => 'initial', 'quantite' => 100]);
        $this->move($p['store'], $p['filet'], ['type' => 'initial', 'quantite' => 10]);

        // Perte manuelle avec origine : 1,5 kg de filet avarié à 4 000 = 6 000.
        $this->move($p['store'], $p['filet'], ['type' => 'perte', 'quantite' => 1.5, 'origine_perte' => 'avarie']);
        $this->postJson("/api/boutiques/{$p['store']->id}/stocks/{$p['filet']->id}/mouvements", ['type' => 'ajustement_sortie', 'quantite' => 1, 'origine_perte' => 'avarie'])
            ->assertStatus(422);

        // Découpe : 100 kg → 85 kg, 15 kg perdus au coût matière de 2 500/kg = 37 500.
        $ragout = $this->product($p['store'], 'Ragoût', 3500, null, 3);
        $this->postJson("/api/boutiques/{$p['store']->id}/decoupes", [
            'entrees' => [['produit_id' => $carcass->id, 'quantite' => 100]],
            'sorties' => [['produit_id' => $ragout->id, 'quantite' => 85]],
        ])->assertStatus(201);

        $this->getJson("/api/boutiques/{$p['store']->id}/pertes")
            ->assertStatus(200)
            ->assertJsonPath('donnees.resume.pertes_stock.valeur', '6000.00')
            ->assertJsonPath('donnees.resume.decoupe.poids', '15.000')
            ->assertJsonPath('donnees.resume.decoupe.valeur', '37500.00')
            ->assertJsonPath('donnees.resume.valeur_totale', '43500.00')
            ->assertJsonPath('donnees.resume.par_origine.0.origine', 'avarie')
            ->assertJsonPath('donnees.pertes.0.libelle_origine', 'Produit avarié ou endommagé')
            ->assertJsonPath('donnees.pertes.0.valeur', '6000.00');
    }

    public function test_cut_pieces_and_preparations_enter_as_lots_with_their_own_dates(): void
    {
        $p = $this->butcher();
        $carcass = $this->product($p['store'], 'Carcasse', null, 2500, null);
        $this->move($p['store'], $carcass, ['type' => 'initial', 'quantite' => 50]);

        $this->postJson("/api/boutiques/{$p['store']->id}/decoupes", [
            'entrees' => [['produit_id' => $carcass->id, 'quantite' => 50]],
            'sorties' => [['produit_id' => $p['filet']->id, 'quantite' => 45]],
        ])->assertStatus(201);
        $piece = StockLot::where('produit_id', $p['filet']->id)->firstOrFail();
        $this->assertSame('45.000', (string) $piece->quantite_initiale);
        $this->assertSame('2026-11-04', $piece->date_limite->toDateString());
        $this->assertSame('0.000', (string) StockLot::where('produit_id', $carcass->id)->value('quantite_restante'));

        // Une préparation datée : le lot prend sa date « à consommer avant ».
        $merguez = $this->product($p['store'], 'Merguez', 5000, null, 10);
        $this->postJson("/api/boutiques/{$p['store']->id}/preparations", [
            'entrees' => [['produit_id' => $p['filet']->id, 'quantite' => 5]],
            'sorties' => [['produit_id' => $merguez->id, 'quantite' => 5]],
            'consommer_avant' => '2026-11-03',
        ])->assertStatus(201);
        $this->assertSame('2026-11-03', StockLot::where('produit_id', $merguez->id)->firstOrFail()->date_limite->toDateString());
        $this->assertSame('40.000', (string) $piece->refresh()->quantite_restante);
    }

    public function test_expiry_alerts_are_sent_once_per_state(): void
    {
        $p = $this->butcher();
        $this->move($p['store'], $p['filet'], ['type' => 'initial', 'quantite' => 10]); // limite le 4

        $this->artisan('conservation:alertes-peremption')->assertSuccessful(); // le 1er : rien
        $this->assertSame(0, DatabaseNotification::count());

        Carbon::setTestNow('2026-11-02 07:00:00'); // à 2 jours : « bientôt »
        $this->artisan('conservation:alertes-peremption')->assertSuccessful();
        $this->artisan('conservation:alertes-peremption')->assertSuccessful();
        $this->assertSame(1, DatabaseNotification::count());
        $this->assertSame('Date limite proche', DatabaseNotification::first()->data['titre']);

        Carbon::setTestNow('2026-11-05 07:00:00'); // dépassée : « périmé »
        $this->artisan('conservation:alertes-peremption')->assertSuccessful();
        $this->assertSame(2, DatabaseNotification::count());
        $this->assertSame(1, DatabaseNotification::where('data->etat', 'perime')->count());
    }

    public function test_other_domains_are_untouched_and_rights_follow_the_stock_permissions(): void
    {
        // Une épicerie (sans `conservation`) : aucun lot, pas d'accès.
        ['store' => $grocery] = $this->createStoreWithFeatures(['produits', 'stock']);
        $rice = Product::factory()->for($grocery)->create(['unite' => 'kg']);
        $this->move($grocery, $rice, ['type' => 'initial', 'quantite' => 10]);
        $this->assertSame(0, StockLot::withoutStoreScope()->count());
        $this->getJson("/api/boutiques/{$grocery->id}/lots")->assertStatus(403)->assertJsonPath('code', 'FONCTIONNALITE_DESACTIVEE');

        $p = $this->butcher();
        $this->move($p['store'], $p['filet'], ['type' => 'initial', 'quantite' => 10]);
        $lot = StockLot::firstOrFail();
        $employee = User::factory()->create();
        $this->postJson("/api/boutiques/{$p['store']->id}/membres", ['email' => $employee->email, 'role' => 'employe'])->assertStatus(201);

        Sanctum::actingAs($employee);
        $this->getJson("/api/boutiques/{$p['store']->id}/lots")->assertStatus(200);
        $this->getJson("/api/boutiques/{$p['store']->id}/pertes")->assertStatus(403);
        $this->postJson("/api/boutiques/{$p['store']->id}/lots/{$lot->id}/perte", [])->assertStatus(403);
    }

    public function test_product_conservation_settings_are_saved_through_the_catalog(): void
    {
        $p = $this->butcher();

        $this->postJson("/api/boutiques/{$p['store']->id}/produits", [
            'nom' => 'Cuisses de poulet', 'unite' => 'kg', 'conservation' => 'congele', 'duree_conservation_jours' => 90,
            'vente_detail_active' => true, 'prix_detail' => 2500,
        ])->assertStatus(201)->assertJsonPath('donnees.conservation', 'congele')->assertJsonPath('donnees.duree_conservation_jours', 90);

        $this->postJson("/api/boutiques/{$p['store']->id}/produits", ['nom' => 'X', 'conservation' => 'sous_vide'])->assertStatus(422);
    }
}

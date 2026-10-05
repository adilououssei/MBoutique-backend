<?php

namespace Tests\Feature\Modules\Reports;

use App\Models\User;
use App\Modules\CashRegister\Models\CashRegister;
use App\Modules\CashRegister\Models\CashRegisterSession;
use App\Modules\Catalog\Models\Product;
use App\Modules\Sales\Enums\SaleStatus;
use App\Modules\Sales\Models\Sale;
use App\Modules\Sales\Models\SaleItem;
use App\Modules\Tenancy\Models\Store;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesStoresWithFeatures;
use Tests\TestCase;

class DashboardReportTest extends TestCase
{
    use CreatesStoresWithFeatures, RefreshDatabase;

    private const NOW = '2026-10-04 15:30:00';

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse(self::NOW, 'UTC'));
    }

    /** @return array{proprietaire: User, store: Store} */
    private function reportingStore(array $features = ['produits', 'ventes', 'caisse', 'rapports']): array
    {
        ['proprietaire' => $owner, 'store' => $store] = $this->createStoreWithFeatures($features);
        Sanctum::actingAs($owner);

        return ['proprietaire' => $owner, 'store' => $store];
    }

    /**
     * Inserts a finished sale directly (the checkout flow itself is
     * covered by the Sales suite) so its timestamp can be controlled.
     *
     * @param  array<int, array{0: Product, 1: float|int, 2: float|int}>  $lines  [product, quantity, unit price]
     */
    private function recordSale(Store $store, string $soldAt, array $lines, array $attributes = []): Sale
    {
        $register = CashRegister::factory()->for($store)->create();
        $session = CashRegisterSession::factory()->for($store)->create(['caisse_id' => $register->id]);

        $total = array_sum(array_map(fn (array $line) => $line[1] * $line[2], $lines));
        $discount = $attributes['montant_remise'] ?? 0;

        $sale = Sale::factory()->for($store)->create([
            'caisse_id' => $register->id,
            'session_caisse_id' => $session->id,
            'sous_total' => $total,
            'montant_remise' => $discount,
            'montant_total' => $total - $discount,
            'vendue_le' => CarbonImmutable::parse($soldAt, 'UTC'),
            ...$attributes,
        ]);

        foreach ($lines as [$product, $quantity, $unitPrice]) {
            SaleItem::factory()->create([
                'boutique_id' => $store->id,
                'vente_id' => $sale->id,
                'produit_id' => $product->id,
                'nom_produit' => $product->nom,
                'prix_unitaire' => $unitPrice,
                'quantite' => $quantity,
                'montant_total' => $quantity * $unitPrice,
            ]);
        }

        return $sale;
    }

    private function dashboard(Store $store, ?string $period = null)
    {
        $query = $period ? "?periode={$period}" : '';

        return $this->getJson("/api/boutiques/{$store->id}/rapports/tableau-de-bord{$query}");
    }

    public function test_today_summary_aggregates_completed_sales(): void
    {
        ['store' => $store] = $this->reportingStore();
        $rice = Product::factory()->for($store)->create(['nom' => 'Riz 5kg', 'prix_achat' => 400]);
        $soap = Product::factory()->for($store)->create(['nom' => 'Savon', 'prix_achat' => 150]);

        $this->recordSale($store, '2026-10-04 09:10:00', [[$rice, 2, 600], [$soap, 4, 250]]); // 2200
        $this->recordSale($store, '2026-10-04 14:45:00', [[$rice, 1, 600]]); // 600

        $this->dashboard($store)
            ->assertOk()
            ->assertJsonPath('donnees.periode.code', 'aujourdhui')
            ->assertJsonPath('donnees.resume.chiffre_affaires', '2800.00')
            ->assertJsonPath('donnees.resume.nombre_ventes', 2)
            ->assertJsonPath('donnees.resume.panier_moyen', '1400.00')
            ->assertJsonPath('donnees.resume.articles_vendus', '7.000')
            // (2800) - (3×400 + 4×150 = 1800)
            ->assertJsonPath('donnees.resume.benefice_estime', '1000.00')
            ->assertJsonPath('donnees.resume.produits_sans_prix_achat', 0)
            ->assertJsonPath('donnees.meilleurs_produits.0.nom', 'Riz 5kg')
            ->assertJsonPath('donnees.meilleurs_produits.0.quantite', '3.000')
            ->assertJsonPath('donnees.meilleurs_produits.0.chiffre_affaires', '1800.00')
            ->assertJsonPath('donnees.modes_paiement.0.mode', 'especes')
            ->assertJsonPath('donnees.modes_paiement.0.montant', '2800.00');
    }

    public function test_today_curve_has_one_bucket_per_hour(): void
    {
        ['store' => $store] = $this->reportingStore();
        $product = Product::factory()->for($store)->create();

        $this->recordSale($store, '2026-10-04 09:10:00', [[$product, 1, 1000]]);
        $this->recordSale($store, '2026-10-04 09:50:00', [[$product, 1, 500]]);

        $response = $this->dashboard($store)->assertOk();

        $response->assertJsonPath('donnees.courbe.granularite', 'heure')
            ->assertJsonCount(24, 'donnees.courbe.points')
            ->assertJsonPath('donnees.courbe.points.9.libelle', '09h')
            ->assertJsonPath('donnees.courbe.points.9.chiffre_affaires', '1500.00')
            ->assertJsonPath('donnees.courbe.points.9.nombre_ventes', 2)
            ->assertJsonPath('donnees.courbe.points.10.chiffre_affaires', '0.00');
    }

    public function test_cancelled_sales_and_other_days_are_excluded(): void
    {
        ['store' => $store] = $this->reportingStore();
        $product = Product::factory()->for($store)->create();

        $this->recordSale($store, '2026-10-04 10:00:00', [[$product, 1, 1000]]);
        $this->recordSale($store, '2026-10-04 11:00:00', [[$product, 1, 9999]], ['statut' => SaleStatus::Cancelled]);
        $this->recordSale($store, '2026-10-03 11:00:00', [[$product, 1, 7777]]);

        $this->dashboard($store)
            ->assertOk()
            ->assertJsonPath('donnees.resume.chiffre_affaires', '1000.00')
            ->assertJsonPath('donnees.resume.nombre_ventes', 1);
    }

    public function test_today_is_compared_with_the_same_hours_of_yesterday(): void
    {
        ['store' => $store] = $this->reportingStore();
        $product = Product::factory()->for($store)->create();

        $this->recordSale($store, '2026-10-04 10:00:00', [[$product, 1, 1500]]);
        $this->recordSale($store, '2026-10-03 10:00:00', [[$product, 1, 1000]]);
        // After 15:30 yesterday — outside the comparable window.
        $this->recordSale($store, '2026-10-03 18:00:00', [[$product, 1, 5000]]);

        $this->dashboard($store)
            ->assertOk()
            ->assertJsonPath('donnees.periode_precedente.chiffre_affaires', '1000.00')
            ->assertJsonPath('donnees.evolution.chiffre_affaires', 50)
            ->assertJsonPath('donnees.evolution.nombre_ventes', 0);
    }

    public function test_evolution_is_null_when_the_previous_period_is_empty(): void
    {
        ['store' => $store] = $this->reportingStore();
        $product = Product::factory()->for($store)->create();

        $this->recordSale($store, '2026-10-04 10:00:00', [[$product, 1, 1500]]);

        $this->dashboard($store)
            ->assertOk()
            ->assertJsonPath('donnees.evolution.chiffre_affaires', null);
    }

    public function test_seven_day_period_has_one_bucket_per_day(): void
    {
        ['store' => $store] = $this->reportingStore();
        $product = Product::factory()->for($store)->create();

        $this->recordSale($store, '2026-09-28 08:00:00', [[$product, 1, 1000]]);
        $this->recordSale($store, '2026-10-04 08:00:00', [[$product, 2, 1000]]);
        // 8 days ago — outside the window.
        $this->recordSale($store, '2026-09-27 08:00:00', [[$product, 1, 9999]]);

        $this->dashboard($store, '7_jours')
            ->assertOk()
            ->assertJsonPath('donnees.courbe.granularite', 'jour')
            ->assertJsonCount(7, 'donnees.courbe.points')
            ->assertJsonPath('donnees.courbe.points.0.cle', '2026-09-28')
            ->assertJsonPath('donnees.courbe.points.0.chiffre_affaires', '1000.00')
            ->assertJsonPath('donnees.courbe.points.6.chiffre_affaires', '2000.00')
            ->assertJsonPath('donnees.resume.chiffre_affaires', '3000.00');
    }

    public function test_days_follow_the_store_timezone(): void
    {
        ['store' => $store] = $this->reportingStore();
        $store->forceFill(['fuseau_horaire' => 'Africa/Lagos'])->save(); // UTC+1
        $product = Product::factory()->for($store)->create();

        // 23:30 UTC on the 3rd is already 00:30 on the 4th in Lagos.
        $this->recordSale($store, '2026-10-03 23:30:00', [[$product, 1, 1000]]);

        $this->dashboard($store)
            ->assertOk()
            ->assertJsonPath('donnees.resume.chiffre_affaires', '1000.00')
            ->assertJsonPath('donnees.courbe.points.0.chiffre_affaires', '1000.00');
    }

    public function test_estimated_profit_ignores_products_without_purchase_price(): void
    {
        ['store' => $store] = $this->reportingStore();
        $known = Product::factory()->for($store)->create(['prix_achat' => 300]);
        $unknown = Product::factory()->for($store)->create(['prix_achat' => null]);

        $this->recordSale($store, '2026-10-04 10:00:00', [[$known, 1, 500], [$unknown, 1, 800]], ['montant_remise' => 50]);

        $this->dashboard($store)
            ->assertOk()
            ->assertJsonPath('donnees.resume.chiffre_affaires', '1250.00')
            ->assertJsonPath('donnees.resume.remises', '50.00')
            // (500 - 300) - 50 discount
            ->assertJsonPath('donnees.resume.benefice_estime', '150.00')
            ->assertJsonPath('donnees.resume.produits_sans_prix_achat', 1);
    }

    public function test_another_stores_sales_are_never_counted(): void
    {
        ['proprietaire' => $owner, 'store' => $store] = $this->reportingStore();
        ['store' => $otherStore] = $this->reportingStore();
        $product = Product::factory()->for($otherStore)->create();
        $this->recordSale($otherStore, '2026-10-04 10:00:00', [[$product, 1, 5000]]);
        Sanctum::actingAs($owner);

        $this->dashboard($store)
            ->assertOk()
            ->assertJsonPath('donnees.resume.chiffre_affaires', '0.00')
            ->assertJsonPath('donnees.resume.nombre_ventes', 0)
            ->assertJsonPath('donnees.meilleurs_produits', []);
    }

    public function test_a_member_of_another_store_cannot_read_the_report(): void
    {
        ['store' => $store] = $this->reportingStore();
        $this->reportingStore(); // acts as the other store's owner now

        // Same "404, not a cross-store leak" contract as every tenant-scoped route.
        $this->dashboard($store)->assertStatus(404);
    }

    public function test_a_cashier_cannot_read_the_report(): void
    {
        ['proprietaire' => $owner, 'store' => $store] = $this->reportingStore();
        $cashier = User::factory()->create();
        $this->postJson("/api/boutiques/{$store->id}/membres", ['email' => $cashier->email, 'role' => 'caissier'])->assertStatus(201);

        Sanctum::actingAs($cashier);

        $this->dashboard($store)->assertStatus(403)->assertJsonPath('code', 'ACCES_INTERDIT');
    }

    public function test_a_manager_can_read_the_report(): void
    {
        ['store' => $store] = $this->reportingStore();
        $manager = User::factory()->create();
        $this->postJson("/api/boutiques/{$store->id}/membres", ['email' => $manager->email, 'role' => 'gerant'])->assertStatus(201);

        Sanctum::actingAs($manager);

        $this->dashboard($store)->assertOk();
    }

    public function test_reports_are_blocked_when_the_feature_is_disabled(): void
    {
        ['store' => $store] = $this->reportingStore(['produits', 'ventes']);

        $this->dashboard($store)->assertStatus(403)->assertJsonPath('code', 'FONCTIONNALITE_DESACTIVEE');
    }

    public function test_an_unknown_period_is_rejected(): void
    {
        ['store' => $store] = $this->reportingStore();

        $this->dashboard($store, 'annee')->assertStatus(422)->assertJsonPath('code', 'VALIDATION_ECHOUEE');
    }
}

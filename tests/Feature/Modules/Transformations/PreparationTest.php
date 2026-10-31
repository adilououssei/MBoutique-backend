<?php

namespace Tests\Feature\Modules\Transformations;

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Features\Models\BusinessDomain;
use App\Modules\Features\Services\FeatureGate;
use App\Modules\Inventory\Models\Stock;
use App\Modules\Tenancy\Models\Store;
use Database\Seeders\FeatureSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesStoresWithFeatures;
use Tests\TestCase;

/** Fiches de préparation et production (merguez, haché…) — docs/modules.md §Transformations. */
class PreparationTest extends TestCase
{
    use CreatesStoresWithFeatures, RefreshDatabase;

    /** @return array{owner: User, store: Store, mutton: Product, fat: Product, merguez: Product} */
    private function butcher(): array
    {
        ['proprietaire' => $owner, 'store' => $store] = $this->createStoreWithFeatures(['produits', 'stock', 'decoupe', 'preparations']);
        Sanctum::actingAs($owner);

        $mutton = Product::factory()->for($store)->create(['nom' => 'Viande de mouton', 'unite' => 'kg', 'prix_achat' => 3000, 'vente_detail_active' => true, 'prix_detail' => 4000]);
        $fat = Product::factory()->for($store)->create(['nom' => 'Gras de bœuf', 'unite' => 'kg', 'prix_achat' => 1000, 'vente_detail_active' => false, 'prix_detail' => null]);
        $merguez = Product::factory()->for($store)->create(['nom' => 'Merguez', 'unite' => 'kg', 'prix_achat' => null, 'vente_detail_active' => true, 'prix_detail' => 5000]);
        foreach ([[$mutton, 20], [$fat, 5]] as [$product, $quantity]) {
            $this->postJson("/api/boutiques/{$store->id}/stocks/{$product->id}/mouvements", ['type' => 'initial', 'quantite' => $quantity])->assertStatus(201);
        }

        return ['owner' => $owner, 'store' => $store, 'mutton' => $mutton, 'fat' => $fat, 'merguez' => $merguez];
    }

    private function sheet(array $p): int
    {
        // Une fournée : 0,8 kg de mouton + 0,2 kg de gras → 1 kg de merguez, 3 jours de conservation.
        return $this->postJson("/api/boutiques/{$p['store']->id}/fiches-preparation", [
            'nom' => 'Merguez maison',
            'produit_id' => $p['merguez']->id,
            'quantite_produite' => 1,
            'duree_conservation_jours' => 3,
            'ingredients' => [
                ['produit_id' => $p['mutton']->id, 'quantite' => 0.8],
                ['produit_id' => $p['fat']->id, 'quantite' => 0.2],
            ],
        ])
            ->assertStatus(201)
            ->assertJsonPath('donnees.produit.nom', 'Merguez')
            ->assertJsonCount(2, 'donnees.ingredients')
            ->json('donnees.id');
    }

    public function test_a_batch_from_a_sheet_consumes_raw_materials_and_stocks_the_finished_product(): void
    {
        $p = $this->butcher();
        $sheetId = $this->sheet($p);

        // 5 fournées : 4 kg de mouton (12 000) + 1 kg de gras (1 000) + 1 500 d'épices = 14 500 pour 5 kg.
        $this->postJson("/api/boutiques/{$p['store']->id}/preparations", [
            'fiche_preparation_id' => $sheetId,
            'entrees' => [
                ['produit_id' => $p['mutton']->id, 'quantite' => 4],
                ['produit_id' => $p['fat']->id, 'quantite' => 1],
            ],
            'sorties' => [['produit_id' => $p['merguez']->id, 'quantite' => 5]],
            'frais_annexes' => 1500,
            'effectuee_le' => '2026-10-30 08:00:00',
        ])
            ->assertStatus(201)
            ->assertJsonPath('donnees.type', 'preparation')
            ->assertJsonPath('donnees.fiche.nom', 'Merguez maison')
            ->assertJsonPath('donnees.cout_matieres', '13000.00')
            ->assertJsonPath('donnees.cout_total', '14500.00')
            ->assertJsonPath('donnees.sorties.0.cout_unitaire', '2900.00')
            ->assertJsonPath('donnees.marge_potentielle', '10500.00')
            ->assertJsonPath('donnees.perte', '0.000')
            // Date limite déduite de la fiche : préparée le 30, à consommer avant le 2 novembre.
            ->assertJsonPath('donnees.consommer_avant', '2026-11-02');

        $this->assertSame(16.0, (float) Stock::where('produit_id', $p['mutton']->id)->value('quantite'));
        $this->assertSame(4.0, (float) Stock::where('produit_id', $p['fat']->id)->value('quantite'));
        $this->assertSame(5.0, (float) Stock::where('produit_id', $p['merguez']->id)->value('quantite'));
        $this->assertSame('2900.00', (string) $p['merguez']->refresh()->prix_achat);
    }

    public function test_sheets_are_managed_by_the_manager_and_read_by_the_staff(): void
    {
        $p = $this->butcher();
        $sheetId = $this->sheet($p);

        $this->putJson("/api/boutiques/{$p['store']->id}/fiches-preparation/{$sheetId}", [
            'quantite_produite' => 2,
            'ingredients' => [['produit_id' => $p['mutton']->id, 'quantite' => 2]],
        ])->assertStatus(200)->assertJsonPath('donnees.quantite_produite', '2.000')->assertJsonCount(1, 'donnees.ingredients');

        // Le produit fini ne peut pas être son propre ingrédient.
        $this->postJson("/api/boutiques/{$p['store']->id}/fiches-preparation", [
            'nom' => 'Boucle', 'produit_id' => $p['merguez']->id, 'quantite_produite' => 1,
            'ingredients' => [['produit_id' => $p['merguez']->id, 'quantite' => 1]],
        ])->assertStatus(422);

        $employee = User::factory()->create();
        $this->postJson("/api/boutiques/{$p['store']->id}/membres", ['email' => $employee->email, 'role' => 'employe'])->assertStatus(201);
        Sanctum::actingAs($employee);
        $this->getJson("/api/boutiques/{$p['store']->id}/fiches-preparation")->assertStatus(200)->assertJsonCount(1, 'donnees');
        $this->putJson("/api/boutiques/{$p['store']->id}/fiches-preparation/{$sheetId}", ['nom' => 'X'])->assertStatus(403);
        $this->deleteJson("/api/boutiques/{$p['store']->id}/fiches-preparation/{$sheetId}")->assertStatus(403);
    }

    public function test_only_butcher_shops_get_cutting_and_preparations_by_default(): void
    {
        $this->seed(FeatureSeeder::class);
        $gate = app(FeatureGate::class);
        $store = fn (string $domain) => Store::factory()->create(['domaine_activite_id' => BusinessDomain::where('slug', $domain)->value('id')]);

        $butcher = $store('boucherie');
        $this->assertTrue($gate->allows($butcher, 'decoupe'));
        $this->assertTrue($gate->allows($butcher, 'preparations'));
        foreach (['alimentation_generale', 'restaurant', 'pharmacie', 'coiffure'] as $domain) {
            $this->assertFalse($gate->allows($store($domain), 'decoupe'), $domain);
            $this->assertFalse($gate->allows($store($domain), 'preparations'), $domain);
        }
    }
}

<?php

namespace Tests\Feature\Modules\Suppliers;

use App\Models\User;
use App\Modules\Suppliers\Models\Supplier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesStoresWithFeatures;
use Tests\TestCase;

class SupplierTest extends TestCase
{
    use CreatesStoresWithFeatures, RefreshDatabase;

    public function test_owner_can_create_list_update_and_delete_a_supplier(): void
    {
        ['proprietaire' => $owner, 'store' => $store] = $this->createStoreWithFeatures(['fournisseurs']);
        Sanctum::actingAs($owner);
        $base = "/api/boutiques/{$store->id}/fournisseurs";

        $id = $this->postJson($base, ['nom' => 'Sodibois SARL', 'telephone' => '70000000', 'nom_contact' => 'M. Diallo'])
            ->assertStatus(201)->assertJsonPath('donnees.nom', 'Sodibois SARL')->json('donnees.id');

        $this->getJson($base)->assertStatus(200)->assertJsonCount(1, 'donnees')->assertJsonPath('donnees.0.solde_du', '0.00');
        $this->getJson("{$base}?recherche=diallo")->assertJsonCount(1, 'donnees');
        $this->putJson("{$base}/{$id}", ['nom' => 'Sodibois'])->assertStatus(200)->assertJsonPath('donnees.nom', 'Sodibois');
        $this->deleteJson("{$base}/{$id}")->assertStatus(200);
        $this->assertSoftDeleted('fournisseurs', ['id' => $id]);
    }

    public function test_name_is_required(): void
    {
        ['proprietaire' => $owner, 'store' => $store] = $this->createStoreWithFeatures(['fournisseurs']);
        Sanctum::actingAs($owner);

        $this->postJson("/api/boutiques/{$store->id}/fournisseurs", [])->assertStatus(422)->assertJsonValidationErrors('nom', 'erreurs');
    }

    public function test_a_cashier_can_view_but_not_manage_suppliers(): void
    {
        ['proprietaire' => $owner, 'store' => $store] = $this->createStoreWithFeatures(['fournisseurs']);
        $cashier = User::factory()->create();
        Sanctum::actingAs($owner);
        $this->postJson("/api/boutiques/{$store->id}/membres", ['email' => $cashier->email, 'role' => 'caissier'])->assertStatus(201);

        Sanctum::actingAs($cashier);
        $this->getJson("/api/boutiques/{$store->id}/fournisseurs")->assertStatus(200);
        $this->postJson("/api/boutiques/{$store->id}/fournisseurs", ['nom' => 'X'])->assertStatus(403);
        $this->getJson("/api/boutiques/{$store->id}/achats")->assertStatus(403);
    }

    public function test_the_feature_must_be_enabled(): void
    {
        ['proprietaire' => $owner, 'store' => $store] = $this->createStoreWithFeatures(['produits']);
        Sanctum::actingAs($owner);

        $this->getJson("/api/boutiques/{$store->id}/fournisseurs")->assertStatus(403)->assertJsonPath('code', 'FONCTIONNALITE_DESACTIVEE');
    }

    public function test_a_supplier_of_another_store_is_not_reachable(): void
    {
        ['proprietaire' => $owner, 'store' => $store] = $this->createStoreWithFeatures(['fournisseurs']);
        ['store' => $other] = $this->createStoreWithFeatures(['fournisseurs']);
        $foreign = Supplier::factory()->for($other)->create();
        Sanctum::actingAs($owner);

        $this->getJson("/api/boutiques/{$store->id}/fournisseurs/{$foreign->id}")->assertStatus(404);
    }
}

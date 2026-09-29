<?php

namespace Tests\Feature\Modules\Sales;

use App\Models\User;
use App\Modules\CashRegister\Models\CashRegister;
use App\Modules\Catalog\Models\Product;
use App\Modules\Sales\Models\Sale;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesStoresWithFeatures;
use Tests\TestCase;

class SaleTest extends TestCase
{
    use CreatesStoresWithFeatures, RefreshDatabase;

    private function checkoutOne(mixed $store, CashRegister $register, Product $product): int
    {
        return $this->postJson("/api/boutiques/{$store->id}/ventes/encaisser", [
            'lignes' => [['produit_id' => $product->id, 'mode_prix' => 'detail', 'quantite' => 1]],
            'caisse_id' => $register->id,
        ])->json('donnees.id');
    }

    public function test_owner_can_list_and_read_a_sale(): void
    {
        ['proprietaire' => $owner, 'store' => $store] = $this->createStoreWithFeatures(['produits', 'stock', 'caisse', 'ventes']);
        Sanctum::actingAs($owner);
        $register = CashRegister::factory()->for($store)->create();
        $this->postJson("/api/boutiques/{$store->id}/caisses/{$register->id}/sessions", ['montant_ouverture' => 0]);
        $product = Product::factory()->for($store)->create(['vente_detail_active' => true, 'prix_detail' => 600]);
        $this->postJson("/api/boutiques/{$store->id}/stocks/{$product->id}/mouvements", ['type' => 'initial', 'quantite' => 10]);
        $saleId = $this->checkoutOne($store, $register, $product);

        $this->getJson("/api/boutiques/{$store->id}/ventes")->assertStatus(200)->assertJsonCount(1, 'donnees');
        $this->getJson("/api/boutiques/{$store->id}/ventes/{$saleId}")->assertStatus(200)->assertJsonPath('donnees.id', $saleId);
    }

    public function test_sales_list_is_paginated(): void
    {
        ['proprietaire' => $owner, 'store' => $store] = $this->createStoreWithFeatures(['ventes']);
        Sale::factory()->for($store)->count(15)->create();
        Sanctum::actingAs($owner);

        $response = $this->getJson("/api/boutiques/{$store->id}/ventes?par_page=10");

        $response->assertStatus(200)
            ->assertJsonCount(10, 'donnees')
            ->assertJsonPath('succes', true)
            ->assertJsonPath('meta.total', 15)
            ->assertJsonPath('meta.page_courante', 1)
            ->assertJsonPath('meta.derniere_page', 2)
            ->assertJsonPath('meta.par_page', 10)
            ->assertJsonStructure(['liens' => ['premier', 'dernier', 'precedent', 'suivant']])
            ->assertJsonMissingPath('data')
            ->assertJsonMissingPath('meta.current_page');
    }

    public function test_status_filter_narrows_the_list(): void
    {
        ['proprietaire' => $owner, 'store' => $store] = $this->createStoreWithFeatures(['ventes']);
        Sale::factory()->for($store)->create(['statut' => 'terminee']);
        Sale::factory()->for($store)->create(['statut' => 'annulee']);
        Sanctum::actingAs($owner);

        $this->getJson("/api/boutiques/{$store->id}/ventes?statut=annulee")
            ->assertStatus(200)->assertJsonCount(1, 'donnees');
    }

    // --- Isolation ---

    public function test_a_member_of_another_store_cannot_read_this_sale(): void
    {
        ['store' => $storeA] = $this->createStoreWithFeatures(['ventes']);
        $saleA = Sale::factory()->for($storeA)->create();

        ['proprietaire' => $ownerB, 'store' => $storeB] = $this->createStoreWithFeatures(['ventes']);
        Sanctum::actingAs($ownerB);

        $this->getJson("/api/boutiques/{$storeB->id}/ventes/{$saleA->id}")
            ->assertStatus(404)
            ->assertJsonPath('succes', false)
            ->assertJsonPath('code', 'INTROUVABLE');
    }

    // --- Permissions ---

    public function test_an_employee_can_view_sales_but_not_checkout(): void
    {
        ['proprietaire' => $owner, 'store' => $store] = $this->createStoreWithFeatures(['produits', 'stock', 'caisse', 'ventes']);
        $employee = User::factory()->create();
        Sanctum::actingAs($owner);
        $this->postJson("/api/boutiques/{$store->id}/membres", ['email' => $employee->email, 'role' => 'employe'])->assertStatus(201);
        $register = CashRegister::factory()->for($store)->create();
        $this->postJson("/api/boutiques/{$store->id}/caisses/{$register->id}/sessions", ['montant_ouverture' => 0]);
        $product = Product::factory()->for($store)->create(['vente_detail_active' => true, 'prix_detail' => 600]);
        $this->postJson("/api/boutiques/{$store->id}/stocks/{$product->id}/mouvements", ['type' => 'initial', 'quantite' => 10]);

        Sanctum::actingAs($employee);

        $this->getJson("/api/boutiques/{$store->id}/ventes")->assertStatus(200);
        $this->postJson("/api/boutiques/{$store->id}/ventes/encaisser", [
            'lignes' => [['produit_id' => $product->id, 'mode_prix' => 'detail', 'quantite' => 1]],
            'caisse_id' => $register->id,
        ])->assertStatus(403)->assertJsonPath('code', 'ACCES_INTERDIT');
    }

    public function test_a_cashier_can_checkout(): void
    {
        ['proprietaire' => $owner, 'store' => $store] = $this->createStoreWithFeatures(['produits', 'stock', 'caisse', 'ventes']);
        $cashier = User::factory()->create();
        Sanctum::actingAs($owner);
        $this->postJson("/api/boutiques/{$store->id}/membres", ['email' => $cashier->email, 'role' => 'caissier'])->assertStatus(201);
        $register = CashRegister::factory()->for($store)->create();
        $this->postJson("/api/boutiques/{$store->id}/caisses/{$register->id}/sessions", ['montant_ouverture' => 0]);
        $product = Product::factory()->for($store)->create(['vente_detail_active' => true, 'prix_detail' => 600]);
        $this->postJson("/api/boutiques/{$store->id}/stocks/{$product->id}/mouvements", ['type' => 'initial', 'quantite' => 10]);

        Sanctum::actingAs($cashier);

        $this->postJson("/api/boutiques/{$store->id}/ventes/encaisser", [
            'lignes' => [['produit_id' => $product->id, 'mode_prix' => 'detail', 'quantite' => 1]],
            'caisse_id' => $register->id,
        ])->assertStatus(201);
    }

    // --- FeatureGate ---

    public function test_sales_is_blocked_when_the_domain_never_enabled_the_feature(): void
    {
        ['proprietaire' => $owner, 'store' => $store] = $this->createStoreWithFeatures(['produits']); // sales NOT enabled
        Sanctum::actingAs($owner);

        $this->getJson("/api/boutiques/{$store->id}/ventes")
            ->assertStatus(403)->assertJsonPath('code', 'FONCTIONNALITE_DESACTIVEE');
    }
}

<?php

namespace Tests\Feature\Modules\Notifications;

use App\Models\User;
use App\Modules\CashRegister\Models\CashRegister;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\Service;
use App\Modules\Employees\Models\Employee;
use App\Modules\Tenancy\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesStoresWithFeatures;
use Tests\TestCase;

/** Centre de notifications et alertes automatiques — docs/modules.md §Notifications. */
class NotificationTest extends TestCase
{
    use CreatesStoresWithFeatures, RefreshDatabase;

    /** @return array{owner: User, manager: User, cashier: User, waiter: User, store: Store, register: CashRegister} */
    private function team(array $features): array
    {
        ['proprietaire' => $owner, 'store' => $store] = $this->createStoreWithFeatures($features);
        Sanctum::actingAs($owner);
        $members = [];
        foreach (['manager' => 'gerant', 'cashier' => 'caissier', 'waiter' => 'employe'] as $key => $role) {
            $members[$key] = User::factory()->create();
            $this->postJson("/api/boutiques/{$store->id}/membres", ['email' => $members[$key]->email, 'role' => $role])->assertStatus(201);
        }
        $register = CashRegister::factory()->for($store)->create();
        if (in_array('caisse', $features, true)) {
            $this->postJson("/api/boutiques/{$store->id}/caisses/{$register->id}/sessions", ['montant_ouverture' => 0])->assertStatus(201);
        }

        return ['owner' => $owner, ...$members, 'store' => $store, 'register' => $register];
    }

    private function sell(Store $store, CashRegister $register, Product $product, float $qty)
    {
        return $this->postJson("/api/boutiques/{$store->id}/ventes/encaisser", ['caisse_id' => $register->id, 'lignes' => [['produit_id' => $product->id, 'mode_prix' => 'detail', 'quantite' => $qty]]]);
    }

    private function inbox(User $user, Store $store, string $query = '')
    {
        Sanctum::actingAs($user);

        return $this->getJson("/api/boutiques/{$store->id}/notifications{$query}");
    }

    public function test_low_stock_alerts_once_at_the_threshold_then_on_out_of_stock(): void
    {
        ['owner' => $owner, 'manager' => $manager, 'cashier' => $cashier, 'store' => $store, 'register' => $register] = $this->team(['produits', 'stock', 'ventes', 'caisse']);
        $product = Product::factory()->for($store)->create(['nom' => 'Huile 1L', 'vente_detail_active' => true, 'prix_detail' => 1000]);
        $this->postJson("/api/boutiques/{$store->id}/stocks/{$product->id}/mouvements", ['type' => 'initial', 'quantite' => 10, 'quantite_minimum' => 5])->assertStatus(201);

        $this->sell($store, $register, $product, 4)->assertStatus(201); // 6 : au-dessus du seuil
        $this->assertSame(0, $owner->notifications()->count());

        $this->sell($store, $register, $product, 1)->assertStatus(201); // 5 : franchit le seuil
        $this->sell($store, $register, $product, 1)->assertStatus(201); // 4 : déjà sous le seuil, pas de nouvelle alerte

        $this->inbox($manager, $store)->assertJsonCount(1, 'donnees')
            ->assertJsonPath('donnees.0.titre', 'Stock faible')
            ->assertJsonPath('donnees.0.categorie', 'stock')
            ->assertJsonPath('donnees.0.lien.ecran', 'stock')
            ->assertJsonPath('donnees.0.lien.id', $product->id);
        $this->assertSame(0, $cashier->notifications()->count()); // le caissier ne gère pas le stock

        Sanctum::actingAs($owner);
        $this->sell($store, $register, $product, 4)->assertStatus(201); // 0 : rupture
        $titles = $this->inbox($owner, $store)->assertJsonCount(2, 'donnees')->json('donnees.*.titre');
        $this->assertEqualsCanonicalizing(['Stock faible', 'Rupture de stock'], $titles);
    }

    public function test_nothing_is_notified_when_the_operation_is_rolled_back(): void
    {
        ['owner' => $owner, 'store' => $store, 'register' => $register] = $this->team(['produits', 'stock', 'ventes', 'caisse']);
        $a = Product::factory()->for($store)->create(['vente_detail_active' => true, 'prix_detail' => 100]);
        $b = Product::factory()->for($store)->create(['vente_detail_active' => true, 'prix_detail' => 100]);
        $this->postJson("/api/boutiques/{$store->id}/stocks/{$a->id}/mouvements", ['type' => 'initial', 'quantite' => 3, 'quantite_minimum' => 2])->assertStatus(201);
        $this->postJson("/api/boutiques/{$store->id}/stocks/{$b->id}/mouvements", ['type' => 'initial', 'quantite' => 1])->assertStatus(201);

        // A passerait sous son seuil, mais B manque : la vente entière est annulée.
        $this->postJson("/api/boutiques/{$store->id}/ventes/encaisser", ['caisse_id' => $register->id, 'lignes' => [
            ['produit_id' => $a->id, 'mode_prix' => 'detail', 'quantite' => 2],
            ['produit_id' => $b->id, 'mode_prix' => 'detail', 'quantite' => 5],
        ]])->assertStatus(422);

        $this->assertSame(0, $owner->notifications()->count());
    }

    public function test_an_order_marked_ready_notifies_the_team_but_not_the_author(): void
    {
        ['owner' => $owner, 'waiter' => $waiter, 'cashier' => $cashier, 'store' => $store] = $this->team(['produits', 'commandes', 'ventes', 'caisse']);
        $dish = Product::factory()->for($store)->create(['vente_detail_active' => true, 'prix_detail' => 2000]);
        $id = $this->postJson("/api/boutiques/{$store->id}/commandes", ['type' => 'a_emporter', 'nom_client' => 'Kofi', 'lignes' => [['produit_id' => $dish->id, 'quantite' => 1]]])->json('donnees.id');

        $this->postJson("/api/boutiques/{$store->id}/commandes/{$id}/statut", ['statut' => 'en_preparation'])->assertStatus(200);
        $this->assertSame(0, $waiter->notifications()->count()); // seule l'étape « prête » notifie

        $this->postJson("/api/boutiques/{$store->id}/commandes/{$id}/statut", ['statut' => 'prete'])->assertStatus(200);

        $this->inbox($waiter, $store)->assertJsonCount(1, 'donnees')->assertJsonPath('donnees.0.titre', 'Commande prête')->assertJsonPath('donnees.0.lien.ecran', 'commande');
        $this->assertSame(1, $cashier->notifications()->count());
        $this->assertSame(0, $owner->notifications()->count());
    }

    public function test_a_booking_notifies_the_employee_account_and_reminders_are_sent_once(): void
    {
        ['owner' => $owner, 'waiter' => $stylist, 'manager' => $manager, 'store' => $store] = $this->team(['services', 'employes', 'rendez_vous', 'clients']);
        $service = Service::factory()->for($store)->create(['nom' => 'Tresses', 'duree_minutes' => 60]);
        $withAccount = $this->postJson("/api/boutiques/{$store->id}/employes", ['nom' => 'Awa', 'utilisateur_id' => $stylist->id])->assertStatus(201)->json('donnees.id');
        $withoutAccount = Employee::query()->withoutGlobalScopes()->findOrFail(
            $this->postJson("/api/boutiques/{$store->id}/employes", ['nom' => 'Ali'])->json('donnees.id')
        );

        $soon = now()->addMinutes(30)->startOfMinute()->toIso8601String();
        $this->postJson("/api/boutiques/{$store->id}/rendez-vous", ['service_id' => $service->id, 'employe_id' => $withAccount, 'nom_client' => 'Fatou', 'debut_le' => $soon])->assertStatus(201);
        $this->postJson("/api/boutiques/{$store->id}/rendez-vous", ['service_id' => $service->id, 'employe_id' => $withoutAccount->id, 'nom_client' => 'Moussa', 'debut_le' => $soon])->assertStatus(201);

        $this->inbox($stylist, $store)->assertJsonCount(1, 'donnees')->assertJsonPath('donnees.0.titre', 'Nouveau rendez-vous');

        $this->artisan('notifications:rappels-rendez-vous')->assertSuccessful();
        $this->artisan('notifications:rappels-rendez-vous')->assertSuccessful(); // idempotent

        $this->assertSame(1, $stylist->notifications()->where('data->categorie', 'rappel_rendez_vous')->count());
        // Ali n'a pas de compte : l'accueil (rendez_vous.modifier) reçoit le rappel.
        $this->assertSame(1, $manager->notifications()->where('data->categorie', 'rappel_rendez_vous')->count());
        $this->assertSame(1, $owner->notifications()->where('data->categorie', 'rappel_rendez_vous')->count());
    }

    public function test_the_inbox_counts_marks_read_and_stays_private_to_its_owner_and_store(): void
    {
        ['owner' => $owner, 'manager' => $manager, 'store' => $store, 'register' => $register] = $this->team(['produits', 'stock', 'ventes', 'caisse']);
        $product = Product::factory()->for($store)->create(['vente_detail_active' => true, 'prix_detail' => 100]);
        $this->postJson("/api/boutiques/{$store->id}/stocks/{$product->id}/mouvements", ['type' => 'initial', 'quantite' => 2, 'quantite_minimum' => 1])->assertStatus(201);
        $this->sell($store, $register, $product, 1)->assertStatus(201);
        $this->sell($store, $register, $product, 1)->assertStatus(201);

        Sanctum::actingAs($owner);
        $base = "/api/boutiques/{$store->id}/notifications";
        $this->getJson("{$base}/compteur")->assertJsonPath('donnees.non_lues', 2);
        $first = $this->getJson($base)->json('donnees.0.id');
        $this->postJson("{$base}/{$first}/lire")->assertStatus(200)->assertJsonPath('donnees.lue', true);
        $this->getJson("{$base}/compteur")->assertJsonPath('donnees.non_lues', 1);
        $this->getJson("{$base}?non_lues=1")->assertJsonCount(1, 'donnees');
        $this->postJson("{$base}/tout-lire")->assertStatus(200);
        $this->getJson("{$base}/compteur")->assertJsonPath('donnees.non_lues', 0);

        // La notification du propriétaire n'existe pas pour le gérant…
        Sanctum::actingAs($manager);
        $this->postJson("{$base}/{$first}/lire")->assertStatus(404);
        // …et rien ne fuit vers une autre boutique du même propriétaire.
        ['proprietaire' => $other, 'store' => $otherStore] = $this->createStoreWithFeatures(['produits']);
        $this->getJson("/api/boutiques/{$otherStore->id}/notifications/compteur")->assertJsonPath('donnees.non_lues', 0);
    }
}

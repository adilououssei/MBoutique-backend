<?php

namespace Tests\Feature\Modules\Appointments;

use App\Models\User;
use App\Modules\CashRegister\Models\CashRegister;
use App\Modules\Catalog\Models\Service;
use App\Modules\Customers\Models\Customer;
use App\Modules\Employees\Models\Employee;
use App\Modules\Features\Models\Feature;
use App\Modules\Features\Models\FeatureDependency;
use App\Modules\Tenancy\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreatesStoresWithFeatures;
use Tests\TestCase;

/** Rendez-vous : créneaux, chevauchements, cycle de vie — docs/modules.md §Appointments. */
class AppointmentTest extends TestCase
{
    use CreatesStoresWithFeatures, RefreshDatabase;

    private const FEATURES = ['services', 'employes', 'rendez_vous', 'clients', 'ventes', 'caisse'];

    /** @return array{owner: User, store: Store, service: Service, awa: Employee, ali: Employee} */
    private function salon(): array
    {
        ['proprietaire' => $owner, 'store' => $store] = $this->createStoreWithFeatures(self::FEATURES);
        Sanctum::actingAs($owner);

        return [
            'owner' => $owner,
            'store' => $store,
            'service' => Service::factory()->for($store)->create(['nom' => 'Tresses', 'prix' => 5000, 'duree_minutes' => 90]),
            'awa' => Employee::factory()->for($store)->create(['nom' => 'Awa']),
            'ali' => Employee::factory()->for($store)->create(['nom' => 'Ali']),
        ];
    }

    private function book(Store $store, array $payload)
    {
        return $this->postJson("/api/boutiques/{$store->id}/rendez-vous", $payload);
    }

    public function test_booking_derives_the_end_from_the_service_duration(): void
    {
        ['store' => $store, 'service' => $service, 'awa' => $awa] = $this->salon();

        $this->book($store, ['service_id' => $service->id, 'employe_id' => $awa->id, 'nom_client' => 'Fatou', 'telephone_client' => '70000000', 'debut_le' => '2026-10-10T09:00:00Z'])
            ->assertStatus(201)
            ->assertJsonPath('donnees.statut', 'prevu')
            ->assertJsonPath('donnees.nom_client', 'Fatou')
            ->assertJsonPath('donnees.employe.nom', 'Awa')
            ->assertJsonPath('donnees.service.nom', 'Tresses')
            ->assertJsonPath('donnees.debut_le', '2026-10-10T09:00:00.000000Z')
            ->assertJsonPath('donnees.fin_le', '2026-10-10T10:30:00.000000Z');
    }

    public function test_a_service_without_duration_defaults_to_thirty_minutes_and_duration_can_be_forced(): void
    {
        ['store' => $store, 'awa' => $awa] = $this->salon();
        $quick = Service::factory()->for($store)->create(['duree_minutes' => null]);

        $this->book($store, ['service_id' => $quick->id, 'employe_id' => $awa->id, 'nom_client' => 'A', 'debut_le' => '2026-10-10T09:00:00Z'])
            ->assertJsonPath('donnees.fin_le', '2026-10-10T09:30:00.000000Z');
        $this->book($store, ['service_id' => $quick->id, 'employe_id' => $awa->id, 'nom_client' => 'B', 'debut_le' => '2026-10-10T10:00:00Z', 'duree_minutes' => 120])
            ->assertJsonPath('donnees.fin_le', '2026-10-10T12:00:00.000000Z');
    }

    public function test_an_employee_cannot_be_double_booked_but_adjacent_slots_and_other_employees_are_fine(): void
    {
        ['store' => $store, 'service' => $service, 'awa' => $awa, 'ali' => $ali] = $this->salon();
        $this->book($store, ['service_id' => $service->id, 'employe_id' => $awa->id, 'nom_client' => 'A', 'debut_le' => '2026-10-10T09:00:00Z'])->assertStatus(201);

        // 10:00 tombe dans 09:00–10:30 → refusé.
        $this->book($store, ['service_id' => $service->id, 'employe_id' => $awa->id, 'nom_client' => 'B', 'debut_le' => '2026-10-10T10:00:00Z'])
            ->assertStatus(422)->assertJsonPath('code', 'CRENEAU_INDISPONIBLE');
        // Commence pile à la fin → accepté.
        $this->book($store, ['service_id' => $service->id, 'employe_id' => $awa->id, 'nom_client' => 'C', 'debut_le' => '2026-10-10T10:30:00Z'])->assertStatus(201);
        // Autre employée, même heure → accepté. Sans employé → jamais bloqué.
        $this->book($store, ['service_id' => $service->id, 'employe_id' => $ali->id, 'nom_client' => 'D', 'debut_le' => '2026-10-10T10:00:00Z'])->assertStatus(201);
        $this->book($store, ['service_id' => $service->id, 'nom_client' => 'E', 'debut_le' => '2026-10-10T10:00:00Z'])->assertStatus(201)->assertJsonPath('donnees.employe', null);
    }

    public function test_a_cancelled_appointment_frees_its_slot(): void
    {
        ['store' => $store, 'service' => $service, 'awa' => $awa] = $this->salon();
        $id = $this->book($store, ['service_id' => $service->id, 'employe_id' => $awa->id, 'nom_client' => 'A', 'debut_le' => '2026-10-10T09:00:00Z'])->json('donnees.id');

        $this->postJson("/api/boutiques/{$store->id}/rendez-vous/{$id}/annuler", ['motif' => 'Cliente malade'])
            ->assertStatus(200)->assertJsonPath('donnees.statut', 'annule')->assertJsonPath('donnees.motif_annulation', 'Cliente malade');

        $this->book($store, ['service_id' => $service->id, 'employe_id' => $awa->id, 'nom_client' => 'B', 'debut_le' => '2026-10-10T09:00:00Z'])->assertStatus(201);
    }

    public function test_a_registered_client_or_at_least_a_name_is_required(): void
    {
        ['store' => $store, 'service' => $service] = $this->salon();
        $client = Customer::factory()->for($store)->create(['nom' => 'Mariam', 'telephone' => '71111111']);

        $this->book($store, ['service_id' => $service->id, 'debut_le' => '2026-10-10T09:00:00Z'])
            ->assertStatus(422)->assertJsonValidationErrors('nom_client', 'erreurs');
        $this->book($store, ['service_id' => $service->id, 'client_id' => $client->id, 'debut_le' => '2026-10-10T09:00:00Z'])
            ->assertStatus(201)->assertJsonPath('donnees.nom_client', 'Mariam')->assertJsonPath('donnees.telephone_client', '71111111');
    }

    public function test_an_inactive_employee_cannot_be_booked(): void
    {
        ['store' => $store, 'service' => $service] = $this->salon();
        $gone = Employee::factory()->for($store)->create(['actif' => false]);

        $this->book($store, ['service_id' => $service->id, 'employe_id' => $gone->id, 'nom_client' => 'A', 'debut_le' => '2026-10-10T09:00:00Z'])
            ->assertStatus(422)->assertJsonValidationErrors('employe_id', 'erreurs');
    }

    public function test_rescheduling_keeps_the_duration_and_respects_other_bookings(): void
    {
        ['store' => $store, 'service' => $service, 'awa' => $awa] = $this->salon();
        $first = $this->book($store, ['service_id' => $service->id, 'employe_id' => $awa->id, 'nom_client' => 'A', 'debut_le' => '2026-10-10T09:00:00Z', 'duree_minutes' => 60])->json('donnees.id');
        $this->book($store, ['service_id' => $service->id, 'employe_id' => $awa->id, 'nom_client' => 'B', 'debut_le' => '2026-10-10T14:00:00Z'])->assertStatus(201);
        $url = "/api/boutiques/{$store->id}/rendez-vous/{$first}";

        // Décalé de 30 min, chevauche son ancien créneau : il s'ignore lui-même, durée conservée (60 min).
        $this->putJson($url, ['debut_le' => '2026-10-10T09:30:00Z'])
            ->assertStatus(200)->assertJsonPath('donnees.fin_le', '2026-10-10T10:30:00.000000Z');
        // Sur le rendez-vous de B → refusé.
        $this->putJson($url, ['debut_le' => '2026-10-10T14:30:00Z'])->assertStatus(422)->assertJsonPath('code', 'CRENEAU_INDISPONIBLE');
    }

    public function test_lifecycle_confirm_then_done_with_the_sale_then_frozen(): void
    {
        ['store' => $store, 'service' => $service, 'awa' => $awa] = $this->salon();
        $id = $this->book($store, ['service_id' => $service->id, 'employe_id' => $awa->id, 'nom_client' => 'A', 'debut_le' => '2026-10-10T09:00:00Z'])->json('donnees.id');
        $url = "/api/boutiques/{$store->id}/rendez-vous/{$id}";

        $register = CashRegister::factory()->for($store)->create();
        $this->postJson("/api/boutiques/{$store->id}/caisses/{$register->id}/sessions", ['montant_ouverture' => 0])->assertStatus(201);
        $saleId = $this->postJson("/api/boutiques/{$store->id}/ventes/encaisser", ['caisse_id' => $register->id, 'lignes' => [['service_id' => $service->id, 'quantite' => 1]]])
            ->assertStatus(201)->json('donnees.id');

        $this->postJson("{$url}/statut", ['statut' => 'confirme'])->assertStatus(200)->assertJsonPath('donnees.statut', 'confirme');
        $this->postJson("{$url}/statut", ['statut' => 'termine', 'vente_id' => $saleId])
            ->assertStatus(200)->assertJsonPath('donnees.statut', 'termine')->assertJsonPath('donnees.vente_id', $saleId);

        $this->putJson($url, ['debut_le' => '2026-10-11T09:00:00Z'])->assertStatus(422)->assertJsonPath('code', 'RENDEZ_VOUS_CLOS');
        $this->postJson("{$url}/annuler")->assertStatus(422)->assertJsonPath('code', 'RENDEZ_VOUS_CLOS');
    }

    public function test_agenda_lists_a_day_ordered_and_filtered_by_employee(): void
    {
        ['store' => $store, 'service' => $service, 'awa' => $awa, 'ali' => $ali] = $this->salon();
        $this->book($store, ['service_id' => $service->id, 'employe_id' => $awa->id, 'nom_client' => 'Tard', 'debut_le' => '2026-10-10T15:00:00Z']);
        $this->book($store, ['service_id' => $service->id, 'employe_id' => $ali->id, 'nom_client' => 'Tôt', 'debut_le' => '2026-10-10T08:00:00Z']);
        $this->book($store, ['service_id' => $service->id, 'employe_id' => $awa->id, 'nom_client' => 'Lendemain', 'debut_le' => '2026-10-11T08:00:00Z']);
        $base = "/api/boutiques/{$store->id}/rendez-vous?du=2026-10-10T00:00:00Z&au=2026-10-11T00:00:00Z";

        $this->getJson($base)->assertJsonCount(2, 'donnees')->assertJsonPath('donnees.0.nom_client', 'Tôt');
        $this->getJson("{$base}&employe_id={$awa->id}")->assertJsonCount(1, 'donnees')->assertJsonPath('donnees.0.nom_client', 'Tard');
    }

    public function test_employees_view_the_agenda_and_cashiers_manage_it(): void
    {
        ['store' => $store, 'service' => $service] = $this->salon();
        $employee = User::factory()->create();
        $cashier = User::factory()->create();
        $this->postJson("/api/boutiques/{$store->id}/membres", ['email' => $employee->email, 'role' => 'employe'])->assertStatus(201);
        $this->postJson("/api/boutiques/{$store->id}/membres", ['email' => $cashier->email, 'role' => 'caissier'])->assertStatus(201);
        $payload = ['service_id' => $service->id, 'nom_client' => 'A', 'debut_le' => '2026-10-10T09:00:00Z'];

        Sanctum::actingAs($employee);
        $this->getJson("/api/boutiques/{$store->id}/rendez-vous")->assertStatus(200);
        $this->book($store, $payload)->assertStatus(403);

        Sanctum::actingAs($cashier);
        $id = $this->book($store, $payload)->assertStatus(201)->json('donnees.id');
        $this->postJson("/api/boutiques/{$store->id}/rendez-vous/{$id}/annuler")->assertStatus(200);
    }

    public function test_appointments_need_their_dependencies_and_stay_inside_the_store(): void
    {
        // rendez_vous dépend de services ET employes (FeatureDependency, seedée en
        // production par FeatureSeeder — recréée ici pour le test).
        ['store' => $incomplete] = $this->createStoreWithFeatures(['services', 'rendez_vous']);
        FeatureDependency::firstOrCreate([
            'fonctionnalite_id' => Feature::where('slug', 'rendez_vous')->value('id'),
            'depend_de_fonctionnalite_id' => Feature::firstOrCreate(['slug' => 'employes'], ['nom' => 'Employés'])->id,
        ]);
        $this->getJson("/api/boutiques/{$incomplete->id}/rendez-vous")->assertStatus(403)->assertJsonPath('code', 'FONCTIONNALITE_DESACTIVEE');

        ['owner' => $owner, 'store' => $store, 'service' => $service] = $this->salon();
        ['store' => $other] = $this->createStoreWithFeatures(self::FEATURES);
        $foreignEmployee = Employee::factory()->for($other)->create();
        Sanctum::actingAs($owner);

        $this->book($store, ['service_id' => $service->id, 'employe_id' => $foreignEmployee->id, 'nom_client' => 'A', 'debut_le' => '2026-10-10T09:00:00Z'])
            ->assertStatus(422)->assertJsonValidationErrors('employe_id', 'erreurs');
    }
}

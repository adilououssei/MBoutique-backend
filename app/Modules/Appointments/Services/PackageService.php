<?php

namespace App\Modules\Appointments\Services;

use App\Modules\Appointments\Exceptions\SalonException;
use App\Modules\Appointments\Models\Appointment;
use App\Modules\Appointments\Models\CustomerPackage;
use App\Modules\Appointments\Models\Package;
use App\Modules\Sales\Models\Sale;
use Illuminate\Support\Facades\DB;

/**
 * Forfaits : définition, vente (une ligne de vente `forfait_id` crée le
 * forfait du client), séance décomptée uniquement quand le rendez-vous est
 * terminé, annulation avec la vente.
 */
class PackageService
{
    /** @param  array{nom: string, description?: string|null, prix: string|float|int, nombre_seances: int, validite_jours?: int|null, actif?: bool, services: array<int, int>}  $data */
    public function save(?Package $package, array $data): Package
    {
        return DB::transaction(function () use ($package, $data) {
            $attributes = collect($data)->except('services')->all();
            $package = $package === null ? Package::create($attributes) : tap($package)->update($attributes);
            if (array_key_exists('services', $data)) {
                $package->services()->sync(collect($data['services'])->unique()->mapWithKeys(fn ($id) => [$id => ['boutique_id' => $package->boutique_id]])->all());
            }

            return $package->load('services');
        });
    }

    /** Ligne de vente d'un forfait : le client repart avec ses séances. */
    public function sell(Sale $sale, Package $package, ?int $customerId, int $quantity): void
    {
        if ($customerId === null) {
            throw new SalonException('La vente d’un forfait doit avoir un client : choisissez-le.', 'CLIENT_REQUIS');
        }
        for ($i = 0; $i < $quantity; $i++) {
            CustomerPackage::create([
                'forfait_id' => $package->id,
                'client_id' => $customerId,
                'vente_id' => $sale->id,
                'prix' => $package->prix,
                'seances_total' => $package->nombre_seances,
                'achete_le' => now(),
                'expire_le' => $package->validite_jours ? today()->addDays($package->validite_jours) : null,
            ]);
        }
    }

    /** Le forfait peut-il couvrir ce rendez-vous (client, validité, séances, prestations incluses) ? */
    public function assertUsableFor(CustomerPackage $customerPackage, ?int $customerId, array $serviceIds): void
    {
        if ($customerId === null || $customerPackage->client_id !== $customerId) {
            throw new SalonException('Ce forfait appartient à un autre client.', 'FORFAIT_INVALIDE');
        }
        if (! $customerPackage->isUsable()) {
            $status = ['expire' => 'expiré', 'epuise' => 'épuisé', 'annule' => 'annulé'][$customerPackage->displayStatus()] ?? 'inutilisable';
            throw new SalonException("Ce forfait est {$status}.", 'FORFAIT_INVALIDE');
        }
        $included = $customerPackage->package->services()->pluck('services.id');
        if ($included->intersect($serviceIds)->isEmpty()) {
            throw new SalonException('Aucune prestation de ce rendez-vous n’est incluse dans le forfait.', 'FORFAIT_INVALIDE');
        }
    }

    /** @return array<int, int> prestations du rendez-vous couvertes par son forfait */
    public function coveredServices(Appointment $appointment): array
    {
        $customerPackage = $appointment->customerPackage;
        if ($customerPackage === null || (! $customerPackage->isUsable() && ! $appointment->seance_decomptee)) {
            return [];
        }

        return $customerPackage->package->services()->pluck('services.id')->all();
    }

    /** Rendez-vous terminé : une séance décomptée, une seule fois. */
    public function useSession(Appointment $appointment, ?int $userId): void
    {
        if ($appointment->forfait_client_id === null || $appointment->seance_decomptee) {
            return;
        }
        $customerPackage = CustomerPackage::query()->lockForUpdate()->findOrFail($appointment->forfait_client_id);
        if (! $customerPackage->isUsable()) {
            throw new SalonException('Le forfait du client n’a plus de séance utilisable : retirez-le du rendez-vous pour l’encaisser normalement.', 'FORFAIT_INVALIDE');
        }
        $used = $customerPackage->seances_utilisees + 1;
        $customerPackage->update(['seances_utilisees' => $used, 'statut' => $used >= $customerPackage->seances_total ? 'epuise' : 'actif']);
        $customerPackage->sessions()->create(['rendez_vous_id' => $appointment->id, 'utilisee_le' => now(), 'cree_par_id' => $userId]);
        $appointment->update(['seance_decomptee' => true]);
    }

    /** Vente annulée : les forfaits qu'elle a vendus sont annulés (séances déjà faites conservées en historique). */
    public function cancelForSale(Sale $sale): void
    {
        CustomerPackage::query()->where('vente_id', $sale->id)->update(['statut' => 'annule']);
    }
}

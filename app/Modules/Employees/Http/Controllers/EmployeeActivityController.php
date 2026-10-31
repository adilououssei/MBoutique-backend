<?php

namespace App\Modules\Employees\Http\Controllers;

use App\Modules\Catalog\Http\Requests\UploadProductImageRequest;
use App\Modules\Employees\Http\Resources\EmployeeResource;
use App\Modules\Employees\Models\Employee;
use App\Modules\Sales\Enums\SaleStatus;
use App\Modules\Sales\Support\Money;
use App\Modules\Tenancy\Models\Store;
use App\Shared\Http\Controllers\ApiController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Activité d'un employé (coiffeur) : prestations encaissées qu'il a
 * réalisées, chiffre d'affaires généré, rendez-vous, commissions ; photo.
 */
class EmployeeActivityController extends ApiController
{
    /** GET employes/{employee}/activite?du=&au= (défaut : mois en cours). */
    public function show(Store $store, Employee $employee, Request $request)
    {
        $this->authorize('view', [$employee, $store]);
        $request->validate(['du' => ['nullable', 'date'], 'au' => ['nullable', 'date']]);
        $from = $request->filled('du') ? $request->date('du')->utc() : now()->startOfMonth()->utc();
        $to = $request->filled('au') ? $request->date('au')->utc() : now()->utc();

        $lines = DB::table('lignes_vente')->join('ventes', 'ventes.id', '=', 'lignes_vente.vente_id')
            ->where('ventes.boutique_id', $store->id)->where('ventes.statut', SaleStatus::Completed->value)
            ->where('lignes_vente.employe_id', $employee->id)->whereNotNull('lignes_vente.service_id')
            ->whereBetween('ventes.vendue_le', [$from, $to]);
        $appointments = DB::table('rendez_vous')->where('boutique_id', $store->id)
            ->where(fn ($q) => $q->where('employe_id', $employee->id)->orWhereExists(fn ($e) => $e->from('prestations_rendez_vous')->whereColumn('prestations_rendez_vous.rendez_vous_id', 'rendez_vous.id')->where('prestations_rendez_vous.employe_id', $employee->id)))
            ->whereBetween('debut_le', [$from, $to]);
        $commissions = DB::table('commissions')->where('boutique_id', $store->id)->where('employe_id', $employee->id);

        return $this->success([
            'du' => $from->toIso8601String(),
            'au' => $to->toIso8601String(),
            'prestations' => (int) (clone $lines)->sum('lignes_vente.quantite'),
            'chiffre_affaires' => Money::round((string) ((clone $lines)->sum('lignes_vente.montant_total') ?? '0')),
            'clients' => (clone $lines)->whereNotNull('ventes.client_id')->distinct()->count('ventes.client_id'),
            'rendez_vous_termines' => (clone $appointments)->where('statut', 'termine')->count(),
            'rendez_vous_a_venir' => DB::table('rendez_vous')->where('boutique_id', $store->id)->where('employe_id', $employee->id)
                ->whereIn('statut', ['en_attente', 'prevu', 'confirme'])->where('debut_le', '>', now())->count(),
            'par_prestation' => (clone $lines)
                ->selectRaw('lignes_vente.service_id as id, MAX(lignes_vente.nom_produit) as nom, SUM(lignes_vente.quantite) as nombre, SUM(lignes_vente.montant_total) as total')
                ->groupBy('lignes_vente.service_id')->orderByDesc('nombre')->limit(10)->get()
                ->map(fn (object $r) => ['service_id' => (int) $r->id, 'nom' => $r->nom, 'nombre' => (int) $r->nombre, 'chiffre_affaires' => Money::round((string) $r->total)])->all(),
            // Pressing : pièces confiées (en cours) et traitées sur la période, commandes dont il est responsable.
            'pieces_en_cours' => DB::table('articles_depot')->where('boutique_id', $store->id)->where('employe_id', $employee->id)->whereIn('statut', ['recu', 'en_traitement', 'controle'])->count(),
            'pieces_traitees' => DB::table('articles_depot')->where('boutique_id', $store->id)->where('employe_id', $employee->id)->whereIn('statut', ['pret', 'remis'])->whereBetween('updated_at', [$from, $to])->count(),
            'commandes_responsable_en_cours' => DB::table('commandes')->where('boutique_id', $store->id)->where('responsable_id', $employee->id)->whereIn('statut', ['en_attente', 'en_preparation', 'controle', 'prete'])->count(),
            'commissions' => [
                'dues' => Money::round((string) ((clone $commissions)->where('statut', 'due')->sum('montant') ?? '0')),
                'generees_periode' => Money::round((string) ((clone $commissions)->whereIn('statut', ['due', 'payee'])->where('montant', '>', 0)->whereBetween('created_at', [$from, $to])->sum('montant') ?? '0')),
                'payees_periode' => Money::round((string) ((clone $commissions)->where('statut', 'payee')->whereBetween('updated_at', [$from, $to])->sum('montant') ?? '0')),
            ],
        ]);
    }

    /** POST employes/{employee}/photo — `image` (jpg, png, webp ≤ 5 Mo). */
    public function storePhoto(Store $store, Employee $employee, UploadProductImageRequest $request)
    {
        $this->authorize('manage', [$employee, $store]);
        $file = $request->file('image');
        $path = $file->storeAs("employes/{$store->id}", Str::uuid().'.'.$file->extension(), 'public');
        $previous = $employee->photo;
        $employee->forceFill(['photo' => $path])->save();
        if ($previous) {
            Storage::disk('public')->delete($previous);
        }

        return $this->success(new EmployeeResource($employee), 'Photo enregistrée.');
    }

    public function destroyPhoto(Store $store, Employee $employee)
    {
        $this->authorize('manage', [$employee, $store]);
        if ($employee->photo) {
            Storage::disk('public')->delete($employee->photo);
            $employee->forceFill(['photo' => null])->save();
        }

        return $this->success(new EmployeeResource($employee), 'Photo supprimée.');
    }
}

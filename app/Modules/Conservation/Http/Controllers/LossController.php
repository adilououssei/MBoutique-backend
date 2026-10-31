<?php

namespace App\Modules\Conservation\Http\Controllers;

use App\Modules\Conservation\Models\StockLot;
use App\Modules\Inventory\Enums\LossOrigin;
use App\Modules\Inventory\Enums\StockMovementType;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Tenancy\Models\Store;
use App\Modules\Transformations\Enums\TransformationStatus;
use App\Modules\Transformations\Enums\TransformationType;
use App\Modules\Transformations\Models\Transformation;
use App\Shared\Http\Controllers\ApiController;
use Illuminate\Http\Request;

/**
 * GET /pertes — pertes de la période, valorisées : pertes de stock (par
 * origine) et pertes de découpe (écart de poids des découpes, au coût
 * matière du kilo). Lecture seule.
 */
class LossController extends ApiController
{
    public function index(Store $store, Request $request)
    {
        $this->authorize('viewLosses', [StockLot::class, $store]);
        $request->validate(['du' => ['nullable', 'date'], 'au' => ['nullable', 'date', 'after_or_equal:du']]);
        $from = $request->filled('du') ? $request->date('du')->startOfDay() : now()->subDays(29)->startOfDay();
        $to = $request->filled('au') ? $request->date('au')->endOfDay() : now()->endOfDay();

        $movements = StockMovement::query()
            ->with(['product' => fn ($q) => $q->withTrashed(), 'createdBy'])
            ->where('type', StockMovementType::Loss->value)
            ->whereBetween('created_at', [$from, $to])
            ->latest('id')
            ->get();

        $value = fn (StockMovement $m) => $m->cout_unitaire === null ? null : bcmul(ltrim((string) $m->quantite, '-'), (string) $m->cout_unitaire, 2);
        $byOrigin = $movements->groupBy(fn (StockMovement $m) => $m->origine_perte?->value ?? 'non_precisee')
            ->map(fn ($group, $origin) => [
                'origine' => $origin,
                'libelle' => LossOrigin::tryFrom($origin)?->label() ?? 'Non précisée',
                'nombre' => $group->count(),
                'valeur' => $this->sum($group->map($value)),
            ])
            ->sortByDesc(fn ($row) => (float) $row['valeur'])
            ->values();

        $cuts = Transformation::query()
            ->where('type', TransformationType::Cutting->value)
            ->where('statut', TransformationStatus::Completed->value)
            ->whereBetween('effectuee_le', [$from, $to])
            ->where('perte', '>', 0)
            ->get();
        // Coût matière du kilo perdu : (coût des matières / poids de départ) × perte.
        $cutValue = $this->sum($cuts->map(fn (Transformation $t) => bccomp((string) $t->poids_entree, '0', 3) > 0
            ? bcdiv(bcmul((string) $t->cout_matieres, (string) $t->perte, 6), (string) $t->poids_entree, 2)
            : '0'));

        $stockValue = $this->sum($movements->map($value));

        return $this->success([
            'periode' => ['du' => $from->toIso8601String(), 'au' => $to->toIso8601String()],
            'resume' => [
                'valeur_totale' => bcadd($stockValue, $cutValue, 2),
                'pertes_stock' => ['nombre' => $movements->count(), 'valeur' => $stockValue, 'sans_cout' => $movements->filter(fn ($m) => $m->cout_unitaire === null)->count()],
                'decoupe' => ['nombre' => $cuts->count(), 'poids' => bcadd((string) $cuts->sum(fn ($t) => (float) $t->perte), '0', 3), 'valeur' => $cutValue],
                'par_origine' => $byOrigin,
            ],
            'pertes' => $movements->take(200)->map(fn (StockMovement $m) => [
                'id' => $m->id,
                'produit' => $m->product ? ['id' => $m->product->id, 'nom' => $m->product->nom, 'unite' => $m->product->unite->value] : null,
                'quantite' => ltrim((string) $m->quantite, '-'),
                'origine' => $m->origine_perte?->value,
                'libelle_origine' => $m->origine_perte?->label() ?? 'Non précisée',
                'cout_unitaire' => $m->cout_unitaire,
                'valeur' => $value($m),
                'motif' => $m->motif,
                'cree_par' => $m->createdBy ? ['id' => $m->createdBy->id, 'nom' => $m->createdBy->nom] : null,
                'cree_le' => $m->created_at,
            ])->values(),
        ]);
    }

    private function sum($values): string
    {
        return $values->reduce(fn (string $carry, ?string $v) => bcadd($carry, $v ?? '0', 2), '0.00');
    }
}

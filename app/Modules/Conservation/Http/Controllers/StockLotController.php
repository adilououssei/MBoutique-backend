<?php

namespace App\Modules\Conservation\Http\Controllers;

use App\Modules\Catalog\Enums\ProductConservation;
use App\Modules\Conservation\Http\Resources\StockLotResource;
use App\Modules\Conservation\Models\StockLot;
use App\Modules\Conservation\Services\LotService;
use App\Modules\Equipment\Enums\EquipmentType;
use App\Modules\Inventory\Enums\LossOrigin;
use App\Modules\Inventory\Exceptions\InsufficientStockException;
use App\Modules\Tenancy\Models\Store;
use App\Shared\Http\Controllers\ApiController;
use App\Shared\Validation\TenantScopedRules;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

class StockLotController extends ApiController
{
    public function __construct(private readonly LotService $lots) {}

    /**
     * Lots en stock, date limite la plus proche d'abord. `produit_id`,
     * `expire_dans=N` (périmés et à N jours ou moins), `tous=1` (y compris épuisés).
     */
    public function index(Store $store, Request $request)
    {
        $this->authorize('viewAny', [StockLot::class, $store]);

        $lots = StockLot::query()
            ->with(['product', 'storage', 'supplier'])
            ->when($request->filled('equipement_id'), fn ($q) => $q->where('equipement_id', $request->integer('equipement_id')))
            ->when(! $request->boolean('tous'), fn ($q) => $q->where('quantite_restante', '>', 0))
            ->when($request->filled('produit_id'), fn ($q) => $q->where('produit_id', $request->integer('produit_id')))
            ->when($request->filled('statut'), fn ($q) => $q->where('statut', $request->string('statut')))
            ->when($request->boolean('perimes'), fn ($q) => $q->whereNotNull('date_limite')->whereDate('date_limite', '<', now()->toDateString()))
            ->when($request->filled('recherche'), function ($q) use ($request) {
                $term = '%'.trim((string) $request->string('recherche')).'%';
                $q->where(fn ($q) => $q->where('numero_lot', 'like', $term)->orWhere('numero', 'like', $term)->orWhereHas('product', fn ($p) => $p->where('nom', 'like', $term)->orWhere('dci', 'like', $term)));
            })
            ->when($request->filled('expire_dans'), fn ($q) => $q->whereNotNull('date_limite')->whereDate('date_limite', '<=', now()->addDays($request->integer('expire_dans'))))
            ->orderByRaw('CASE WHEN date_limite IS NULL THEN 1 ELSE 0 END')
            ->orderBy('date_limite')
            ->orderBy('recu_le')
            ->paginate(min((int) $request->integer('par_page', 30), 100));

        return $this->success(StockLotResource::collection($lots));
    }

    /** Compteurs pour l'accueil et le menu : lots périmés et bientôt périmés encore en stock. */
    public function alerts(Store $store)
    {
        $this->authorize('viewAny', [StockLot::class, $store]);
        $today = now()->toDateString();
        $active = fn () => StockLot::query()->where('quantite_restante', '>', 0)->whereNotNull('date_limite');

        // Pharmacie : seuils configurés (30, 60, 90, 180 jours…), sinon le seuil court de la conservation.
        $thresholds = $this->thresholds($store);
        $soon = $thresholds === [] ? StockLot::SOON_DAYS : max($thresholds);

        return $this->success([
            'perimes' => $active()->whereDate('date_limite', '<', $today)->count(),
            'bientot' => $active()->whereDate('date_limite', '>=', $today)->whereDate('date_limite', '<=', now()->addDays($soon)->toDateString())->count(),
            'jours_alerte' => $soon,
            'par_seuil' => collect($thresholds)->mapWithKeys(fn (int $days) => [$days => $active()->whereDate('date_limite', '>=', $today)->whereDate('date_limite', '<=', now()->addDays($days)->toDateString())->count()]),
            'bloques' => StockLot::query()->where('quantite_restante', '>', 0)->where('statut', 'bloque')->count(),
            'rappeles' => StockLot::query()->where('quantite_restante', '>', 0)->where('statut', 'rappele')->count(),
        ]);
    }

    /**
     * GET /lots/peremptions?seuil=90 — tableau de suivi : médicament, lot, date,
     * quantité, jours restants, statut ; périmés d'abord, valeur au prix d'achat.
     */
    public function expiries(Store $store, Request $request)
    {
        $this->authorize('viewAny', [StockLot::class, $store]);
        $days = (int) ($request->integer('seuil') ?: (max($this->thresholds($store) ?: [90])));
        $lots = StockLot::query()->with(['product', 'supplier'])
            ->where('quantite_restante', '>', 0)->whereNotNull('date_limite')
            ->whereDate('date_limite', '<=', now()->addDays($days)->toDateString())
            ->orderBy('date_limite')->limit(300)->get();
        $value = $lots->reduce(fn (string $c, StockLot $l) => bcadd($c, bcmul((string) $l->quantite_restante, (string) ($l->prix_achat ?? $l->product?->prix_achat ?? '0'), 4), 4), '0');

        return $this->success([
            'seuil_jours' => $days,
            'seuils' => $this->thresholds($store),
            'valeur_au_prix_achat' => number_format((float) $value, 2, '.', ''),
            'lots' => StockLotResource::collection($lots),
        ]);
    }

    /** GET /lots/{lot} — fiche du lot : origine, quantités, ventes, journal. */
    public function show(Store $store, StockLot $stockLot)
    {
        $this->authorize('viewAny', [StockLot::class, $store]);
        $sold = DB::table('lots_vente')->join('lignes_vente', 'lignes_vente.id', '=', 'lots_vente.ligne_vente_id')->join('ventes', 'ventes.id', '=', 'lignes_vente.vente_id')
            ->where('lots_vente.lot_id', $stockLot->id)->orderByDesc('ventes.vendue_le')->limit(50)
            ->get(['ventes.id', 'ventes.reference', 'ventes.vendue_le', 'ventes.statut', 'lots_vente.quantite']);

        return $this->success([
            ...(new StockLotResource($stockLot->load(['product', 'storage', 'supplier', 'journal.createdBy'])))->resolve(),
            'ventes' => $sold->map(fn (object $s) => ['vente_id' => (int) $s->id, 'reference' => $s->reference, 'le' => $s->vendue_le, 'statut' => $s->statut, 'quantite' => number_format((float) $s->quantite, 3, '.', '')]),
        ]);
    }

    /** POST /lots/{lot}/statut — actif, bloque, rappele (motif exigé pour bloquer ou rappeler). */
    public function changeStatus(Store $store, StockLot $stockLot, Request $request)
    {
        $this->authorize('update', [$stockLot, $store]);
        $data = $request->validate([
            'statut' => ['required', Rule::in(['actif', 'bloque', 'rappele'])],
            'motif' => ['required_unless:statut,actif', 'nullable', 'string', 'max:500'],
        ]);
        $before = $stockLot->statut ?? 'actif';
        $stockLot->update(['statut' => $data['statut'], 'motif_blocage' => $data['statut'] === 'actif' ? null : $data['motif']]);
        $action = ['actif' => 'deblocage', 'bloque' => 'blocage', 'rappele' => 'rappel'][$data['statut']];
        $this->lots->journal($stockLot, $action, "{$before} → {$data['statut']}".(filled($data['motif'] ?? null) ? " : {$data['motif']}" : ''), $request->user()?->id);

        return $this->success(new StockLotResource($stockLot->refresh()->load(['product', 'storage', 'supplier'])), $data['statut'] === 'actif' ? 'Lot remis en vente.' : 'Lot retiré de la vente.');
    }

    /** @return array<int, int> */
    private function thresholds(Store $store): array
    {
        $values = $store->parametres['seuils_peremption'] ?? [];

        return collect(is_array($values) ? $values : [])->map(fn ($v) => (int) $v)->filter(fn (int $v) => $v > 0)->unique()->sort()->values()->all();
    }

    /** PUT /parametres/peremption { seuils: [30, 60, 90, 180] } */
    public function updateThresholds(Store $store, Request $request)
    {
        abort_unless($request->user()->can('stock.ajuster'), 403);
        $data = $request->validate(['seuils' => ['present', 'array', 'max:6'], 'seuils.*' => ['integer', 'min:1', 'max:730']]);
        $settings = $store->parametres?->getArrayCopy() ?? [];
        $settings['seuils_peremption'] = collect($data['seuils'])->map(fn ($v) => (int) $v)->unique()->sort()->values()->all();
        $store->forceFill(['parametres' => $settings])->save();

        return $this->success(['seuils' => $settings['seuils_peremption']], 'Seuils de péremption enregistrés.');
    }

    /** Corriger la date limite (étiquette), le mode de conservation (passé au congélateur) ou la note. */
    public function update(Store $store, StockLot $stockLot, Request $request)
    {
        $this->authorize('update', [$stockLot, $store]);
        $data = $request->validate([
            'date_limite' => ['sometimes', 'nullable', 'date'],
            'numero_lot' => ['sometimes', 'nullable', 'string', 'max:100'],
            'fabrique_le' => ['sometimes', 'nullable', 'date'],
            'conservation' => ['sometimes', 'nullable', Rule::enum(ProductConservation::class)],
            'note' => ['sometimes', 'nullable', 'string', 'max:500'],
            // Espace de conservation : un congélateur, une chambre froide… de la boutique.
            'equipement_id' => ['sometimes', 'nullable', 'integer', TenantScopedRules::existsInCurrentStore('equipements')->whereNull('deleted_at')->whereIn('type', EquipmentType::storageValues())],
        ]);
        $before = $stockLot->only(array_keys($data));
        $stockLot->update($data);
        $changes = collect($data)->filter(fn ($v, $k) => (string) ($before[$k] instanceof \DateTimeInterface ? $before[$k]->format('Y-m-d') : ($before[$k] instanceof \BackedEnum ? $before[$k]->value : $before[$k])) !== (string) $v)
            ->map(fn ($v, $k) => "{$k} : ".($v ?? '—'))->implode(', ');
        if ($changes !== '') {
            $this->lots->journal($stockLot, 'modification', $changes, $request->user()?->id);
        }

        return $this->success(new StockLotResource($stockLot->refresh()->load(['product', 'storage'])), 'Lot mis à jour.');
    }

    /** POST /lots/{lot}/perte — par défaut tout le reste du lot, origine « péremption ». */
    public function loss(Store $store, StockLot $stockLot, Request $request)
    {
        $this->authorize('update', [$stockLot, $store]);
        $data = $request->validate([
            'quantite' => ['nullable', 'numeric', 'gt:0'],
            'origine_perte' => ['nullable', Rule::enum(LossOrigin::class)],
            'motif' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $this->lots->declareLoss(
                $stockLot,
                isset($data['quantite']) ? (string) $data['quantite'] : null,
                LossOrigin::tryFrom($data['origine_perte'] ?? '') ?? LossOrigin::Expiry,
                $data['motif'] ?? null,
                $request->user()?->id,
            );
        } catch (InvalidArgumentException $e) {
            return $this->error($e->getMessage(), [], 422, 'QUANTITE_INVALIDE');
        } catch (InsufficientStockException $e) {
            return $this->error($e->getMessage(), [], 422, 'STOCK_INSUFFISANT');
        }

        return $this->success(new StockLotResource($stockLot->refresh()->load(['product', 'storage'])), 'Perte enregistrée.');
    }
}

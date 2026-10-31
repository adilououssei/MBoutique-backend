<?php

namespace App\Modules\Sales\Http\Controllers;

use App\Modules\Sales\Models\Promotion;
use App\Modules\Tenancy\Models\Store;
use App\Shared\Http\Controllers\ApiController;
use App\Shared\Validation\TenantScopedRules;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Promotions et soldes (feature `promotions`). Lecture : ventes.voir ;
 * création et modification : produits.modifier (même droit que les prix).
 */
class PromotionController extends ApiController
{
    /** `en_cours=1` : seulement celles qui s'appliquent maintenant. */
    public function index(Store $store, Request $request)
    {
        abort_unless($request->user()->can('ventes.voir'), 403);
        $items = Promotion::query()->with('products:id,nom')
            ->when($request->boolean('en_cours'), fn ($q) => $q->running())
            ->orderByDesc('actif')->orderByDesc('debut_le')->limit(200)->get();

        return $this->success($items->map(fn (Promotion $p) => $this->present($p)));
    }

    public function show(Store $store, Promotion $promotion, Request $request)
    {
        abort_unless($request->user()->can('ventes.voir'), 403);
        // Ventes réalisées pendant la promotion (lignes où elle a été appliquée).
        $stats = DB::table('lignes_vente')->join('ventes', 'ventes.id', '=', 'lignes_vente.vente_id')
            ->where('lignes_vente.boutique_id', $store->id)->where('lignes_vente.promotion_id', $promotion->id)->where('ventes.statut', 'terminee')
            ->selectRaw('COUNT(DISTINCT ventes.id) as ventes, COALESCE(SUM(lignes_vente.quantite), 0) as articles, COALESCE(SUM(lignes_vente.montant_total), 0) as total, COALESCE(SUM(lignes_vente.montant_remise), 0) as remises')
            ->first();

        return $this->success([...$this->present($promotion->load('products:id,nom')), 'ventes' => [
            'nombre' => (int) $stats->ventes,
            'articles' => number_format((float) $stats->articles, 3, '.', ''),
            'chiffre_affaires' => number_format((float) $stats->total, 2, '.', ''),
            'remises_accordees' => number_format((float) $stats->remises, 2, '.', ''),
        ]]);
    }

    public function store(Store $store, Request $request)
    {
        abort_unless($request->user()->can('produits.modifier'), 403);
        $data = $this->validated($request, true);
        $promotion = Promotion::create([...collect($data)->except('produit_ids')->all(), 'cree_par_id' => $request->user()->id]);
        $this->syncProducts($promotion, $data['produit_ids'] ?? []);

        return $this->success($this->present($promotion->load('products:id,nom')), 'Promotion enregistrée.', [], 201);
    }

    public function update(Store $store, Promotion $promotion, Request $request)
    {
        abort_unless($request->user()->can('produits.modifier'), 403);
        $data = $this->validated($request, false);
        $promotion->update(collect($data)->except('produit_ids')->all());
        if (array_key_exists('produit_ids', $data)) {
            $this->syncProducts($promotion, $data['produit_ids'] ?? []);
        }

        return $this->success($this->present($promotion->load('products:id,nom')), 'Promotion mise à jour.');
    }

    /** @param  array<int, int>  $ids */
    private function syncProducts(Promotion $promotion, array $ids): void
    {
        $promotion->products()->sync(collect($ids)->mapWithKeys(fn (int $id) => [$id => ['boutique_id' => $promotion->boutique_id]])->all());
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, bool $creating): array
    {
        $data = $request->validate([
            'nom' => [$creating ? 'required' : 'sometimes', 'string', 'max:255'],
            'type' => [$creating ? 'required' : 'sometimes', Rule::in(['pourcentage', 'montant'])],
            'valeur' => [$creating ? 'required' : 'sometimes', 'numeric', 'gt:0'],
            'debut_le' => [$creating ? 'required' : 'sometimes', 'date'],
            'fin_le' => ['nullable', 'date', 'after_or_equal:debut_le'],
            'portee' => [$creating ? 'required' : 'sometimes', Rule::in(['tous', 'produits', 'categorie', 'collection'])],
            'categorie_id' => ['nullable', 'required_if:portee,categorie', 'integer', TenantScopedRules::existsInCurrentStore('categories')],
            'collection_id' => ['nullable', 'required_if:portee,collection', 'integer', TenantScopedRules::existsInCurrentStore('collections')],
            'produit_ids' => ['nullable', 'required_if:portee,produits', 'array', 'max:500'],
            'produit_ids.*' => ['integer', 'distinct', TenantScopedRules::existsInCurrentStore('produits')],
            'actif' => ['sometimes', 'boolean'],
        ]);
        if (($data['type'] ?? null) === 'pourcentage' && (float) $data['valeur'] > 100) {
            abort(response()->json(['succes' => false, 'donnees' => null, 'message' => 'Un pourcentage ne dépasse pas 100.', 'code' => 'VALIDATION_ECHOUEE', 'erreurs' => ['valeur' => ['Un pourcentage ne dépasse pas 100.']]], 422));
        }

        return $data;
    }

    /** @return array<string, mixed> */
    private function present(Promotion $p): array
    {
        return [
            'id' => $p->id, 'nom' => $p->nom, 'type' => $p->type, 'valeur' => $p->valeur,
            'debut_le' => $p->debut_le, 'fin_le' => $p->fin_le, 'portee' => $p->portee,
            'categorie_id' => $p->categorie_id, 'collection_id' => $p->collection_id,
            'produits' => $p->relationLoaded('products') ? $p->products->map(fn ($pr) => ['id' => $pr->id, 'nom' => $pr->nom])->values() : [],
            'actif' => $p->actif, 'en_cours' => $p->isRunning(),
        ];
    }
}

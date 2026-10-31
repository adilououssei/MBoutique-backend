<?php

namespace App\Modules\Catalog\Http\Controllers;

use App\Modules\Catalog\Models\Collection;
use App\Modules\Catalog\Models\Product;
use App\Modules\Tenancy\Models\Store;
use App\Shared\Http\Controllers\ApiController;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Collections et saisons (feature `collections`). Lecture : produits.voir ; écriture : produits.modifier. */
class CollectionController extends ApiController
{
    public function index(Store $store, Request $request)
    {
        $this->authorize('viewAny', [Product::class, $store]);
        $items = Collection::query()->withCount(['products' => fn ($q) => $q->whereNull('produit_parent_id')])
            ->when($request->has('actif'), fn ($q) => $q->where('actif', $request->boolean('actif')))
            ->orderByDesc('actif')->orderByDesc('debut_le')->orderBy('nom')->get();

        return $this->success($items->map(fn (Collection $c) => $this->present($c)));
    }

    public function store(Store $store, Request $request)
    {
        abort_unless($request->user()->can('produits.modifier'), 403);
        $collection = Collection::create($this->validated($request, true));

        return $this->success($this->present($collection->loadCount('products')), 'Collection créée.', [], 201);
    }

    public function update(Store $store, Collection $collection, Request $request)
    {
        abort_unless($request->user()->can('produits.modifier'), 403);
        $collection->update($this->validated($request, false));

        return $this->success($this->present($collection->loadCount('products')), 'Collection mise à jour.');
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, bool $creating): array
    {
        return $request->validate([
            'nom' => [$creating ? 'required' : 'sometimes', 'string', 'max:255'],
            'saison' => ['nullable', Rule::in(['ete', 'hiver', 'rentree', 'fetes', 'traditionnelle', 'autre'])],
            'debut_le' => ['nullable', 'date'],
            'fin_le' => ['nullable', 'date', 'after_or_equal:debut_le'],
            'actif' => ['sometimes', 'boolean'],
        ]);
    }

    /** @return array<string, mixed> */
    private function present(Collection $c): array
    {
        return [
            'id' => $c->id, 'nom' => $c->nom, 'saison' => $c->saison,
            'debut_le' => $c->debut_le?->toDateString(), 'fin_le' => $c->fin_le?->toDateString(), 'actif' => $c->actif,
            'nombre_articles' => $c->products_count ?? null,
        ];
    }
}

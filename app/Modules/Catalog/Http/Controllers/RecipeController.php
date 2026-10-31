<?php

namespace App\Modules\Catalog\Http\Controllers;

use App\Modules\Authorization\Support\Permissions;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\Recipe;
use App\Modules\Catalog\Services\RecipeService;
use App\Modules\Sales\Support\Money;
use App\Modules\Tenancy\Models\Store;
use App\Shared\Http\Controllers\ApiController;
use App\Shared\Validation\TenantScopedRules;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * Recette d'un plat (feature `recettes`) : GET/PUT/DELETE /produits/{id}/recette,
 * GET /recettes (carte avec coût de revient et marge). Lecture : produits.voir ;
 * écriture : fiches_preparation.gerer (même droit que les fiches de la boucherie).
 */
class RecipeController extends ApiController
{
    public function __construct(private readonly RecipeService $recipes) {}

    public function index(Store $store, Request $request)
    {
        abort_unless($request->user()->can(Permissions::PRODUCTS_VIEW), 403);

        $recipes = Recipe::query()->with(['product', 'ingredients.product'])->latest('id')->paginate(min((int) $request->integer('par_page', 50), 100));

        return $this->success($recipes->through(fn (Recipe $recipe) => $this->present($recipe)));
    }

    public function show(Store $store, Product $product, Request $request)
    {
        abort_unless($request->user()->can(Permissions::PRODUCTS_VIEW), 403);
        $recipe = Recipe::query()->with(['product', 'ingredients.product'])->where('produit_id', $product->id)->first();

        return $this->success($recipe ? $this->present($recipe) : null, $recipe ? null : 'Ce plat n’a pas encore de recette.');
    }

    public function update(Store $store, Product $product, Request $request)
    {
        abort_unless($request->user()->can(Permissions::PREPARATION_SHEETS_MANAGE), 403);
        $data = $request->validate([
            'portions' => ['nullable', 'numeric', 'gt:0', 'max:1000'],
            'note' => ['nullable', 'string', 'max:2000'],
            'mettre_a_jour_prix_achat' => ['sometimes', 'boolean'],
            'ingredients' => ['required', 'array', 'min:1', 'max:50'],
            'ingredients.*.produit_id' => ['required', 'integer', 'distinct', TenantScopedRules::existsInCurrentStore('produits')->whereNull('deleted_at')],
            'ingredients.*.quantite' => ['required', 'numeric', 'gt:0'],
        ]);

        try {
            $recipe = $this->recipes->save($product, $data);
        } catch (InvalidArgumentException $e) {
            return $this->error($e->getMessage(), [], 422, 'RECETTE_INVALIDE');
        }

        return $this->success($this->present($recipe->load(['product', 'ingredients.product'])), 'Recette enregistrée.');
    }

    public function destroy(Store $store, Product $product, Request $request)
    {
        abort_unless($request->user()->can(Permissions::PREPARATION_SHEETS_MANAGE), 403);
        Recipe::query()->where('produit_id', $product->id)->delete();

        return $this->success(null, 'Recette supprimée : le plat sortira lui-même du stock.');
    }

    /** @return array<string, mixed> */
    private function present(Recipe $recipe): array
    {
        $cost = $this->recipes->cost($recipe);
        $product = $recipe->product;
        $price = $product->vente_detail_active ? $product->prix_detail : $product->prix_gros;
        $margin = $cost['cout_portion'] !== null && $price !== null ? Money::round(bcsub((string) $price, $cost['cout_portion'], 4)) : null;
        $lines = collect($cost['lignes'])->keyBy('produit_id');

        return [
            'id' => $recipe->id,
            'produit' => ['id' => $product->id, 'nom' => $product->nom, 'type_article' => $product->type_article?->value, 'image_url' => $product->image ? asset('storage/'.$product->image) : null],
            'portions' => $recipe->portions,
            'note' => $recipe->note,
            'ingredients' => $recipe->ingredients->map(fn ($i) => [
                'produit_id' => $i->produit_id,
                'nom' => $i->product->nom,
                'unite' => $i->product->unite->value,
                'quantite' => $i->quantite,
                'cout_unitaire' => $lines[$i->produit_id]['cout_unitaire'] ?? null,
                'cout' => $lines[$i->produit_id]['cout'] ?? null,
            ])->values(),
            'cout_total' => $cost['cout_total'],
            'cout_portion' => $cost['cout_portion'],
            'ingredients_sans_cout' => $cost['ingredients_sans_cout'],
            'prix_vente' => $price,
            'marge' => $margin,
            'taux_marge' => $margin !== null && $price !== null && bccomp((string) $price, '0', 2) > 0 ? round((float) bcmul(bcdiv($margin, (string) $price, 6), '100', 4), 1) : null,
        ];
    }
}

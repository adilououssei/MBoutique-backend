<?php

namespace App\Modules\Catalog\Services;

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\Recipe;
use App\Modules\Sales\Support\Money;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Recettes (restaurant) : enregistrement, coût de revient et consommation à
 * la vente. Un ingrédient peut lui-même avoir une recette (formule = plats,
 * plat = sauce maison…) : on descend jusqu'aux matières, 3 niveaux au plus.
 */
class RecipeService
{
    private const MAX_DEPTH = 3;

    /**
     * Remplace la recette du plat. `mettre_a_jour_prix_achat` (défaut : oui)
     * reporte le coût de revient sur `produits.prix_achat` quand tous les
     * ingrédients ont un coût : marges et bénéfice estimé le suivent.
     *
     * @param  array{portions?: string|float|int|null, note?: string|null, ingredients: array<int, array{produit_id: int, quantite: string|float|int}>, mettre_a_jour_prix_achat?: bool}  $data
     */
    public function save(Product $product, array $data): Recipe
    {
        return DB::transaction(function () use ($product, $data) {
            foreach ($data['ingredients'] as $line) {
                if ((int) $line['produit_id'] === $product->id) {
                    throw new InvalidArgumentException('Un plat ne peut pas être son propre ingrédient.');
                }
            }

            $recipe = Recipe::query()->updateOrCreate(['produit_id' => $product->id], [
                'portions' => $data['portions'] ?? 1,
                'note' => $data['note'] ?? null,
            ]);
            $recipe->ingredients()->delete();
            foreach ($data['ingredients'] as $line) {
                $recipe->ingredients()->create(['produit_id' => $line['produit_id'], 'quantite' => (string) $line['quantite']]);
            }

            // Une boucle (A dans B, B dans A) rendrait la consommation infinie.
            $this->consumption($product, '1');

            $cost = $this->cost($recipe->load('ingredients.product'));
            if (($data['mettre_a_jour_prix_achat'] ?? true) && $cost['cout_portion'] !== null) {
                $product->forceFill(['prix_achat' => $cost['cout_portion']])->save();
            }

            return $recipe;
        });
    }

    /**
     * Coût de revient d'une portion : Σ quantité × coût de l'ingrédient (son
     * prix d'achat, ou le coût de sa propre recette) ÷ portions. Null si un
     * ingrédient n'a aucun coût connu.
     *
     * @return array{cout_total: string|null, cout_portion: string|null, ingredients_sans_cout: array<int, string>, lignes: array<int, array{produit_id: int, cout_unitaire: string|null, cout: string|null}>}
     */
    public function cost(Recipe $recipe, int $depth = 0): array
    {
        $total = '0';
        $missing = [];
        $lines = [];
        foreach ($recipe->ingredients as $ingredient) {
            $unit = $this->unitCost($ingredient->product, $depth + 1);
            $lineCost = $unit === null ? null : bcmul($unit, (string) $ingredient->quantite, 4);
            if ($lineCost === null) {
                $missing[] = $ingredient->product->nom;
            } else {
                $total = bcadd($total, $lineCost, 4);
            }
            $lines[] = ['produit_id' => $ingredient->produit_id, 'cout_unitaire' => $unit === null ? null : Money::round($unit), 'cout' => $lineCost === null ? null : Money::round($lineCost)];
        }
        $known = $missing === [] && $recipe->ingredients->isNotEmpty();
        $portions = bccomp((string) $recipe->portions, '0', 2) > 0 ? (string) $recipe->portions : '1';

        return [
            'cout_total' => $known ? Money::round($total) : null,
            'cout_portion' => $known ? Money::round(bcdiv($total, $portions, 4)) : null,
            'ingredients_sans_cout' => $missing,
            'lignes' => $lines,
        ];
    }

    /**
     * Matières consommées pour `quantity` unités du produit : produit_id =>
     * quantité, recettes imbriquées développées. Vide si le produit n'a pas
     * de recette (il sort alors lui-même du stock, comme avant).
     *
     * @return array<int, string>
     */
    public function consumption(Product $product, string $quantity, int $depth = 0): array
    {
        $recipe = Recipe::query()->with('ingredients.product')->where('produit_id', $product->id)->first();
        if ($recipe === null || $recipe->ingredients->isEmpty()) {
            return [];
        }
        if ($depth >= self::MAX_DEPTH) {
            throw new InvalidArgumentException("Recettes imbriquées trop profondes (ou en boucle) autour de « {$product->nom} ».");
        }

        $portions = bccomp((string) $recipe->portions, '0', 2) > 0 ? (string) $recipe->portions : '1';
        $needs = [];
        foreach ($recipe->ingredients as $ingredient) {
            $amount = bcdiv(bcmul((string) $ingredient->quantite, $quantity, 6), $portions, 6);
            $nested = $this->consumption($ingredient->product, $amount, $depth + 1);
            foreach ($nested ?: [$ingredient->produit_id => $amount] as $productId => $qty) {
                $needs[$productId] = bcadd($needs[$productId] ?? '0', $qty, 6);
            }
        }

        return array_map(fn (string $q) => bcadd($q, '0', 3), $needs);
    }

    private function unitCost(Product $product, int $depth): ?string
    {
        if ($depth < self::MAX_DEPTH) {
            $recipe = Recipe::query()->with('ingredients.product')->where('produit_id', $product->id)->first();
            if ($recipe !== null && $recipe->ingredients->isNotEmpty()) {
                return $this->cost($recipe, $depth)['cout_portion'];
            }
        }

        return $product->prix_achat !== null ? (string) $product->prix_achat : null;
    }
}

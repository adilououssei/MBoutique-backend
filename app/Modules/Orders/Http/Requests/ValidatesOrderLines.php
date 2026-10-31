<?php

namespace App\Modules\Orders\Http\Requests;

use App\Modules\Catalog\Enums\PricingMode;
use App\Shared\Validation\TenantScopedRules;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/** Règles d'une ligne de commande : un produit (mode de prix facultatif) OU un service. */
trait ValidatesOrderLines
{
    /** @return array<string, array<int, mixed>> */
    protected function lineRules(bool $required): array
    {
        return [
            'lignes' => [$required ? 'required' : 'sometimes', 'array', $required ? 'min:1' : 'min:0'],
            'lignes.*.produit_id' => ['nullable', 'integer', TenantScopedRules::existsInCurrentStore('produits')->whereNull('deleted_at')],
            'lignes.*.service_id' => ['nullable', 'integer', TenantScopedRules::existsInCurrentStore('services')->whereNull('deleted_at')],
            'lignes.*.mode_prix' => ['nullable', Rule::enum(PricingMode::class)],
            'lignes.*.quantite' => ['required', 'numeric', 'gt:0'],
            'lignes.*.note' => ['nullable', 'string', 'max:255'],
            // Suppléments payants du plat (restaurant) : deviennent des lignes rattachées.
            'lignes.*.supplements' => ['nullable', 'array', 'max:10'],
            'lignes.*.supplements.*.produit_id' => ['required', 'integer', TenantScopedRules::existsInCurrentStore('produits')->whereNull('deleted_at')],
            'lignes.*.supplements.*.quantite' => ['nullable', 'numeric', 'gt:0'],
            // Pressing : type d'article (prix de la grille) et description de chaque pièce déposée.
            'lignes.*.type_article' => ['nullable', 'string', 'max:100'],
            'lignes.*.articles' => ['nullable', 'array', 'max:100'],
            'lignes.*.articles.*.type_article' => ['nullable', 'string', 'max:100'],
            'lignes.*.articles.*.couleur' => ['nullable', 'string', 'max:100'],
            'lignes.*.articles.*.marque' => ['nullable', 'string', 'max:100'],
            'lignes.*.articles.*.etat' => ['nullable', 'string', 'max:255'],
            'lignes.*.articles.*.defauts' => ['nullable', 'string', 'max:500'],
            'lignes.*.articles.*.instructions' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            foreach ((array) $this->input('lignes', []) as $i => $line) {
                if (is_array($line) && filled($line['produit_id'] ?? null) === filled($line['service_id'] ?? null)) {
                    $validator->errors()->add("lignes.{$i}", 'Chaque ligne doit porter un produit ou un service, et un seul.');
                }
            }
        });
    }
}

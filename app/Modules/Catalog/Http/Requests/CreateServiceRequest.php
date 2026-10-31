<?php

namespace App\Modules\Catalog\Http\Requests;

use App\Shared\Validation\TenantScopedRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

class CreateServiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'slug' => $this->input('slug') ?: Str::slug((string) $this->input('nom')),
        ]);
    }

    public function rules(): array
    {
        return [
            'nom' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:255', 'alpha_dash', TenantScopedRules::uniqueInCurrentStore('services', 'slug')],
            'description' => ['nullable', 'string'],
            'categorie_id' => ['nullable', 'integer', TenantScopedRules::existsInCurrentStore('categories')],
            'prix' => ['required', 'numeric', 'min:0'],
            'duree_minutes' => ['nullable', 'integer', 'min:1'],
            // Tarif « à partir de », ajusté sur le dossier ou le devis.
            'prix_variable' => ['sometimes', 'boolean'],
            'actif' => ['sometimes', 'boolean'],
            // Salon : commission propre à la prestation (sinon le taux de l'employé).
            'commission_type' => ['sometimes', 'nullable', 'in:pourcentage,montant'],
            'commission_valeur' => ['sometimes', 'nullable', 'numeric', 'min:0', 'required_with:commission_type'],
            'consommables' => ['sometimes', 'array'],
            'consommables.*.produit_id' => ['required', 'integer', TenantScopedRules::existsInCurrentStore('produits')->whereNull('deleted_at')],
            'consommables.*.quantite' => ['required', 'numeric', 'gt:0'],
            // Pressing : unité de tarification, délai, consignes, grille par type d'article.
            'unite_tarif' => ['sometimes', 'nullable', 'in:piece,kg,m2,paire,lot,heure,seance,jour,forfait'],
            'delai_heures' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:2160'],
            'consignes' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'tarifs_articles' => ['sometimes', 'array', 'max:50'],
            'tarifs_articles.*.type_article' => ['required', 'string', 'max:100', 'distinct'],
            'tarifs_articles.*.prix' => ['required', 'numeric', 'min:0'],
            // Formule combinée : prix et durée globaux, composée de prestations simples.
            'est_formule' => ['sometimes', 'boolean'],
            'composants' => ['sometimes', 'array', 'required_if_accepted:est_formule', 'min:2', 'max:10'],
            'composants.*' => ['integer', 'distinct', TenantScopedRules::existsInCurrentStore('services')->whereNull('deleted_at')->where('est_formule', 0)],
        ];
    }
}

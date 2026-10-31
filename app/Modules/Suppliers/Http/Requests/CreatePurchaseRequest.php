<?php

namespace App\Modules\Suppliers\Http\Requests;

use App\Modules\Suppliers\Enums\PurchasePaymentMode;
use App\Shared\Validation\TenantScopedRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreatePurchaseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'fournisseur_id' => ['nullable', 'integer', TenantScopedRules::existsInCurrentStore('fournisseurs')],
            'lignes' => ['required', 'array', 'min:1'],
            'lignes.*.produit_id' => ['required', 'integer', TenantScopedRules::existsInCurrentStore('produits')],
            'lignes.*.quantite' => ['required', 'numeric', 'gt:0'],
            'lignes.*.cout_unitaire' => ['required', 'numeric', 'min:0'],
            // Produit suivi à l'unité : IMEI ou n° de série de chaque unité reçue (facultatif).
            // Pharmacie : lot du fabricant, péremption, fabrication (repris par le lot créé).
            'lignes.*.numero_lot' => ['nullable', 'string', 'max:100'],
            'lignes.*.date_peremption' => ['nullable', 'date'],
            'lignes.*.fabrique_le' => ['nullable', 'date'],
            'lignes.*.appareils' => ['nullable', 'array'],
            'lignes.*.appareils.*' => ['string', 'max:100'],
            'note' => ['nullable', 'string', 'max:1000'],
            'numero_facture_fournisseur' => ['nullable', 'string', 'max:100'],
            'reference_commande' => ['nullable', 'string', 'max:100'],
            // Transport, douane, livraison : répartis dans le coût de revient, hors dette fournisseur.
            'frais_annexes' => ['nullable', 'numeric', 'min:0'],
            // Remise du fournisseur sur la facture : déduite du total et répartie dans le coût de revient.
            'remise_fournisseur' => ['nullable', 'numeric', 'min:0'],
            // Commande fournisseur (feature commandes_fournisseurs) : rien n'entre en stock avant la réception.
            'commande' => ['sometimes', 'boolean'],
            'detail_frais' => ['nullable', 'string', 'max:500'],
            'achete_le' => ['nullable', 'date', 'before_or_equal:now'],
            'mettre_a_jour_prix_achat' => ['sometimes', 'boolean'],
            // Règlement immédiat, facultatif : sans lui, l'achat est à crédit.
            'paiement' => ['nullable', 'array'],
            'paiement.montant' => ['required_with:paiement', 'numeric', 'min:0'],
            'paiement.mode' => ['required_with:paiement', Rule::enum(PurchasePaymentMode::class)],
            'paiement.caisse_id' => ['nullable', 'required_if:paiement.mode,caisse', 'integer', TenantScopedRules::existsInCurrentStore('caisses')],
            'cle_idempotence' => ['nullable', 'string', 'max:100'],
        ];
    }
}

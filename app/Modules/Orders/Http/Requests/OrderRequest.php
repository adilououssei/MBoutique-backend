<?php

namespace App\Modules\Orders\Http\Requests;

use App\Modules\Orders\Enums\OrderType;
use App\Shared\Validation\TenantScopedRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Ouverture (POST, avec lignes facultatives) ou modification de l'en-tête (PUT). */
class OrderRequest extends FormRequest
{
    use ValidatesOrderLines;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $creating = $this->isMethod('post');

        return [
            'type' => [$creating ? 'required' : 'prohibited', Rule::enum(OrderType::class)],
            // Une table de la salle ; ignorée par OrderService si la commande n'est pas « sur place ».
            'table_id' => ['nullable', 'integer', TenantScopedRules::existsInCurrentStore('tables_salle')->where('actif', true)],
            'client_id' => ['nullable', 'integer', TenantScopedRules::existsInCurrentStore('clients')->whereNull('deleted_at')],
            'nom_client' => ['nullable', 'string', 'max:255'],
            'telephone_client' => ['nullable', 'string', 'max:30'],
            'adresse_livraison' => ['nullable', 'string', 'max:255'],
            'date_promise' => ['nullable', 'date'],
            'note' => ['nullable', 'string'],
            // Restaurant : serveur responsable (membre de la boutique) et nombre de couverts.
            'serveur_id' => ['nullable', 'integer', Rule::exists('utilisateurs_boutique', 'utilisateur_id')->where('boutique_id', $this->route('store')?->id)],
            'nombre_couverts' => ['nullable', 'integer', 'min:1', 'max:500'],
            // Pressing : employé responsable, livraison interne (statut, livreur).
            'responsable_id' => ['nullable', 'integer', TenantScopedRules::existsInCurrentStore('employes')->whereNull('deleted_at')],
            'statut_livraison' => ['nullable', 'in:a_livrer,en_livraison,livree'],
            'livreur_id' => ['nullable', 'integer', TenantScopedRules::existsInCurrentStore('employes')->whereNull('deleted_at')],
            ...($creating ? $this->lineRules(false) : []),
        ];
    }
}

<?php

namespace App\Modules\Features\Http\Controllers;

use App\Modules\Features\Models\DomainFeature;
use App\Modules\Features\Models\Feature;
use App\Modules\Features\Models\StoreFeatureOverride;
use App\Modules\Features\Services\FeatureGate;
use App\Modules\Tenancy\Models\Store;
use App\Shared\Http\Controllers\ApiController;
use Illuminate\Http\Request;

/**
 * Paramètres du module : les fonctionnalités que le métier propose « en
 * option » (ex. réparations en électronique), activées ou non par la
 * boutique elle-même. Seules celles-là sont modifiables ici.
 */
class StoreFeatureSettingsController extends ApiController
{
    public function __construct(private readonly FeatureGate $features) {}

    public function index(Store $store, Request $request)
    {
        abort_unless($request->user()->can('membres.gerer'), 403);

        return $this->success($this->options($store));
    }

    /** Spécialités d'un atelier : adaptent les champs du bien confié et les exemples. */
    public const SPECIALTIES = ['mecanique', 'moto', 'menuiserie', 'couture', 'soudure', 'electronique', 'informatique', 'tapisserie', 'plomberie', 'maintenance', 'fabrication', 'autre'];

    /** PUT parametres/specialite { specialite } — stockée dans boutiques.parametres. */
    public function updateSpecialty(Store $store, Request $request)
    {
        abort_unless($request->user()->can('membres.gerer'), 403);
        $data = $request->validate(['specialite' => ['nullable', 'string', 'in:'.implode(',', self::SPECIALTIES)]]);

        $settings = $store->parametres?->getArrayCopy() ?? [];
        $settings['specialite'] = $data['specialite'] ?? null;
        $store->forceFill(['parametres' => $settings])->save();

        return $this->success(['specialite' => $settings['specialite']], 'Spécialité enregistrée.');
    }

    /** PUT { fonctionnalites: { slug: bool } } */
    public function update(Store $store, Request $request)
    {
        abort_unless($request->user()->can('membres.gerer'), 403);
        $data = $request->validate(['fonctionnalites' => ['required', 'array'], 'fonctionnalites.*' => ['boolean']]);
        $offered = collect($this->options($store))->keyBy('slug');

        foreach ($data['fonctionnalites'] as $slug => $enabled) {
            if (! $offered->has($slug)) {
                return $this->error("« {$slug} » n'est pas une option de ce métier.", [], 422, 'OPTION_INDISPONIBLE');
            }
            if ($enabled && ! $offered[$slug]['inclus_abonnement']) {
                return $this->error("« {$offered[$slug]['nom']} » n'est pas inclus dans votre abonnement.", [], 422, 'OPTION_NON_INCLUSE');
            }
            StoreFeatureOverride::query()->updateOrCreate(
                ['boutique_id' => $store->id, 'fonctionnalite_id' => $offered[$slug]['id']],
                ['activee' => (bool) $enabled],
            );
        }

        return $this->success($this->options($store), 'Paramètres enregistrés.');
    }

    /**
     * Options du métier : état réel (abonnement et dépendances compris),
     * inclusion dans l'abonnement, fonctionnalités dont elle dépend.
     *
     * @return array<int, array{id: int, slug: string, nom: string, description: ?string, activee: bool, inclus_abonnement: bool, depend_de: array<int, array{slug: string, nom: string}>}>
     */
    private function options(Store $store): array
    {
        $ids = DomainFeature::query()->where('domaine_activite_id', $store->domaine_activite_id)->where('optionnelle', true)->pluck('fonctionnalite_id');
        $resolved = $this->features->resolveForStore($store);
        $all = Feature::query()->get(['id', 'slug', 'nom'])->keyBy('id');

        return Feature::query()->with('dependencies')->whereIn('id', $ids)->where('actif', true)->orderBy('nom')->get()
            ->map(fn (Feature $f) => [
                'id' => $f->id,
                'slug' => $f->slug,
                'nom' => $f->nom,
                'description' => $f->description,
                'activee' => (bool) $resolved->get($f->slug, false),
                'inclus_abonnement' => $this->features->planIncludes($store, $f->id),
                'depend_de' => $f->dependencies->map(fn ($d) => $all->get($d->depend_de_fonctionnalite_id))->filter()->map(fn (Feature $d) => ['slug' => $d->slug, 'nom' => $d->nom])->values()->all(),
            ])
            ->values()->all();
    }

    /** Secteurs proposés pour une activité « Autre » (facultatif). */
    public const SECTORS = ['services', 'commerce', 'reparation', 'nettoyage', 'impression', 'formation', 'location', 'evenementiel', 'transport', 'artisanat', 'autre'];

    /**
     * PUT parametres/activite — fiche de l'activité (domaine « Autre ») :
     * nom, description, secteur, offre (produits, services ou les deux),
     * unités utilisées. Stockée dans boutiques.parametres.
     */
    public function updateActivity(Store $store, Request $request)
    {
        abort_unless($request->user()->can('membres.gerer'), 403);
        $data = $request->validate([
            'nom' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:1000'],
            'secteur' => ['nullable', 'in:'.implode(',', self::SECTORS)],
            'offre' => ['required', 'in:produits,services,les_deux'],
            'unites' => ['nullable', 'array', 'max:12'],
            'unites.*' => ['string', 'max:30'],
        ]);
        $settings = $store->parametres?->getArrayCopy() ?? [];
        $settings['activite'] = [
            'nom' => trim($data['nom']),
            'description' => $data['description'] ?? null,
            'secteur' => $data['secteur'] ?? null,
            'offre' => $data['offre'],
            'unites' => array_values(array_unique(array_map('trim', $data['unites'] ?? []))),
        ];
        $store->forceFill(['parametres' => $settings])->save();

        return $this->success($settings['activite'], 'Activité enregistrée.');
    }
}

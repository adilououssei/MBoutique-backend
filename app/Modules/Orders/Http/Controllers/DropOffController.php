<?php

namespace App\Modules\Orders\Http\Controllers;

use App\Modules\CashRegister\Exceptions\InsufficientCashException;
use App\Modules\Catalog\Http\Requests\UploadProductImageRequest;
use App\Modules\Catalog\Models\Product;
use App\Modules\Orders\Exceptions\OrderClosedException;
use App\Modules\Orders\Exceptions\OrderLineException;
use App\Modules\Orders\Http\Resources\OrderResource;
use App\Modules\Orders\Models\DropOffItem;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Services\DropOffService;
use App\Modules\Orders\Services\DropOffTracker;
use App\Modules\Sales\Enums\PaymentMethod;
use App\Modules\Sales\Exceptions\InvalidDepositException;
use App\Modules\Sales\Exceptions\NoOpenCashRegisterSessionException;
use App\Modules\Tenancy\Models\Store;
use App\Shared\Http\Controllers\ApiController;
use App\Shared\Validation\TenantScopedRules;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Pressing (feature `articles_depot`) : remise des pièces, suivi pièce par
 * pièce, recherche par code, consommation prévue, réglage « solde avant
 * remise ».
 */
class DropOffController extends ApiController
{
    private const RELATIONS = ['customer', 'items', 'createdBy', 'waiter', 'articles.employee', 'responsible', 'deliverer', 'sale', 'handovers.handedBy', 'history.user'];

    public function __construct(private readonly DropOffService $dropOff, private readonly DropOffTracker $tracker) {}

    /** POST commandes/{order}/remise — remise totale (défaut) ou des `article_ids`, paiement éventuel. */
    public function handover(Store $store, Order $order, Request $request)
    {
        $this->authorize('update', [$order, $store]);
        $data = $request->validate([
            'article_ids' => ['nullable', 'array'],
            'article_ids.*' => ['integer'],
            'mode' => ['nullable', 'in:retrait,livraison'],
            'note' => ['nullable', 'string', 'max:255'],
            'caisse_id' => ['nullable', 'integer', TenantScopedRules::existsInCurrentStore('caisses')],
            'paiement' => ['nullable', 'array'],
            'paiement.montant' => ['required_with:paiement', 'numeric', 'min:0'],
            'paiement.caisse_id' => ['required_with:paiement', 'integer', TenantScopedRules::existsInCurrentStore('caisses')],
            'paiement.mode_paiement' => ['nullable', Rule::in(array_map(fn (PaymentMethod $m) => $m->value, PaymentMethod::acceptedForCheckout()))],
            'differer_paiement' => ['sometimes', 'boolean'],
        ]);
        // Reporter le solde : réglage de la boutique, ou personne qui gère les crédits clients.
        $mayDefer = ! DropOffService::balanceRequiredBeforeHandover($store) || $request->user()->can('credits.gerer');

        return $this->guard(fn () => $this->success(new OrderResource($this->dropOff->handover($store, $order, $data, $request->user()?->id, $mayDefer)->load(self::RELATIONS)), 'Remise enregistrée.'));
    }

    /** PUT commandes/{order}/articles/{article} — description, employé, étape de la pièce. */
    public function updateArticle(Store $store, Order $order, DropOffItem $article, Request $request)
    {
        $this->authorize('update', [$order, $store]);
        abort_unless($article->commande_id === $order->id, 404);
        $data = $request->validate([
            'type_article' => ['sometimes', 'nullable', 'string', 'max:100'],
            'couleur' => ['sometimes', 'nullable', 'string', 'max:100'],
            'marque' => ['sometimes', 'nullable', 'string', 'max:100'],
            'etat' => ['sometimes', 'nullable', 'string', 'max:255'],
            'defauts' => ['sometimes', 'nullable', 'string', 'max:500'],
            'instructions' => ['sometimes', 'nullable', 'string', 'max:500'],
            'statut' => ['sometimes', 'in:recu,en_traitement,controle,pret'],
            'employe_id' => ['sometimes', 'nullable', 'integer', TenantScopedRules::existsInCurrentStore('employes')->whereNull('deleted_at')],
        ]);

        return $this->guard(function () use ($order, $article, $data, $request) {
            $this->dropOff->updateArticle($order, $article, $data, $request->user()?->id);

            return $this->success(new OrderResource($order->refresh()->load(self::RELATIONS)), 'Pièce mise à jour.');
        });
    }

    /** POST commandes/{order}/articles/{article}/photo — état de la pièce à la réception. */
    public function articlePhoto(Store $store, Order $order, DropOffItem $article, UploadProductImageRequest $request)
    {
        $this->authorize('update', [$order, $store]);
        abort_unless($article->commande_id === $order->id, 404);
        $file = $request->file('image');
        $path = $file->storeAs("depots/{$store->id}", Str::uuid().'.'.$file->extension(), 'public');
        if ($article->photo) {
            Storage::disk('public')->delete($article->photo);
        }
        $article->update(['photo' => $path]);

        return $this->success(new OrderResource($order->load(self::RELATIONS)), 'Photo enregistrée.');
    }

    /** GET commandes-articles?code= — retrouver une pièce (et sa commande) par son code. */
    public function findArticle(Store $store, Request $request)
    {
        $this->authorize('viewAny', [Order::class, $store]);
        $data = $request->validate(['code' => ['required', 'string', 'max:30']]);
        $article = DropOffItem::query()->where('code', trim($data['code']))->first();
        if ($article === null) {
            return $this->error('Aucune pièce ne porte ce code.', [], 404, 'INTROUVABLE');
        }

        return $this->success(['article_id' => $article->id, 'commande_id' => $article->commande_id, 'code' => $article->code, 'statut' => $article->statut]);
    }

    /** GET commandes/{order}/consommations — produits prévus par les prestations (à corriger au passage « prête »). */
    public function consumption(Store $store, Order $order)
    {
        $this->authorize('view', [$order, $store]);
        $needs = $this->tracker->plannedConsumption($order);
        $products = Product::withTrashed()->whereIn('id', array_keys($needs))->get()->keyBy('id');

        return $this->success(collect($needs)->map(fn (string $q, int $id) => ['produit_id' => $id, 'nom' => $products[$id]?->nom ?? '—', 'unite' => $products[$id]?->unite, 'quantite' => $q])->values());
    }

    public function settings(Store $store)
    {
        return $this->success(['solde_avant_remise' => DropOffService::balanceRequiredBeforeHandover($store)]);
    }

    /** PUT parametres/pressing { solde_avant_remise } */
    public function updateSettings(Store $store, Request $request)
    {
        abort_unless($request->user()->can('membres.gerer'), 403);
        $data = $request->validate(['solde_avant_remise' => ['required', 'boolean']]);
        $settings = $store->parametres?->getArrayCopy() ?? [];
        $settings['pressing'] = ['solde_avant_remise' => $data['solde_avant_remise']];
        $store->forceFill(['parametres' => $settings])->save();

        return $this->success(['solde_avant_remise' => $data['solde_avant_remise']], 'Réglage enregistré.');
    }

    private function guard(Closure $operation)
    {
        try {
            return $operation();
        } catch (OrderLineException $e) {
            return $this->error($e->getMessage(), [], 422, $e->errorCode);
        } catch (OrderClosedException $e) {
            return $this->error($e->getMessage(), [], 422, 'COMMANDE_CLOSE');
        } catch (NoOpenCashRegisterSessionException $e) {
            return $this->error($e->getMessage(), [], 422, 'AUCUNE_SESSION_CAISSE_OUVERTE');
        } catch (InvalidDepositException $e) {
            return $this->error($e->getMessage(), [], 422, 'ACOMPTE_INVALIDE');
        } catch (InsufficientCashException $e) {
            return $this->error($e->getMessage(), [], 422, 'SOLDE_CAISSE_INSUFFISANT');
        }
    }
}

<?php

namespace App\Modules\Orders\Http\Controllers;

use App\Modules\CashRegister\Exceptions\InsufficientCashException;
use App\Modules\Conservation\Exceptions\LotNotSellableException;
use App\Modules\Devices\Exceptions\DeviceTrackingException;
use App\Modules\Inventory\Exceptions\InsufficientStockException;
use App\Modules\Inventory\Exceptions\StockNotInitializedException;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Exceptions\EmptyOrderException;
use App\Modules\Orders\Exceptions\OrderClosedException;
use App\Modules\Orders\Exceptions\OrderLineException;
use App\Modules\Orders\Exceptions\TableOccupiedException;
use App\Modules\Orders\Http\Requests\AddOrderItemsRequest;
use App\Modules\Orders\Http\Requests\OrderRequest;
use App\Modules\Orders\Http\Resources\OrderResource;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderItem;
use App\Modules\Orders\Services\DropOffTracker;
use App\Modules\Orders\Services\OrderService;
use App\Modules\Sales\Enums\PaymentMethod;
use App\Modules\Sales\Exceptions\InvalidDepositException;
use App\Modules\Sales\Exceptions\InvalidDiscountException;
use App\Modules\Sales\Exceptions\NoOpenCashRegisterSessionException;
use App\Modules\Sales\Exceptions\PricingModeNotAvailableException;
use App\Modules\Tenancy\Models\Store;
use App\Shared\Http\Controllers\ApiController;
use App\Shared\Validation\TenantScopedRules;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class OrderController extends ApiController
{
    private const RELATIONS = ['diningTable', 'customer', 'items', 'createdBy', 'waiter'];

    public function __construct(private readonly OrderService $orders, private readonly DropOffTracker $dropOff) {}

    /** Relations chargées ; pressing : pièces, responsable, livreur, vente (paiement). */
    private function relations(bool $detail = false): array
    {
        if (! $this->dropOff->tracks()) {
            return self::RELATIONS;
        }

        return [...self::RELATIONS, 'articles.employee', 'responsible', 'deliverer', 'sale', ...($detail ? ['handovers.handedBy', 'history.user'] : [])];
    }

    /** `ouvertes=1` : commandes en cours ; `statut` : liste séparée par des virgules. */
    public function index(Store $store, Request $request)
    {
        $this->authorize('viewAny', [Order::class, $store]);

        $orders = Order::query()
            ->with($this->relations())
            ->when($request->boolean('ouvertes'), fn ($q) => $q->whereIn('statut', OrderStatus::openValues()))
            ->when($request->filled('statut'), fn ($q) => $q->whereIn('statut', explode(',', $request->string('statut'))))
            ->when($request->filled('type'), fn ($q) => $q->where('type', $request->string('type')))
            ->when($request->filled('table_id'), fn ($q) => $q->where('table_id', $request->integer('table_id')))
            ->when($request->filled('serveur_id'), fn ($q) => $q->where('serveur_id', $request->integer('serveur_id')))
            ->when($request->filled('client_id'), fn ($q) => $q->where('client_id', $request->integer('client_id')))
            ->when($request->filled('responsable_id'), fn ($q) => $q->where('responsable_id', $request->integer('responsable_id')))
            // Pressing : en retard (date prévue passée, pas encore remise) ou dues dans les 24 h.
            ->when($request->boolean('en_retard'), fn ($q) => $q->whereIn('statut', [OrderStatus::Waiting->value, OrderStatus::Preparing->value, OrderStatus::Control->value, OrderStatus::Ready->value])->whereNotNull('date_promise')->where('date_promise', '<', now()))
            ->when($request->boolean('bientot'), fn ($q) => $q->whereIn('statut', [OrderStatus::Waiting->value, OrderStatus::Preparing->value, OrderStatus::Control->value, OrderStatus::Ready->value])->whereBetween('date_promise', [now(), now()->addDay()]))
            ->when($request->filled('recherche'), function ($q) use ($request) {
                $term = '%'.$request->string('recherche').'%';
                $q->where(fn ($q) => $q->where('reference', 'like', $term)->orWhere('nom_client', 'like', $term)->orWhere('telephone_client', 'like', $term)
                    ->orWhereHas('customer', fn ($c) => $c->where('nom', 'like', $term)->orWhere('telephone', 'like', $term))
                    ->when($this->dropOff->tracks(), fn ($w) => $w->orWhereHas('articles', fn ($a) => $a->where('code', 'like', $term))));
            })
            ->latest('id')
            ->paginate(min((int) $request->integer('par_page', 30), 100));

        return $this->success(OrderResource::collection($orders));
    }

    public function store(Store $store, OrderRequest $request)
    {
        $this->authorize('create', [Order::class, $store]);

        return $this->guard(fn () => $this->success(new OrderResource($this->orders->open($request->validated(), $request->user()?->id)->load($this->relations())), 'Commande enregistrée.', [], 201));
    }

    public function show(Store $store, Order $order)
    {
        $this->authorize('view', [$order, $store]);

        return $this->success(new OrderResource($order->load($this->relations(true))));
    }

    public function update(Store $store, Order $order, OrderRequest $request)
    {
        $this->authorize('update', [$order, $store]);

        return $this->guard(fn () => $this->success(new OrderResource($this->orders->update($order, $request->validated())->load($this->relations())), 'Commande mise à jour.'));
    }

    public function addItems(Store $store, Order $order, AddOrderItemsRequest $request)
    {
        $this->authorize('update', [$order, $store]);

        return $this->guard(fn () => $this->success(new OrderResource($this->orders->addItems($order, $request->validated('lignes'))->load($this->relations())), 'Articles ajoutés.'));
    }

    public function updateItem(Store $store, Order $order, OrderItem $item, Request $request)
    {
        $this->authorize('update', [$order, $store]);
        $data = $request->validate(['quantite' => ['sometimes', 'numeric', 'gt:0'], 'note' => ['nullable', 'string', 'max:255']]);

        return $this->guard(fn () => $this->success(new OrderResource($this->orders->updateItem($order, $item, $data)->load($this->relations())), 'Ligne mise à jour.'));
    }

    public function removeItem(Store $store, Order $order, OrderItem $item)
    {
        $this->authorize('update', [$order, $store]);

        return $this->guard(fn () => $this->success(new OrderResource($this->orders->removeItem($order, $item)->load($this->relations())), 'Ligne retirée.'));
    }

    /** POST /commandes/{order}/statut — en_attente, en_preparation, prete, servie. */
    public function changeStatus(Store $store, Order $order, Request $request)
    {
        $this->authorize('update', [$order, $store]);
        $data = $request->validate([
            'statut' => ['required', Rule::in(OrderStatus::openValues())],
            // Pressing : quantités réellement consommées au passage « prête » (personnes habilitées au stock).
            'consommations' => ['nullable', 'array'],
            'consommations.*.produit_id' => ['required', 'integer', 'distinct', TenantScopedRules::existsInCurrentStore('produits')],
            'consommations.*.quantite' => ['required', 'numeric', 'min:0'],
        ]);
        if (isset($data['consommations']) && ! $request->user()->can('stock.ajuster')) {
            return $this->error('Seule une personne autorisée à ajuster le stock peut corriger les produits consommés.', [], 403, 'ACCES_INTERDIT');
        }

        return $this->guard(fn () => $this->success(new OrderResource($this->orders->changeStatus($order, OrderStatus::from($data['statut']), $request->user()?->id, $data['consommations'] ?? null)->load($this->relations(true))), 'Commande mise à jour.'));
    }

    /** POST /commandes/{order}/addition — le client demande l'addition : la table passe « en attente de paiement ». */
    public function requestBill(Store $store, Order $order, Request $request)
    {
        $this->authorize('update', [$order, $store]);
        $data = $request->validate(['annuler' => ['sometimes', 'boolean']]);

        return $this->guard(fn () => $this->success(new OrderResource($this->orders->requestBill($order, ! ($data['annuler'] ?? false))->load($this->relations())), 'Addition demandée.'));
    }

    /** POST /commandes/{order}/regrouper — `commande_ids` : leurs lignes et acomptes rejoignent cette commande. */
    public function merge(Store $store, Order $order, Request $request)
    {
        $this->authorize('update', [$order, $store]);
        $data = $request->validate([
            'commande_ids' => ['required', 'array', 'min:1', 'max:10'],
            'commande_ids.*' => ['integer', 'distinct', TenantScopedRules::existsInCurrentStore('commandes')],
        ]);

        return $this->guard(fn () => $this->success(new OrderResource($this->orders->merge($order, $data['commande_ids'], $request->user()?->id)->load($this->relations())), 'Commandes regroupées.'));
    }

    /** POST /commandes/{order}/diviser — règle une partie des lignes (addition séparée) ; la commande reste ouverte pour le reste. */
    public function splitCheckout(Store $store, Order $order, Request $request)
    {
        $this->authorize('checkout', [$order, $store]);
        $data = $request->validate([
            ...$this->paymentRules(),
            'lignes' => ['required', 'array', 'min:1'],
            'lignes.*.ligne_id' => ['required', 'integer', 'distinct'],
            'lignes.*.quantite' => ['nullable', 'numeric', 'gt:0'],
        ]);

        return $this->guard(fn () => $this->success(new OrderResource($this->orders->splitCheckout($store, $order, $data, $request->user()?->id)->load($this->relations())), 'Addition séparée encaissée.'));
    }

    /** @return array<string, array<int, mixed>> */
    private function paymentRules(): array
    {
        return [
            'caisse_id' => ['required', 'integer', TenantScopedRules::existsInCurrentStore('caisses')],
            'montant_remise' => ['nullable', 'numeric', 'min:0'],
            // Espèces par défaut ; Mobile Money, carte, virement hors tiroir ; crédit (client exigé).
            'mode_paiement' => ['nullable', Rule::enum(PaymentMethod::class)],
            'client_id' => ['nullable', 'integer', TenantScopedRules::existsInCurrentStore('clients')->whereNull('deleted_at')],
            // Vente à crédit : part payée maintenant (pressing : paiement partiel).
            'acompte' => ['nullable', 'numeric', 'min:0'],
        ];
    }

    /** POST /commandes/{order}/encaisser — crée la vente (Sales) et clôt la commande. */
    public function checkout(Store $store, Order $order, Request $request)
    {
        $this->authorize('checkout', [$order, $store]);
        $data = $request->validate($this->paymentRules());

        return $this->guard(fn () => $this->success(new OrderResource($this->orders->checkout($store, $order, $data, $request->user()?->id)->load($this->relations())), 'Commande encaissée.'));
    }

    /** POST /commandes/{order}/acompte — somme versée d'avance, entrée dans la caisse choisie. */
    public function deposit(Store $store, Order $order, Request $request)
    {
        $this->authorize('checkout', [$order, $store]);
        $data = $request->validate([
            'montant' => ['required', 'numeric', 'gt:0'],
            'caisse_id' => ['required', 'integer', TenantScopedRules::existsInCurrentStore('caisses')],
        ]);

        return $this->guard(fn () => $this->success(new OrderResource($this->orders->recordDeposit($order, $data, $request->user()?->id)->load($this->relations())), 'Acompte enregistré.'));
    }

    /** Un acompte versé est remboursé depuis `caisse_id`, sauf `conserver_acompte`. */
    public function cancel(Store $store, Order $order, Request $request)
    {
        $this->authorize('cancel', [$order, $store]);
        $data = $request->validate([
            'motif' => ['nullable', 'string', 'max:500'],
            'caisse_id' => ['nullable', 'integer', TenantScopedRules::existsInCurrentStore('caisses')],
            'conserver_acompte' => ['sometimes', 'boolean'],
        ]);

        return $this->guard(fn () => $this->success(new OrderResource($this->orders->cancel($order, $data, $request->user()?->id)->load($this->relations())), 'Commande annulée.'));
    }

    /** Erreurs métier → 422 avec un code exploitable (mêmes codes que Sales pour l'encaissement). */
    private function guard(Closure $operation)
    {
        try {
            return $operation();
        } catch (OrderClosedException $e) {
            return $this->error($e->getMessage(), [], 422, 'COMMANDE_CLOSE');
        } catch (LotNotSellableException $e) {
            return $this->error($e->getMessage(), [], 422, 'LOT_NON_VENDABLE');
        } catch (OrderLineException $e) {
            return $this->error($e->getMessage(), [], 422, $e->errorCode);
        } catch (TableOccupiedException $e) {
            return $this->error($e->getMessage(), [], 422, 'TABLE_OCCUPEE');
        } catch (EmptyOrderException $e) {
            return $this->error($e->getMessage(), [], 422, 'COMMANDE_VIDE');
        } catch (PricingModeNotAvailableException $e) {
            return $this->error($e->getMessage(), [], 422, 'MODE_PRIX_INDISPONIBLE');
        } catch (NoOpenCashRegisterSessionException $e) {
            return $this->error($e->getMessage(), [], 422, 'AUCUNE_SESSION_CAISSE_OUVERTE');
        } catch (InvalidDiscountException $e) {
            return $this->error($e->getMessage(), [], 422, 'REMISE_INVALIDE');
        } catch (InvalidDepositException $e) {
            return $this->error($e->getMessage(), [], 422, 'ACOMPTE_INVALIDE');
        } catch (DeviceTrackingException $e) {
            return $this->error($e->getMessage(), [], 422, $e->errorCode);
        } catch (InsufficientStockException $e) {
            return $this->error($e->getMessage(), [], 422, 'STOCK_INSUFFISANT');
        } catch (StockNotInitializedException $e) {
            return $this->error($e->getMessage().' Initialisez-le depuis la fiche du produit avant de l’encaisser.', [], 422, 'STOCK_NON_INITIALISE');
        } catch (InsufficientCashException $e) {
            return $this->error($e->getMessage(), [], 422, 'SOLDE_CAISSE_INSUFFISANT');
        }
    }
}

<?php

namespace App\Modules\Orders\Http\Controllers;

use App\Modules\CashRegister\Exceptions\InsufficientCashException;
use App\Modules\Inventory\Exceptions\InsufficientStockException;
use App\Modules\Inventory\Exceptions\StockNotInitializedException;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Exceptions\EmptyOrderException;
use App\Modules\Orders\Exceptions\OrderClosedException;
use App\Modules\Orders\Exceptions\TableOccupiedException;
use App\Modules\Orders\Http\Requests\AddOrderItemsRequest;
use App\Modules\Orders\Http\Requests\OrderRequest;
use App\Modules\Orders\Http\Resources\OrderResource;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderItem;
use App\Modules\Orders\Services\OrderService;
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
    private const RELATIONS = ['diningTable', 'customer', 'items', 'createdBy'];

    public function __construct(private readonly OrderService $orders) {}

    /** `ouvertes=1` : commandes en cours ; `statut` : liste séparée par des virgules. */
    public function index(Store $store, Request $request)
    {
        $this->authorize('viewAny', [Order::class, $store]);

        $orders = Order::query()
            ->with(self::RELATIONS)
            ->when($request->boolean('ouvertes'), fn ($q) => $q->whereIn('statut', OrderStatus::openValues()))
            ->when($request->filled('statut'), fn ($q) => $q->whereIn('statut', explode(',', $request->string('statut'))))
            ->when($request->filled('type'), fn ($q) => $q->where('type', $request->string('type')))
            ->when($request->filled('table_id'), fn ($q) => $q->where('table_id', $request->integer('table_id')))
            ->when($request->filled('recherche'), function ($q) use ($request) {
                $term = '%'.$request->string('recherche').'%';
                $q->where(fn ($q) => $q->where('reference', 'like', $term)->orWhere('nom_client', 'like', $term)->orWhere('telephone_client', 'like', $term));
            })
            ->latest('id')
            ->paginate(min((int) $request->integer('par_page', 30), 100));

        return $this->success(OrderResource::collection($orders));
    }

    public function store(Store $store, OrderRequest $request)
    {
        $this->authorize('create', [Order::class, $store]);

        return $this->guard(fn () => $this->success(new OrderResource($this->orders->open($request->validated(), $request->user()?->id)->load(self::RELATIONS)), 'Commande enregistrée.', [], 201));
    }

    public function show(Store $store, Order $order)
    {
        $this->authorize('view', [$order, $store]);

        return $this->success(new OrderResource($order->load(self::RELATIONS)));
    }

    public function update(Store $store, Order $order, OrderRequest $request)
    {
        $this->authorize('update', [$order, $store]);

        return $this->guard(fn () => $this->success(new OrderResource($this->orders->update($order, $request->validated())->load(self::RELATIONS)), 'Commande mise à jour.'));
    }

    public function addItems(Store $store, Order $order, AddOrderItemsRequest $request)
    {
        $this->authorize('update', [$order, $store]);

        return $this->guard(fn () => $this->success(new OrderResource($this->orders->addItems($order, $request->validated('lignes'))->load(self::RELATIONS)), 'Articles ajoutés.'));
    }

    public function updateItem(Store $store, Order $order, OrderItem $item, Request $request)
    {
        $this->authorize('update', [$order, $store]);
        $data = $request->validate(['quantite' => ['sometimes', 'numeric', 'gt:0'], 'note' => ['nullable', 'string', 'max:255']]);

        return $this->guard(fn () => $this->success(new OrderResource($this->orders->updateItem($order, $item, $data)->load(self::RELATIONS)), 'Ligne mise à jour.'));
    }

    public function removeItem(Store $store, Order $order, OrderItem $item)
    {
        $this->authorize('update', [$order, $store]);

        return $this->guard(fn () => $this->success(new OrderResource($this->orders->removeItem($order, $item)->load(self::RELATIONS)), 'Ligne retirée.'));
    }

    /** POST /commandes/{order}/statut — en_attente, en_preparation, prete, servie. */
    public function changeStatus(Store $store, Order $order, Request $request)
    {
        $this->authorize('update', [$order, $store]);
        $data = $request->validate(['statut' => ['required', Rule::in(OrderStatus::openValues())]]);

        return $this->guard(fn () => $this->success(new OrderResource($this->orders->changeStatus($order, OrderStatus::from($data['statut']), $request->user()?->id)->load(self::RELATIONS)), 'Commande mise à jour.'));
    }

    /** POST /commandes/{order}/encaisser — crée la vente (Sales) et clôt la commande. */
    public function checkout(Store $store, Order $order, Request $request)
    {
        $this->authorize('checkout', [$order, $store]);
        $data = $request->validate([
            'caisse_id' => ['required', 'integer', TenantScopedRules::existsInCurrentStore('caisses')],
            'montant_remise' => ['nullable', 'numeric', 'min:0'],
        ]);

        return $this->guard(fn () => $this->success(new OrderResource($this->orders->checkout($store, $order, $data, $request->user()?->id)->load(self::RELATIONS)), 'Commande encaissée.'));
    }

    public function cancel(Store $store, Order $order, Request $request)
    {
        $this->authorize('cancel', [$order, $store]);
        $data = $request->validate(['motif' => ['nullable', 'string', 'max:500']]);

        return $this->guard(fn () => $this->success(new OrderResource($this->orders->cancel($order, $data['motif'] ?? null)->load(self::RELATIONS)), 'Commande annulée.'));
    }

    /** Erreurs métier → 422 avec un code exploitable (mêmes codes que Sales pour l'encaissement). */
    private function guard(Closure $operation)
    {
        try {
            return $operation();
        } catch (OrderClosedException $e) {
            return $this->error($e->getMessage(), [], 422, 'COMMANDE_CLOSE');
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
        } catch (InsufficientStockException $e) {
            return $this->error($e->getMessage(), [], 422, 'STOCK_INSUFFISANT');
        } catch (StockNotInitializedException $e) {
            return $this->error($e->getMessage().' Initialisez-le depuis la fiche du produit avant de l’encaisser.', [], 422, 'STOCK_NON_INITIALISE');
        } catch (InsufficientCashException $e) {
            return $this->error($e->getMessage(), [], 422, 'SOLDE_CAISSE_INSUFFISANT');
        }
    }
}

<?php

namespace App\Modules\Orders\Http\Controllers;

use App\Modules\Catalog\Enums\ProductKind;
use App\Modules\Orders\Enums\KitchenStatus;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Exceptions\OrderClosedException;
use App\Modules\Orders\Exceptions\OrderLineException;
use App\Modules\Orders\Http\Resources\OrderResource;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderItem;
use App\Modules\Orders\Services\OrderService;
use App\Modules\Sales\Exceptions\InvalidDepositException;
use App\Modules\Tenancy\Models\Store;
use App\Shared\Http\Controllers\ApiController;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Vue cuisine (feature `cuisine`) : les plats des commandes ouvertes, par
 * commande, du plus ancien au plus récent. Lecture : commandes.voir ;
 * changer l'étape d'un plat : commandes.modifier.
 */
class KitchenController extends ApiController
{
    /** Au-delà, sans temps de préparation renseigné, une commande est « urgente ». */
    private const DEFAULT_URGENT_MINUTES = 20;

    public function __construct(private readonly OrderService $orders) {}

    /** GET /cuisine?boissons=0 — commandes avec au moins un plat à préparer, en cours ou prêt. */
    public function index(Store $store, Request $request)
    {
        $this->authorize('viewAny', [Order::class, $store]);
        $withDrinks = $request->boolean('boissons', true);
        $active = [KitchenStatus::Pending->value, KitchenStatus::Preparing->value, KitchenStatus::Ready->value];

        $orders = Order::query()
            ->with(['diningTable', 'waiter', 'items.product'])
            ->whereIn('statut', OrderStatus::openValues())
            ->whereHas('items', fn ($q) => $q->whereIn('statut_cuisine', $active)->whereNull('vente_id'))
            ->oldest('id')
            ->limit(100)
            ->get();

        $data = $orders->map(function (Order $order) use ($active, $withDrinks) {
            $lines = $order->items
                ->filter(fn (OrderItem $item) => $item->statut_cuisine !== null && in_array($item->statut_cuisine->value, $active, true))
                ->reject(fn (OrderItem $item) => $item->product?->type_article === ProductKind::Ingredient)
                ->reject(fn (OrderItem $item) => ! $withDrinks && $item->product?->type_article === ProductKind::Drink);
            $waited = (int) $order->created_at->diffInMinutes(now());
            $expected = (int) ($lines->max(fn (OrderItem $item) => $item->product?->temps_preparation_minutes) ?: self::DEFAULT_URGENT_MINUTES);

            return [
                'id' => $order->id,
                'reference' => $order->reference,
                'type' => $order->type->value,
                'table' => $order->diningTable ? ['id' => $order->diningTable->id, 'nom' => $order->diningTable->nom] : null,
                'serveur' => $order->waiter ? ['id' => $order->waiter->id, 'nom' => $order->waiter->nom] : null,
                'nom_client' => $order->nom_client,
                'note' => $order->note,
                'cree_le' => $order->created_at,
                'attente_minutes' => $waited,
                'urgente' => $waited >= $expected,
                'lignes' => $lines->values()->map(fn (OrderItem $item) => [
                    'id' => $item->id,
                    'ligne_parent_id' => $item->ligne_parent_id,
                    'nom' => $item->nom,
                    'quantite' => $item->quantite,
                    'note' => $item->note,
                    'type_article' => $item->product?->type_article?->value,
                    'temps_preparation_minutes' => $item->product?->temps_preparation_minutes,
                    'statut_cuisine' => $item->statut_cuisine->value,
                    'preparation_commencee_le' => $item->preparation_commencee_le,
                ]),
            ];
        })->filter(fn (array $order) => $order['lignes']->isNotEmpty())->values();

        return $this->success($data);
    }

    /** POST /commandes/{order}/lignes/{item}/cuisine — `statut`, `marquer_epuise` (indisponible). */
    public function update(Store $store, Order $order, OrderItem $item, Request $request)
    {
        $this->authorize('update', [$order, $store]);
        $data = $request->validate([
            'statut' => ['required', Rule::enum(KitchenStatus::class)],
            'marquer_epuise' => ['sometimes', 'boolean'],
        ]);

        try {
            $order = $this->orders->setKitchenStatus($order, $item, KitchenStatus::from($data['statut']), $data['marquer_epuise'] ?? false);
        } catch (OrderClosedException $e) {
            return $this->error($e->getMessage(), [], 422, 'COMMANDE_CLOSE');
        } catch (OrderLineException $e) {
            return $this->error($e->getMessage(), [], 422, $e->errorCode);
        } catch (InvalidDepositException $e) {
            return $this->error($e->getMessage(), [], 422, 'ACOMPTE_INVALIDE');
        }

        return $this->success(new OrderResource($order->load(['diningTable', 'customer', 'items', 'createdBy', 'waiter'])), 'Plat mis à jour.');
    }
}

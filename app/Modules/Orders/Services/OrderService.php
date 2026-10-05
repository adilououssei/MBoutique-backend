<?php

namespace App\Modules\Orders\Services;

use App\Modules\Catalog\Enums\PricingMode;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\Service;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Enums\OrderType;
use App\Modules\Orders\Events\OrderStatusChanged;
use App\Modules\Orders\Exceptions\EmptyOrderException;
use App\Modules\Orders\Exceptions\OrderClosedException;
use App\Modules\Orders\Exceptions\TableOccupiedException;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderItem;
use App\Modules\Sales\Exceptions\PricingModeNotAvailableException;
use App\Modules\Sales\Models\Sale;
use App\Modules\Sales\Services\SaleService;
use App\Modules\Sales\Support\Money;
use App\Modules\Tenancy\Models\Store;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Seul point d'écriture des commandes — docs/modules.md §Orders. Une
 * commande se résout en Sale au paiement via SaleService::checkout() : aucun
 * calcul de prix, de stock ni de caisse n'est dupliqué ici.
 */
class OrderService
{
    public function __construct(private readonly SaleService $sales) {}

    /**
     * @param  array{type: string, table_id?: int|null, client_id?: int|null, nom_client?: string|null, telephone_client?: string|null, adresse_livraison?: string|null, date_promise?: string|null, note?: string|null, lignes?: array<int, array<string, mixed>>}  $data
     */
    public function open(array $data, ?int $userId): Order
    {
        return DB::transaction(function () use ($data, $userId) {
            $tableId = OrderType::from($data['type']) === OrderType::DineIn ? ($data['table_id'] ?? null) : null;
            $this->assertTableFree($tableId);

            $order = Order::create([
                'type' => $data['type'],
                'statut' => OrderStatus::Waiting,
                'table_id' => $tableId,
                'client_id' => $data['client_id'] ?? null,
                'nom_client' => $data['nom_client'] ?? null,
                'telephone_client' => $data['telephone_client'] ?? null,
                'adresse_livraison' => $data['adresse_livraison'] ?? null,
                'date_promise' => $data['date_promise'] ?? null,
                'note' => $data['note'] ?? null,
                'cree_par_id' => $userId,
            ]);
            $order->update(['reference' => sprintf('CMD-%s-%06d', now()->format('Ymd'), $order->id)]);

            $this->createItems($order, $data['lignes'] ?? []);

            return $order;
        });
    }

    /** Infos d'en-tête (client, table, note, date promise…) d'une commande ouverte. */
    public function update(Order $order, array $data): Order
    {
        return DB::transaction(function () use ($order, $data) {
            $order = $this->lockOpen($order);

            if ($order->type !== OrderType::DineIn) {
                unset($data['table_id']); // pas de table hors « sur place »
            }
            if (array_key_exists('table_id', $data) && $data['table_id'] !== $order->table_id) {
                $this->assertTableFree($data['table_id'], $order->id);
            }

            $order->update(array_intersect_key($data, array_flip(['table_id', 'client_id', 'nom_client', 'telephone_client', 'adresse_livraison', 'date_promise', 'note'])));

            return $order;
        });
    }

    /** @param  array<int, array<string, mixed>>  $lines */
    public function addItems(Order $order, array $lines): Order
    {
        return DB::transaction(function () use ($order, $lines) {
            $order = $this->lockOpen($order);
            $this->createItems($order, $lines);

            return $order;
        });
    }

    public function updateItem(Order $order, OrderItem $item, array $data): Order
    {
        return DB::transaction(function () use ($order, $item, $data) {
            $order = $this->lockOpen($order);
            $item->update(array_intersect_key($data, array_flip(['quantite', 'note'])));

            return $order;
        });
    }

    public function removeItem(Order $order, OrderItem $item): Order
    {
        return DB::transaction(function () use ($order, $item) {
            $order = $this->lockOpen($order);
            $item->delete();

            return $order;
        });
    }

    /** Avancer (ou corriger) l'étape : en attente, en préparation, prête, servie. */
    public function changeStatus(Order $order, OrderStatus $status, ?int $userId = null): Order
    {
        if (! $status->isOpen()) {
            throw new InvalidArgumentException('Utilisez « encaisser » ou « annuler » pour clore une commande.');
        }

        return DB::transaction(function () use ($order, $status, $userId) {
            $order = $this->lockOpen($order);
            $order->update(['statut' => $status]);
            OrderStatusChanged::dispatch($order, $userId);

            return $order;
        });
    }

    public function cancel(Order $order, ?string $reason): Order
    {
        return DB::transaction(function () use ($order, $reason) {
            $order = $this->lockOpen($order);
            $order->update(['statut' => OrderStatus::Cancelled, 'motif_annulation' => $reason]);

            return $order;
        });
    }

    /**
     * Encaisse la commande : crée la vente avec SaleService (prix recalculés
     * serveur, stock, caisse, idempotence), puis clôt la commande en la liant.
     * Clé d'idempotence fixe par commande : un double appui ne crée jamais
     * deux ventes.
     *
     * @param  array{caisse_id: int, montant_remise?: string|float|int|null}  $data
     */
    public function checkout(Store $store, Order $order, array $data, ?int $userId): Order
    {
        return DB::transaction(function () use ($store, $order, $data, $userId) {
            $order = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            if ($order->statut === OrderStatus::Paid) {
                return $order; // déjà encaissée (double appui) : on renvoie l'état final
            }
            $this->assertOpen($order);

            $items = $order->items()->get();
            if ($items->isEmpty()) {
                throw new EmptyOrderException('La commande est vide : ajoutez au moins un article avant d’encaisser.');
            }

            /** @var Sale $sale */
            $sale = $this->sales->checkout($store, [
                'caisse_id' => $data['caisse_id'],
                'client_id' => $order->client_id,
                'mode_paiement' => 'especes',
                'montant_remise' => $data['montant_remise'] ?? null,
                'cle_idempotence' => "commande-{$order->id}",
                'lignes' => $items->map(fn (OrderItem $item) => $item->produit_id !== null
                    ? ['produit_id' => $item->produit_id, 'mode_prix' => $item->mode_prix->value, 'quantite' => (string) $item->quantite]
                    : ['service_id' => $item->service_id, 'quantite' => (string) $item->quantite])->all(),
            ], $userId);

            $order->update(['statut' => OrderStatus::Paid, 'vente_id' => $sale->id]);

            return $order;
        });
    }

    /** @param  array<int, array{produit_id?: int|null, service_id?: int|null, mode_prix?: string|null, quantite: string|float|int, note?: string|null}>  $lines */
    private function createItems(Order $order, array $lines): void
    {
        foreach ($lines as $line) {
            if (filled($line['produit_id'] ?? null)) {
                $product = Product::query()->findOrFail($line['produit_id']);
                $mode = PricingMode::from($line['mode_prix'] ?? ($product->vente_detail_active ? 'detail' : 'gros'));
                try {
                    $price = $product->priceFor($mode);
                } catch (InvalidArgumentException) {
                    throw PricingModeNotAvailableException::forProduct($product, $mode);
                }
                $attributes = ['produit_id' => $product->id, 'mode_prix' => $mode, 'nom' => $product->nom, 'prix_unitaire' => $price];
            } else {
                $service = Service::query()->findOrFail($line['service_id']);
                $attributes = ['service_id' => $service->id, 'nom' => $service->nom, 'prix_unitaire' => Money::round((string) $service->prix)];
            }

            OrderItem::create([
                ...$attributes,
                'commande_id' => $order->id,
                'quantite' => (string) $line['quantite'],
                'note' => $line['note'] ?? null,
            ]);
        }
    }

    private function lockOpen(Order $order): Order
    {
        $order = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
        $this->assertOpen($order);

        return $order;
    }

    private function assertOpen(Order $order): void
    {
        if (! $order->statut->isOpen()) {
            throw new OrderClosedException("La commande {$order->reference} est déjà encaissée ou annulée.");
        }
    }

    private function assertTableFree(?int $tableId, ?int $ignoreOrderId = null): void
    {
        if ($tableId === null) {
            return;
        }

        $busy = Order::query()
            ->where('table_id', $tableId)
            ->whereIn('statut', OrderStatus::openValues())
            ->when($ignoreOrderId, fn ($q) => $q->whereKeyNot($ignoreOrderId))
            ->lockForUpdate()
            ->first();

        if ($busy !== null) {
            throw new TableOccupiedException("Cette table a déjà une commande ouverte ({$busy->reference}) : ajoutez-y les articles.");
        }
    }
}

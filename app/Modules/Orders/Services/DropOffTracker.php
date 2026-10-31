<?php

namespace App\Modules\Orders\Services;

use App\Modules\Appointments\Models\ServiceConsumption;
use App\Modules\Appointments\Services\SalonSaleService;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\Service;
use App\Modules\Features\Services\FeatureGate;
use App\Modules\Inventory\Enums\StockMovementType;
use App\Modules\Inventory\Models\Stock;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Events\OrderStatusChanged;
use App\Modules\Orders\Models\DropOffItem;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderHistory;
use App\Modules\Orders\Models\OrderItem;
use App\Modules\Sales\Enums\PaymentMethod;
use App\Modules\Sales\Support\Money;
use App\Modules\Tenancy\Models\Store;
use App\Shared\Tenancy\Contracts\TenantContextContract;

/**
 * Pressing (feature `articles_depot`) : ce qu'OrderService fait en plus pour
 * une commande de dépôt — pièces codées, historique, statuts des pièces,
 * consommation des produits au passage « prête » (une seule fois), état
 * du paiement distinct de la remise. Sans la feature, rien ne change.
 */
class DropOffTracker
{
    /** Statut de la commande → statut des pièces encore au pressing. */
    private const ITEM_STATUS = [
        'en_attente' => 'recu',
        'en_preparation' => 'en_traitement',
        'controle' => 'controle',
        'prete' => 'pret',
    ];

    /** @var array<int, bool> */
    private array $tracking = [];

    public function __construct(
        private readonly FeatureGate $features,
        private readonly SalonSaleService $salon,
        private readonly InventoryService $inventory,
    ) {}

    public function tracks(): bool
    {
        $storeId = app(TenantContextContract::class)->getStoreId();
        if ($storeId === null) {
            return false;
        }

        return $this->tracking[$storeId] ??= ($store = Store::query()->find($storeId)) !== null && $this->features->allows($store, 'articles_depot');
    }

    public function store(): Store
    {
        return Store::query()->findOrFail(app(TenantContextContract::class)->getStoreId());
    }

    /**
     * Pièces d'une ligne : celles décrites, sinon une par unité (pièces,
     * paires, quantité entière jusqu'à 100), sinon un lot (tarif au kg, m²…).
     *
     * @param  array<int, array<string, string|null>>  $described
     */
    public function createArticles(Order $order, OrderItem $item, array $described, ?string $articleType, ?int $userId): void
    {
        $unit = $item->service_id ? Service::withTrashed()->whereKey($item->service_id)->value('unite_tarif') : null;
        $quantity = (float) $item->quantite;
        if ($described === []) {
            $count = in_array($unit, [null, 'piece', 'paire'], true) && floor($quantity) === $quantity && $quantity <= 100 ? (int) $quantity : 1;
            $described = array_fill(0, max(1, $count), ['type_article' => $articleType]);
        }
        $next = DropOffItem::query()->where('commande_id', $order->id)->count();
        foreach ($described as $article) {
            $next++;
            $created = DropOffItem::create([
                'commande_id' => $order->id,
                'ligne_commande_id' => $item->id,
                'code' => sprintf('%05d-%02d', $order->id, $next),
                'type_article' => $article['type_article'] ?? $articleType,
                'couleur' => $article['couleur'] ?? null,
                'marque' => $article['marque'] ?? null,
                'etat' => $article['etat'] ?? null,
                'defauts' => $article['defauts'] ?? null,
                'instructions' => $article['instructions'] ?? null,
            ]);
            $this->log($order, 'statut', null, 'recu', null, $userId, $created->id);
        }
    }

    public function log(Order $order, string $event, ?string $from, ?string $to, ?string $note, ?int $userId, ?int $articleId = null, ?string $amount = null): void
    {
        OrderHistory::create([
            'commande_id' => $order->id,
            'article_id' => $articleId,
            'evenement' => $event,
            'de' => $from,
            'vers' => $to,
            'montant' => $amount,
            'note' => $note,
            'utilisateur_id' => $userId,
            'created_at' => now(),
        ]);
    }

    /**
     * La commande change d'étape : les pièces encore au pressing suivent ;
     * au premier passage « prête », les produits consommés sortent du stock.
     *
     * @param  array<int, array{produit_id: int, quantite: string|float|int}>|null  $actual
     */
    public function onStatusChanged(Order $order, OrderStatus $from, OrderStatus $to, ?int $userId, ?array $actual = null): void
    {
        $this->log($order, 'statut', $from->value, $to->value, null, $userId);
        if (isset(self::ITEM_STATUS[$to->value])) {
            DropOffItem::query()->where('commande_id', $order->id)->whereNotIn('statut', ['remis', 'annule'])->update(['statut' => self::ITEM_STATUS[$to->value]]);
        }
        if ($to === OrderStatus::Ready && ! $order->consommation_faite) {
            $this->consume($order, $userId, $actual);
        }
    }

    /**
     * Toutes les pièces encore au pressing sont prêtes : la commande passe
     * « prête » (et consomme ses produits si ce n'est pas déjà fait).
     */
    public function syncFromArticles(Order $order, ?int $userId): void
    {
        $open = DropOffItem::query()->where('commande_id', $order->id)->whereNotIn('statut', ['remis', 'annule']);
        if ((clone $open)->exists() && ! (clone $open)->where('statut', '!=', 'pret')->exists() && $order->statut !== OrderStatus::Ready) {
            $from = $order->statut;
            $order->update(['statut' => OrderStatus::Ready]);
            $this->onStatusChanged($order, $from, OrderStatus::Ready, $userId);
            OrderStatusChanged::dispatch($order, $userId);
        }
    }

    /**
     * Produits prévus par les prestations de la commande (fiches des
     * prestations), ou quantités réellement utilisées. Tracé sur la
     * commande ; jamais répété (`consommation_faite`).
     *
     * @param  array<int, array{produit_id: int, quantite: string|float|int}>|null  $actual
     */
    public function consume(Order $order, ?int $userId, ?array $actual = null): void
    {
        $store = $this->store();
        if (! $this->features->allows($store, 'stock')) {
            $order->update(['consommation_faite' => true]);

            return;
        }
        $planned = $this->plannedConsumption($order);
        $final = $planned;
        if ($actual !== null) {
            $final = [];
            foreach ($actual as $line) {
                $final[(int) $line['produit_id']] = bcadd($final[(int) $line['produit_id']] ?? '0', (string) $line['quantite'], 3);
            }
        }
        $keys = array_keys($planned + $final);
        sort($keys);
        foreach ($keys as $productId) {
            $quantity = $final[$productId] ?? '0';
            $product = Product::withTrashed()->find($productId);
            ServiceConsumption::create([
                'commande_id' => $order->id,
                'produit_id' => $productId,
                'quantite_prevue' => $planned[$productId] ?? '0',
                'quantite' => $quantity,
                'cout_unitaire' => $product?->prix_achat,
                'ajustee' => bccomp($quantity, $planned[$productId] ?? '0', 3) !== 0,
            ]);
            if ($product !== null && bccomp($quantity, '0', 3) > 0 && Stock::query()->where('produit_id', $productId)->exists()) {
                $this->inventory->removeStock($product, StockMovementType::Consumption, $quantity, $userId, "Traitement de la commande {$order->reference}", $order);
            }
        }
        $order->update(['consommation_faite' => true]);
        if ($keys !== []) {
            $this->log($order, 'consommation', null, null, count($keys).' produit(s) consommé(s)', $userId);
        }
    }

    /** @return array<int, string> produit_id => quantité prévue */
    public function plannedConsumption(Order $order): array
    {
        $store = $this->store();
        $needs = [];
        foreach ($order->items()->whereNotNull('service_id')->get() as $item) {
            $service = Service::withTrashed()->find($item->service_id);
            if ($service === null) {
                continue;
            }
            foreach ($this->salon->consumables($store, $service, (string) $item->quantite) as $productId => $quantity) {
                $needs[$productId] = bcadd($needs[$productId] ?? '0', $quantity, 3);
            }
        }

        return $needs;
    }

    /** Commande annulée : les pièces encore au pressing sont annulées (rien n'est encaissé ni consommé ici). */
    public function cancelArticles(Order $order, string $from, ?string $reason, ?int $userId): void
    {
        DropOffItem::query()->where('commande_id', $order->id)->whereNotIn('statut', ['remis'])->update(['statut' => 'annule']);
        $this->log($order, 'statut', $from, 'annulee', $reason, $userId);
    }

    public function allHandedOver(Order $order): bool
    {
        return ! DropOffItem::query()->where('commande_id', $order->id)->whereNotIn('statut', ['remis', 'annule'])->exists();
    }

    /**
     * Paiement, indépendant de la remise : total, payé (acomptes + vente),
     * solde ; `credit` = le reste est sur le compte du client.
     *
     * @return array{total: string, paye: string, solde: string, statut: string}
     */
    public function paymentSummary(Order $order): array
    {
        $total = $order->relationLoaded('items') ? $order->total() : $order->load('items')->total();
        $deposits = (string) $order->montant_acompte;
        $sale = $order->vente_id ? $order->sale()->first() : null;
        if ($sale !== null && $sale->statut->value !== 'annulee') {
            $credit = $sale->mode_paiement === PaymentMethod::Credit;
            $paid = $credit ? bcadd($deposits, (string) ($sale->montant_acompte ?? '0'), 2) : (string) $sale->montant_total;
            $balance = Money::round(bcsub((string) $sale->montant_total, $paid, 2));

            return ['total' => Money::round((string) $sale->montant_total), 'paye' => Money::round($paid), 'solde' => $balance, 'statut' => bccomp($balance, '0', 2) > 0 ? 'credit' : 'paye'];
        }
        $balance = Money::round(bcsub($total, $deposits, 2));

        return [
            'total' => Money::round($total),
            'paye' => Money::round($deposits),
            'solde' => $balance,
            'statut' => bccomp($deposits, '0', 2) <= 0 ? 'non_paye' : (bccomp($balance, '0', 2) <= 0 ? 'paye' : 'partiel'),
        ];
    }
}

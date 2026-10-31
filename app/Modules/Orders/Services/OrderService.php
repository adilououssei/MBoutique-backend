<?php

namespace App\Modules\Orders\Services;

use App\Modules\CashRegister\Exceptions\CashRegisterSessionClosedException;
use App\Modules\CashRegister\Models\CashRegister;
use App\Modules\CashRegister\Models\CashRegisterSession;
use App\Modules\CashRegister\Services\CashRegisterService;
use App\Modules\Catalog\Enums\PricingMode;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\Service;
use App\Modules\Orders\Enums\KitchenStatus;
use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Enums\OrderType;
use App\Modules\Orders\Events\OrderStatusChanged;
use App\Modules\Orders\Exceptions\EmptyOrderException;
use App\Modules\Orders\Exceptions\OrderClosedException;
use App\Modules\Orders\Exceptions\OrderLineException;
use App\Modules\Orders\Exceptions\TableOccupiedException;
use App\Modules\Orders\Models\DiningTable;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderItem;
use App\Modules\Sales\Exceptions\InvalidDepositException;
use App\Modules\Sales\Exceptions\NoOpenCashRegisterSessionException;
use App\Modules\Sales\Exceptions\PricingModeNotAvailableException;
use App\Modules\Sales\Models\Sale;
use App\Modules\Sales\Services\SaleService;
use App\Modules\Sales\Support\Money;
use App\Modules\Tenancy\Models\Store;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Seul point d'écriture des commandes — docs/modules.md §Orders. Une
 * commande se résout en Sale au paiement via SaleService::checkout() : aucun
 * calcul de prix, de stock ni de caisse n'est dupliqué ici.
 */
class OrderService
{
    public function __construct(
        private readonly SaleService $sales,
        private readonly CashRegisterService $cashRegisters,
        private readonly DropOffTracker $dropOff,
    ) {}

    /**
     * Pressing : `responsable_id`, et par ligne `type_article` (prix de la
     * grille) et `articles` (description de chaque pièce déposée).
     *
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
                // Par défaut, le serveur est celui qui prend la commande.
                'serveur_id' => $data['serveur_id'] ?? $userId,
                'nombre_couverts' => $data['nombre_couverts'] ?? null,
                'responsable_id' => $data['responsable_id'] ?? null,
                'statut_livraison' => OrderType::from($data['type']) === OrderType::Delivery && $this->dropOff->tracks() ? 'a_livrer' : null,
            ]);
            $order->update(['reference' => sprintf('CMD-%s-%06d', now()->format('Ymd'), $order->id)]);
            if ($this->dropOff->tracks()) {
                $this->dropOff->log($order, 'statut', null, OrderStatus::Waiting->value, 'Commande reçue', $userId);
            }
            // Nouveaux clients installés : la table est forcément propre.
            if ($tableId !== null) {
                DiningTable::query()->whereKey($tableId)->update(['a_nettoyer' => false]);
            }

            $this->createItems($order, $data['lignes'] ?? [], $userId);

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

            $previousTable = $order->table_id;
            $before = $order->only(['responsable_id', 'statut_livraison', 'livreur_id']);
            $fields = ['table_id', 'client_id', 'nom_client', 'telephone_client', 'adresse_livraison', 'date_promise', 'note', 'serveur_id', 'nombre_couverts'];
            if ($this->dropOff->tracks()) {
                $fields = [...$fields, 'responsable_id', 'statut_livraison', 'livreur_id'];
                if (($data['statut_livraison'] ?? null) === 'livree' && $order->statut_livraison !== 'livree') {
                    $data['livree_le'] = now();
                    $fields[] = 'livree_le';
                }
            }
            $order->update(array_intersect_key($data, array_flip($fields)));
            if ($this->dropOff->tracks()) {
                foreach (['responsable_id' => 'affectation', 'livreur_id' => 'affectation', 'statut_livraison' => 'livraison'] as $field => $event) {
                    if (array_key_exists($field, $data) && (string) $before[$field] !== (string) $order->{$field}) {
                        $this->dropOff->log($order, $event, $before[$field] === null ? null : (string) $before[$field], $order->{$field} === null ? null : (string) $order->{$field}, $field, auth()->id());
                    }
                }
            }
            // Commande déplacée : l'ancienne table est à débarrasser.
            if ($previousTable !== null && $order->table_id !== $previousTable) {
                DiningTable::query()->whereKey($previousTable)->update(['a_nettoyer' => true]);
            }

            return $order;
        });
    }

    /** @param  array<int, array<string, mixed>>  $lines */
    public function addItems(Order $order, array $lines): Order
    {
        return DB::transaction(function () use ($order, $lines) {
            $order = $this->lockOpen($order);
            $this->assertNotSettled($order);
            $this->createItems($order, $lines, auth()->id());

            return $order;
        });
    }

    public function updateItem(Order $order, OrderItem $item, array $data): Order
    {
        return DB::transaction(function () use ($order, $item, $data) {
            $order = $this->lockOpen($order);
            $this->assertNotSettled($order);
            $item->update(array_intersect_key($data, array_flip(['quantite', 'note'])));
            $this->assertDepositCovered($order);

            return $order;
        });
    }

    public function removeItem(Order $order, OrderItem $item): Order
    {
        return DB::transaction(function () use ($order, $item) {
            $order = $this->lockOpen($order);
            $this->assertNotSettled($order);
            $item->delete();
            $this->assertDepositCovered($order);

            return $order;
        });
    }

    /** Avancer (ou corriger) l'étape : en attente, en préparation, prête, servie. */
    /**
     * @param  array<int, array{produit_id: int, quantite: string|float|int}>|null  $consumption  Pressing : quantités réellement utilisées (au passage « prête »)
     */
    public function changeStatus(Order $order, OrderStatus $status, ?int $userId = null, ?array $consumption = null): Order
    {
        if (! $status->isOpen()) {
            throw new InvalidArgumentException('Utilisez « encaisser » ou « annuler » pour clore une commande.');
        }

        return DB::transaction(function () use ($order, $status, $userId, $consumption) {
            $order = $this->lockOpen($order);
            $from = $order->statut;
            if ($this->dropOff->tracks() && $status === OrderStatus::Served) {
                // Remis = une remise enregistrée (qui, quand, quelles pièces), jamais un simple statut.
                throw new OrderLineException('Enregistrez la remise des articles au client (bouton « Remettre »).', 'REMISE_REQUISE');
            }
            $order->update(['statut' => $status]);
            if ($this->dropOff->tracks() && $from !== $status) {
                $this->dropOff->onStatusChanged($order, $from, $status, $userId, $consumption);
            }
            OrderStatusChanged::dispatch($order, $userId);

            return $order;
        });
    }

    /**
     * Acompte versé sur une commande ouverte : la somme entre en caisse
     * (mouvement « entrée » référencé sur la commande) et s'ajoute au cumul,
     * qui ne peut pas dépasser le total de la commande.
     *
     * @param  array{montant: string|float|int, caisse_id: int}  $data
     */
    public function recordDeposit(Order $order, array $data, ?int $userId): Order
    {
        return DB::transaction(function () use ($order, $data, $userId) {
            $order = $this->lockOpen($order);
            $amount = Money::round((string) $data['montant']);
            $deposit = bcadd((string) $order->montant_acompte, $amount, 2);
            $total = $order->load('items')->total();
            if (bccomp($deposit, $total, 2) > 0) {
                throw new InvalidDepositException("L'acompte ne peut pas dépasser le total de la commande ({$total}).");
            }

            $session = $this->openSession($data['caisse_id']);
            try {
                $this->cashRegisters->recordReceipt($session, $amount, $userId, $order, "Acompte de la commande {$order->reference}");
            } catch (CashRegisterSessionClosedException $e) {
                throw new NoOpenCashRegisterSessionException($e->getMessage(), previous: $e);
            }
            $order->update(['montant_acompte' => $deposit]);
            if ($this->dropOff->tracks()) {
                $this->dropOff->log($order, 'paiement', null, null, 'Acompte', $userId, null, $amount);
            }

            return $order;
        });
    }

    /**
     * Annule une commande ouverte. Un acompte versé est remboursé depuis
     * `caisse_id` (sortie référencée), sauf `conserver_acompte` : il reste
     * alors acquis à la boutique (préparation jamais retirée), sans
     * mouvement de caisse.
     *
     * @param  array{motif?: string|null, caisse_id?: int|null, conserver_acompte?: bool}  $data
     */
    public function cancel(Order $order, array $data, ?int $userId = null): Order
    {
        return DB::transaction(function () use ($order, $data, $userId) {
            $order = $this->lockOpen($order);
            $reason = $data['motif'] ?? null;

            if (bccomp((string) $order->montant_acompte, '0', 2) > 0 && ! ($data['conserver_acompte'] ?? false)) {
                if (empty($data['caisse_id'])) {
                    throw new InvalidDepositException('Choisissez la caisse qui rembourse l’acompte, ou conservez-le.');
                }
                $session = $this->openSession($data['caisse_id']);
                $label = "Remboursement de l'acompte de la commande {$order->reference}".($reason ? " : {$reason}" : '');
                try {
                    $this->cashRegisters->recordRefund($session, (string) $order->montant_acompte, $userId, $order, $label);
                } catch (CashRegisterSessionClosedException $e) {
                    throw new NoOpenCashRegisterSessionException($e->getMessage(), previous: $e);
                }
            }

            $from = $order->statut->value;
            $order->update(['statut' => OrderStatus::Cancelled, 'motif_annulation' => $reason]);
            $this->freeTable($order);
            if ($this->dropOff->tracks()) {
                $this->dropOff->cancelArticles($order, $from, $reason, $userId);
            }

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
            $tracked = $this->dropOff->tracks();
            if ($tracked && $order->vente_id !== null) {
                return $order; // dépôt déjà réglé : la clôture viendra avec la dernière remise
            }

            $items = $order->items()->get();
            if ($items->isEmpty()) {
                throw new EmptyOrderException('La commande est vide : ajoutez au moins un article avant d’encaisser.');
            }

            // Ni les plats signalés indisponibles, ni ceux déjà réglés par une addition séparée.
            $items = $items->filter(fn (OrderItem $item) => $item->vente_id === null && ! $item->isUnavailable());
            if ($items->isEmpty()) {
                // Tout a déjà été réglé par additions séparées : on clôt simplement.
                $order->update(['statut' => OrderStatus::Paid]);
                $this->freeTable($order);

                return $order;
            }

            $sale = $this->sell($store, $order, $items, $data, (string) $order->montant_acompte, "commande-{$order->id}", $userId);
            OrderItem::query()->whereIn('id', $items->pluck('id'))->update(['vente_id' => $sale->id]);
            if ($tracked) {
                // Pressing : payé ≠ remis. La commande reste ouverte tant que des pièces sont au pressing.
                $order->update(['vente_id' => $sale->id]);
                $summary = $this->dropOff->paymentSummary($order);
                $paidNow = bcsub($summary['paye'], (string) $order->montant_acompte, 2);
                $this->dropOff->log($order, 'paiement', null, $summary['statut'], $summary['statut'] === 'credit' ? "Reste {$summary['solde']} sur le compte client" : 'Règlement', $userId, null, $paidNow);
                if ($this->dropOff->allHandedOver($order) && $order->statut === OrderStatus::Served) {
                    $order->update(['statut' => OrderStatus::Paid]);
                }

                return $order;
            }
            $order->update(['statut' => OrderStatus::Paid, 'vente_id' => $sale->id]);
            $this->freeTable($order);

            return $order;
        });
    }

    /**
     * Addition séparée : règle les lignes choisies (une quantité partielle
     * coupe la ligne en deux) par une vente à part ; la commande reste
     * ouverte pour le reste. L'acompte éventuel est imputé au règlement final.
     *
     * @param  array{caisse_id: int, mode_paiement?: string|null, client_id?: int|null, montant_remise?: string|float|int|null, lignes: array<int, array{ligne_id: int, quantite?: string|float|int|null}>}  $data
     */
    public function splitCheckout(Store $store, Order $order, array $data, ?int $userId): Order
    {
        return DB::transaction(function () use ($store, $order, $data, $userId) {
            $order = $this->lockOpen($order);
            $selected = collect();
            foreach ($data['lignes'] as $choice) {
                $item = $order->items()->whereKey($choice['ligne_id'])->whereNull('vente_id')->first();
                if ($item === null || $item->isUnavailable()) {
                    throw new OrderLineException('Cette ligne n’existe pas, est indisponible ou est déjà réglée.', 'LIGNE_INVALIDE');
                }
                $quantity = isset($choice['quantite']) ? (string) $choice['quantite'] : (string) $item->quantite;
                $cmp = bccomp($quantity, (string) $item->quantite, 3);
                if ($cmp > 0) {
                    throw new OrderLineException("Quantité supérieure à celle commandée pour « {$item->nom} ».", 'LIGNE_INVALIDE');
                }
                if ($cmp < 0) {
                    // Coupe la ligne : la part réglée devient une nouvelle ligne.
                    $item->update(['quantite' => bcsub((string) $item->quantite, $quantity, 3)]);
                    $item = $item->replicate(['vente_id']);
                    $item->quantite = $quantity;
                    $item->save();
                }
                $selected->push($item);
            }

            $remaining = $order->items()->whereNull('vente_id')->whereNotIn('id', $selected->pluck('id'))->get()
                ->reject(fn (OrderItem $item) => $item->isUnavailable());
            if ($remaining->isEmpty()) {
                throw new OrderLineException('Ce sont les dernières lignes : utilisez « Encaisser » pour clore la commande.', 'LIGNE_INVALIDE');
            }

            $part = $order->items()->whereNotNull('vente_id')->distinct()->count('vente_id') + 1;
            $sale = $this->sell($store, $order, $selected, $data, '0', "commande-{$order->id}-part-{$part}-".$selected->pluck('id')->sort()->implode('-'), $userId);
            OrderItem::query()->whereIn('id', $selected->pluck('id'))->update(['vente_id' => $sale->id]);

            return $order->refresh();
        });
    }

    /**
     * Étape d'un plat en cuisine. Horodatée (temps de préparation mesuré).
     * « Indisponible » retire le plat (et ses suppléments) de l'addition et
     * peut le marquer épuisé à la carte. La commande suit : un plat lancé la
     * passe en préparation, tous prêts la passent « prête ».
     */
    public function setKitchenStatus(Order $order, OrderItem $item, KitchenStatus $status, bool $markSoldOut = false): Order
    {
        return DB::transaction(function () use ($order, $item, $status, $markSoldOut) {
            $order = $this->lockOpen($order);
            if ($item->vente_id !== null) {
                throw new OrderLineException('Ce plat est déjà réglé.', 'LIGNE_INVALIDE');
            }
            $stamps = match ($status) {
                KitchenStatus::Preparing => ['preparation_commencee_le' => $item->preparation_commencee_le ?? now()],
                KitchenStatus::Ready => ['preparation_commencee_le' => $item->preparation_commencee_le ?? now(), 'prete_le' => now()],
                KitchenStatus::Served => ['prete_le' => $item->prete_le ?? now(), 'servie_le' => now()],
                default => [],
            };
            $item->update(['statut_cuisine' => $status, ...$stamps]);
            if ($status === KitchenStatus::Unavailable) {
                OrderItem::query()->where('ligne_parent_id', $item->id)->update(['statut_cuisine' => KitchenStatus::Unavailable]);
                if ($markSoldOut && $item->produit_id !== null) {
                    Product::query()->whereKey($item->produit_id)->update(['disponible' => false]);
                }
                $this->assertDepositCovered($order);
            }

            $kitchen = $order->items()->whereNull('ligne_parent_id')->whereNotNull('statut_cuisine')->pluck('statut_cuisine')
                ->reject(fn (KitchenStatus $s) => $s === KitchenStatus::Unavailable);
            $next = match (true) {
                $kitchen->isNotEmpty() && $kitchen->every(fn (KitchenStatus $s) => $s === KitchenStatus::Served) => OrderStatus::Served,
                $kitchen->isNotEmpty() && $kitchen->every(fn (KitchenStatus $s) => in_array($s, [KitchenStatus::Ready, KitchenStatus::Served], true)) => OrderStatus::Ready,
                $kitchen->contains(fn (KitchenStatus $s) => $s !== KitchenStatus::Pending) => OrderStatus::Preparing,
                default => null,
            };
            if ($next !== null && $next !== $order->statut) {
                $order->update(['statut' => $next]);
                OrderStatusChanged::dispatch($order, null);
            }

            return $order;
        });
    }

    /** Le client demande l'addition (ou on annule cette demande). */
    public function requestBill(Order $order, bool $requested): Order
    {
        return DB::transaction(function () use ($order, $requested) {
            $order = $this->lockOpen($order);
            $order->update(['addition_demandee_le' => $requested ? now() : null]);

            return $order;
        });
    }

    /**
     * Regroupe d'autres commandes ouvertes dans celle-ci (tables réunies,
     * une seule addition) : lignes et acomptes y passent, les commandes
     * regroupées sont closes (annulées avec le motif) et leurs tables libérées.
     *
     * @param  array<int, int>  $orderIds
     */
    public function merge(Order $order, array $orderIds, ?int $userId): Order
    {
        return DB::transaction(function () use ($order, $orderIds, $userId) {
            $order = $this->lockOpen($order);
            $others = Order::query()->whereIn('id', $orderIds)->whereKeyNot($order->id)->orderBy('id')->lockForUpdate()->get();
            foreach ($others as $other) {
                $this->assertOpen($other);
                if ($other->items()->whereNotNull('vente_id')->exists()) {
                    throw new OrderLineException("La commande {$other->reference} a déjà une addition réglée : encaissez-la séparément.", 'REGROUPEMENT_IMPOSSIBLE');
                }
                $other->items()->update(['commande_id' => $order->id]);
                $order->montant_acompte = bcadd((string) $order->montant_acompte, (string) $other->montant_acompte, 2);
                $other->update(['statut' => OrderStatus::Cancelled, 'montant_acompte' => 0, 'motif_annulation' => "Regroupée dans {$order->reference}"]);
                OrderStatusChanged::dispatch($other, $userId);
                $this->freeTable($other);
            }
            $order->save();

            return $order;
        });
    }

    /**
     * @param  Collection<int, OrderItem>  $items
     * @param  array<string, mixed>  $data
     */
    private function sell(Store $store, Order $order, Collection $items, array $data, string $alreadyCollected, string $key, ?int $userId): Sale
    {
        $method = $data['mode_paiement'] ?? 'especes';
        $customerId = $data['client_id'] ?? $order->client_id;
        if ($method === 'credit') {
            if ($customerId === null) {
                throw new OrderLineException('Une vente à crédit doit avoir un client : choisissez-le.', 'CLIENT_REQUIS');
            }
            // Pressing : acomptes + reste sur le compte client est un cas normal (remise avec paiement différé).
            if (bccomp($alreadyCollected, '0', 2) > 0 && ! $this->dropOff->tracks()) {
                throw new InvalidDepositException('Cette commande a un acompte : encaissez le reste autrement qu’à crédit.');
            }
        }

        $tracked = $this->dropOff->tracks();

        return $this->sales->checkout($store, [
            'caisse_id' => $data['caisse_id'],
            'client_id' => $customerId,
            'mode_paiement' => $method,
            'acompte' => $method === 'credit' ? (string) ($data['acompte'] ?? '0') : null,
            // Pressing : prix fixés au dépôt (grille, kilo) et produits déjà consommés au passage « prête ».
            'sans_consommation_prestations' => $tracked,
            'montant_remise' => $data['montant_remise'] ?? null,
            'deja_encaisse' => $alreadyCollected,
            'cle_idempotence' => $key,
            'lignes' => $items->values()->map(fn (OrderItem $item) => $item->produit_id !== null
                ? ['produit_id' => $item->produit_id, 'mode_prix' => $item->mode_prix->value, 'quantite' => (string) $item->quantite]
                : ['service_id' => $item->service_id, 'quantite' => (string) $item->quantite, ...($tracked ? ['prix_impose' => (string) $item->prix_unitaire] : [])])->all(),
        ], $userId);
    }

    /** Commande close : sa table est à débarrasser avant le client suivant. */
    private function freeTable(Order $order): void
    {
        if ($order->table_id !== null) {
            DiningTable::query()->whereKey($order->table_id)->update(['a_nettoyer' => true]);
        }
    }

    /**
     * Un plat épuisé (disponible=false) est refusé ; ses suppléments, choisis
     * parmi ceux que propose le plat, deviennent des lignes rattachées
     * (ligne_parent_id) au prix de leur propre produit.
     *
     * @param  array<int, array{produit_id?: int|null, service_id?: int|null, mode_prix?: string|null, quantite: string|float|int, note?: string|null, supplements?: array<int, array{produit_id: int, quantite?: string|float|int|null}>|null}>  $lines
     */
    private function createItems(Order $order, array $lines, ?int $userId = null): void
    {
        $tracked = $this->dropOff->tracks();
        foreach ($lines as $line) {
            if (filled($line['produit_id'] ?? null)) {
                $product = Product::query()->findOrFail($line['produit_id']);
                $attributes = $this->productLine($product, $line['mode_prix'] ?? null);
            } else {
                $service = Service::query()->findOrFail($line['service_id']);
                // Pressing : prix de la grille pour ce type d'article (chemise, costume…), sinon celui de la prestation.
                $articleType = $tracked ? (trim((string) ($line['type_article'] ?? '')) ?: null) : null;
                $attributes = [
                    'service_id' => $service->id,
                    'nom' => $articleType ? "{$service->nom} — {$articleType}" : $service->nom,
                    'prix_unitaire' => Money::round($tracked ? $service->priceForArticle($articleType) : (string) $service->prix),
                ];
            }

            $item = OrderItem::create([
                ...$attributes,
                'commande_id' => $order->id,
                'quantite' => (string) $line['quantite'],
                'note' => $line['note'] ?? null,
            ]);
            if ($tracked && $item->service_id !== null) {
                $this->dropOff->createArticles($order, $item, $line['articles'] ?? [], $articleType ?? null, $userId);
            }

            foreach ($line['supplements'] ?? [] as $extra) {
                if (! isset($product) || ! $product->supplements()->whereKey($extra['produit_id'])->exists()) {
                    throw new OrderLineException('Ce supplément n’est pas proposé avec ce plat.', 'SUPPLEMENT_INVALIDE');
                }
                OrderItem::create([
                    ...$this->productLine(Product::query()->findOrFail($extra['produit_id']), null),
                    'commande_id' => $order->id,
                    'ligne_parent_id' => $item->id,
                    'quantite' => (string) ($extra['quantite'] ?? $line['quantite']),
                ]);
            }
            unset($product, $articleType);
        }
    }

    /** @return array<string, mixed> */
    private function productLine(Product $product, ?string $mode): array
    {
        if (! $product->disponible) {
            throw new OrderLineException("« {$product->nom} » est épuisé pour le moment.", 'PRODUIT_INDISPONIBLE');
        }
        $mode = PricingMode::from($mode ?? ($product->vente_detail_active ? 'detail' : 'gros'));
        try {
            $price = $product->priceFor($mode);
        } catch (InvalidArgumentException) {
            throw PricingModeNotAvailableException::forProduct($product, $mode);
        }

        return ['produit_id' => $product->id, 'mode_prix' => $mode, 'nom' => $product->nom, 'prix_unitaire' => $price, 'statut_cuisine' => KitchenStatus::Pending];
    }

    /** Pressing : une commande réglée ne change plus de contenu (la vente est faite). */
    private function assertNotSettled(Order $order): void
    {
        if ($order->vente_id !== null && $this->dropOff->tracks()) {
            throw new OrderLineException('Cette commande est déjà réglée : son contenu ne peut plus changer.', 'COMMANDE_REGLEE');
        }
    }

    private function lockOpen(Order $order): Order
    {
        $order = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
        $this->assertOpen($order);

        return $order;
    }

    /** Une ligne retirée ou réduite ne peut pas faire passer le total sous l'acompte déjà versé. */
    private function assertDepositCovered(Order $order): void
    {
        $total = $order->load('items')->total();
        if (bccomp((string) $order->montant_acompte, $total, 2) > 0) {
            throw new InvalidDepositException("Le total de la commande ({$total}) deviendrait inférieur à l'acompte déjà versé ({$order->montant_acompte}).");
        }
    }

    private function openSession(int $registerId): CashRegisterSession
    {
        $register = CashRegister::query()->findOrFail($registerId);
        if ($register->session_ouverte_id === null) {
            throw new NoOpenCashRegisterSessionException("La caisse \"{$register->nom}\" n'a pas de session ouverte.");
        }

        return CashRegisterSession::query()->findOrFail($register->session_ouverte_id);
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

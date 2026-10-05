<?php

namespace App\Modules\Inventory\Services;

use App\Models\User;
use App\Modules\Authorization\Support\Permissions;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use App\Modules\Features\Services\FeatureGate;
use App\Modules\Inventory\Enums\StockMovementType;
use App\Modules\Inventory\Exceptions\InvalidStockTransferException;
use App\Modules\Inventory\Models\StockTransfer;
use App\Modules\Inventory\Models\StockTransferLine;
use App\Modules\Tenancy\Enums\StoreStatus;
use App\Modules\Tenancy\Enums\StoreUserStatus;
use App\Modules\Tenancy\Models\Store;
use App\Modules\Tenancy\Models\StoreUser;
use App\Shared\Tenancy\Contracts\TenantContextContract;
use Closure;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Transfert immédiat de marchandise entre deux boutiques d'une même
 * entreprise — docs/inventory.md §"Transferts". Une seule transaction :
 * sortie (TransferOut) dans la source, entrée (TransferIn) dans la
 * destination, ou rien du tout.
 *
 * Chaque boutique est isolée par TenantContext : le contexte est basculé
 * sur la destination le temps d'y écrire, puis restauré. Les deux
 * boutiques sont traitées dans l'ordre de leur id, pour que deux transferts
 * croisés (A→B et B→A) verrouillent toujours dans le même ordre.
 */
class StockTransferService
{
    public function __construct(
        private readonly InventoryService $inventory,
        private readonly FeatureGate $features,
        private readonly TenantContextContract $tenantContext,
    ) {}

    /**
     * Boutiques vers lesquelles $user peut transférer depuis $source : même
     * entreprise, actives, avec le suivi de stock, et où il a stock.ajuster.
     *
     * @return Collection<int, Store>
     */
    public function destinationsFor(Store $source, User $user): Collection
    {
        return Store::query()
            ->where('entreprise_id', $source->entreprise_id)
            ->whereKeyNot($source->id)
            ->where('statut', StoreStatus::Active->value)
            ->orderBy('nom')
            ->get()
            ->filter(fn (Store $store) => $this->canReceive($store, $user))
            ->values();
    }

    /**
     * @param  array{boutique_destination_id: int, lignes: array<int, array{produit_id: int, quantite: string|float|int}>, note?: string|null}  $data
     */
    public function transfer(Store $source, array $data, User $user): StockTransfer
    {
        $destination = Store::query()->find($data['boutique_destination_id']);

        if ($destination === null || $destination->id === $source->id || $destination->entreprise_id !== $source->entreprise_id) {
            throw new InvalidStockTransferException('La boutique de destination doit être une autre boutique de la même entreprise.');
        }
        if (! $this->canReceive($destination, $user)) {
            throw new InvalidStockTransferException("Vous ne pouvez pas ajouter de stock dans « {$destination->nom} » (suivi de stock désactivé ou droits insuffisants).");
        }

        // Quantités cumulées par produit (une même ligne saisie deux fois).
        $quantities = collect($data['lignes'])
            ->groupBy('produit_id')
            ->map(fn (Collection $lines) => $lines->reduce(fn (string $sum, array $l) => bcadd($sum, (string) $l['quantite'], 3), '0'))
            ->sortKeys();

        return DB::transaction(function () use ($source, $destination, $quantities, $data, $user) {
            $products = Product::query()->whereIn('id', $quantities->keys())->get()->keyBy('id');

            $transfer = StockTransfer::create([
                'entreprise_id' => $source->entreprise_id,
                'boutique_source_id' => $source->id,
                'boutique_destination_id' => $destination->id,
                'note' => $data['note'] ?? null,
                'cree_par_id' => $user->id,
            ]);
            $transfer->update(['reference' => sprintf('TRF-%s-%06d', now()->format('Ymd'), $transfer->id)]);

            $outReason = "Transfert {$transfer->reference} vers {$destination->nom}";
            $inReason = "Transfert {$transfer->reference} depuis {$source->nom}";

            $send = function () use ($quantities, $products, $user, $outReason, $transfer) {
                foreach ($quantities as $productId => $quantity) {
                    $product = $products->get($productId) ?? throw new InvalidArgumentException("Produit #{$productId} introuvable.");
                    $this->inventory->removeStock($product, StockMovementType::TransferOut, $quantity, $user->id, $outReason, $transfer);
                }
            };

            $receive = fn () => $this->inStore($destination, function () use ($quantities, $products, $user, $inReason, $transfer) {
                foreach ($quantities as $productId => $quantity) {
                    $product = $products->get($productId) ?? throw new InvalidArgumentException("Produit #{$productId} introuvable.");
                    [$target, $created] = $this->matchOrCopy($product);
                    $this->inventory->receiveTransfer($target, $quantity, $user->id, $inReason, $transfer);

                    StockTransferLine::create([
                        'transfert_id' => $transfer->id,
                        'produit_source_id' => $product->id,
                        'produit_destination_id' => $target->id,
                        'nom_produit' => $product->nom,
                        'quantite' => $quantity,
                        'produit_cree' => $created,
                    ]);
                }
            });

            if ($source->id < $destination->id) {
                $send();
                $receive();
            } else {
                $receive();
                $send();
            }

            return $transfer;
        });
    }

    /**
     * Le même article dans la destination : même SKU, sinon même code-barres,
     * sinon même nom (slug). À défaut, une copie de la fiche est créée
     * (catégorie retrouvée par son nom, sans photo). Appelé dans le contexte
     * de la destination.
     *
     * @return array{0: Product, 1: bool} le produit et « créé par ce transfert »
     */
    private function matchOrCopy(Product $product): array
    {
        $match = Product::withTrashed()
            ->where(function ($q) use ($product) {
                $q->where('slug', $product->slug);
                if (filled($product->sku)) {
                    $q->orWhere('sku', $product->sku);
                }
                if (filled($product->code_barres)) {
                    $q->orWhere('code_barres', $product->code_barres);
                }
            })
            ->get()
            ->sortBy(fn (Product $p) => match (true) {
                filled($product->sku) && $p->sku === $product->sku => 0,
                filled($product->code_barres) && $p->code_barres === $product->code_barres => 1,
                default => 2,
            })
            ->first();

        if ($match !== null) {
            if ($match->trashed()) {
                $match->restore();
            }

            return [$match, false];
        }

        $categoryName = $product->categorie_id !== null
            ? Category::withTrashed()->withoutStoreScope()->whereKey($product->categorie_id)->value('nom')
            : null;

        $copy = Product::create([
            ...$product->only(['nom', 'slug', 'description', 'sku', 'code_barres', 'unite', 'prix_achat', 'vente_detail_active', 'prix_detail', 'vente_gros_active', 'prix_gros']),
            'categorie_id' => $categoryName !== null ? Category::query()->where('nom', $categoryName)->value('id') : null,
            'actif' => true,
        ]);

        return [$copy, true];
    }

    private function canReceive(Store $store, User $user): bool
    {
        if ($store->statut !== StoreStatus::Active) {
            return false;
        }

        // Tout dans le contexte de $store : StoreUser et les overrides de
        // fonctionnalités sont eux-mêmes scopés par boutique.
        return $this->inStore($store, function () use ($store, $user) {
            if (! $this->features->allows($store, 'stock')) {
                return false;
            }

            $isMember = StoreUser::query()
                ->where('boutique_id', $store->id)
                ->where('utilisateur_id', $user->id)
                ->where('statut', StoreUserStatus::Active->value)
                ->exists();

            // Utilisateur rechargé : pas de rôles mis en cache pour la boutique courante.
            return $isMember && (bool) $user->fresh()?->checkPermissionTo(Permissions::INVENTORY_ADJUST);
        });
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    private function inStore(Store $store, Closure $callback): mixed
    {
        $previous = $this->tenantContext->getStoreId();
        $this->tenantContext->setStoreId($store->id);

        try {
            return $callback();
        } finally {
            $previous !== null ? $this->tenantContext->setStoreId($previous) : $this->tenantContext->clear();
        }
    }
}

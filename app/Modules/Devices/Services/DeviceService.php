<?php

namespace App\Modules\Devices\Services;

use App\Modules\Catalog\Models\Product;
use App\Modules\Devices\Enums\DeviceStatus;
use App\Modules\Devices\Exceptions\DeviceTrackingException;
use App\Modules\Devices\Models\Device;
use App\Modules\Features\Services\FeatureGate;
use App\Modules\Inventory\Enums\LossOrigin;
use App\Modules\Inventory\Enums\StockMovementType;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Tenancy\Models\Store;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Seul point d'écriture des appareils — docs/modules.md §Devices. Appelé par
 * Sales (vente / annulation) et Suppliers (réception), dans leur transaction.
 * Ne s'applique qu'aux produits `suivi_unitaire` des boutiques qui ont la
 * feature `appareils` : sinon, rien ne change (gestion classique en quantité).
 */
class DeviceService
{
    /** @var array<int, bool> */
    private array $storeTracks = [];

    public function __construct(
        private readonly FeatureGate $features,
        private readonly InventoryService $inventory,
    ) {}

    public function tracks(Product $product): bool
    {
        if (! $product->suivi_unitaire) {
            return false;
        }

        return $this->storeTracks[$product->boutique_id] ??= ($store = Store::query()->find($product->boutique_id)) !== null
            && $this->features->allows($store, 'appareils');
    }

    /**
     * Vente : `$deviceIds` doit désigner exactement `$quantity` appareils en
     * stock de ce produit. Ils passent « vendu », rattachés à la vente, à la
     * ligne et au client.
     *
     * @param  array<int, int|string>  $deviceIds
     */
    public function sell(Product $product, string $quantity, array $deviceIds, Model $sale, Model $saleItem, ?int $customerId, ?int $userId): void
    {
        $count = $this->unitCount($product, $quantity);
        $ids = array_values(array_unique(array_map('intval', $deviceIds)));
        if (count($ids) !== $count) {
            throw new DeviceTrackingException("« {$product->nom} » est suivi à l’unité : choisissez {$count} appareil(s) (IMEI ou n° de série).", 'APPAREILS_REQUIS');
        }

        $devices = Device::query()->whereIn('id', $ids)->lockForUpdate()->get();
        foreach ($ids as $id) {
            $device = $devices->firstWhere('id', $id);
            if ($device === null || $device->produit_id !== $product->id || $device->statut !== DeviceStatus::InStock) {
                $label = $device ? $device->identifier() : "#{$id}";
                throw new DeviceTrackingException("L’appareil {$label} n’est pas disponible à la vente pour « {$product->nom} ».", 'APPAREIL_INDISPONIBLE');
            }
        }

        foreach ($devices as $device) {
            $device->update(['statut' => DeviceStatus::Sold, 'vente_id' => $sale->getKey(), 'ligne_vente_id' => $saleItem->getKey(), 'client_id' => $customerId, 'vendu_le' => now()]);
            $this->log($device, 'vente', "Vendu ({$sale->reference})", $sale, $userId);
        }
    }

    /** Annulation d'une vente : ses appareils redeviennent disponibles. */
    public function releaseFromSale(Model $sale, ?int $userId): void
    {
        foreach (Device::query()->where('vente_id', $sale->getKey())->lockForUpdate()->get() as $device) {
            $device->update(['statut' => DeviceStatus::InStock, 'vente_id' => null, 'ligne_vente_id' => null, 'client_id' => null, 'vendu_le' => null]);
            $this->log($device, 'annulation_vente', "Vente {$sale->reference} annulée : de nouveau en stock", $sale, $userId);
        }
    }

    /**
     * Retour d'appareils vendus (module Returns) : revendable → de nouveau en
     * stock, sinon défectueux. Le stock est tenu par l'appelant.
     *
     * @param  array<int, int>  $deviceIds
     */
    public function returnFromSale(Model $saleItem, array $deviceIds, bool $defective, Model $return, ?int $userId): void
    {
        $devices = Device::query()->whereIn('id', $deviceIds)->lockForUpdate()->get();
        foreach ($deviceIds as $id) {
            $device = $devices->firstWhere('id', $id);
            if ($device === null || $device->ligne_vente_id !== $saleItem->getKey() || $device->statut !== DeviceStatus::Sold) {
                throw new DeviceTrackingException('Un appareil retourné ne fait pas partie de cette ligne de vente.', 'APPAREIL_INDISPONIBLE');
            }
        }
        foreach ($devices as $device) {
            $device->update(['statut' => $defective ? DeviceStatus::Defective : DeviceStatus::InStock, 'ligne_vente_id' => null, 'client_id' => null, 'vendu_le' => null, 'vente_id' => null]);
            $this->log($device, 'retour', $defective ? 'Retourné défectueux' : 'Retourné, remis en stock', $return, $userId);
        }
    }

    /**
     * Réception par un achat : identifiants facultatifs (on peut les saisir
     * plus tard), mais s'ils sont donnés, un par unité reçue.
     *
     * @param  array<int, string>  $identifiers
     */
    public function receive(Product $product, string $quantity, array $identifiers, Model $purchaseItem, string $unitCost, ?int $userId): void
    {
        if ($identifiers === []) {
            return;
        }
        $count = $this->unitCount($product, $quantity);
        if (count($identifiers) !== $count) {
            throw new DeviceTrackingException("« {$product->nom} » : {$count} unité(s) reçue(s), mais ".count($identifiers).' identifiant(s) saisi(s).', 'APPAREILS_REQUIS');
        }

        foreach ($this->createDevices($product, $identifiers, ['cout_achat' => $unitCost, 'ligne_achat_id' => $purchaseItem->getKey()]) as $device) {
            $this->log($device, 'entree', 'Reçu par un achat', $purchaseItem, $userId);
        }
    }

    /**
     * Enregistrement direct : appareils déjà en stock (avant le suivi) ou, avec
     * `$addToStock`, nouvelle entrée en stock du même nombre d'unités.
     *
     * @param  array<int, string>  $identifiers
     * @return Collection<int, Device>
     */
    public function register(Product $product, array $identifiers, bool $addToStock, ?string $unitCost, ?string $note, ?int $userId): Collection
    {
        return DB::transaction(function () use ($product, $identifiers, $addToStock, $unitCost, $note, $userId) {
            if (! $product->suivi_unitaire) {
                throw new DeviceTrackingException("« {$product->nom} » n’est pas suivi à l’unité : activez-le dans sa fiche.", 'PRODUIT_NON_SUIVI');
            }
            $count = (string) count($identifiers);

            if ($addToStock) {
                $this->inventory->isInitialized($product)
                    ? $this->inventory->addStock($product, StockMovementType::Purchase, $count, $userId, 'Appareils enregistrés')
                    : $this->inventory->initializeStock($product, $count, null, $userId, 'Appareils enregistrés');
            } else {
                // Sans entrée en stock, on n'identifie que des unités déjà comptées.
                $untracked = bcsub($this->inventory->quantityOf($product), (string) Device::query()->where('produit_id', $product->id)->where('statut', DeviceStatus::InStock)->count(), 3);
                if (bccomp($count, $untracked, 3) > 0) {
                    throw new DeviceTrackingException('Il n’y a que '.rtrim(rtrim($untracked, '0'), '.')." unité(s) en stock sans identifiant pour « {$product->nom} ». Cochez « Ajouter au stock » pour un nouvel arrivage.", 'STOCK_INSUFFISANT');
                }
            }

            $devices = $this->createDevices($product, $identifiers, ['cout_achat' => $unitCost ?? $product->prix_achat, 'note' => $note]);
            foreach ($devices as $device) {
                $this->log($device, 'entree', $addToStock ? 'Entré en stock' : 'Identifié (déjà en stock)', null, $userId);
            }

            return $devices;
        });
    }

    /**
     * Défectueux ou perdu/volé : sort du stock (perte valorisée). Remis en
     * stock (réparé, retrouvé) : y revient.
     */
    public function changeStatus(Device $device, DeviceStatus $status, ?string $reason, ?int $userId): Device
    {
        return DB::transaction(function () use ($device, $status, $reason, $userId) {
            $device = Device::query()->whereKey($device->id)->lockForUpdate()->firstOrFail();
            $from = $device->statut;
            $allowed = match ($status) {
                DeviceStatus::Defective, DeviceStatus::Lost => in_array($from, [DeviceStatus::InStock, DeviceStatus::Defective], true) && $from !== $status,
                DeviceStatus::InStock => in_array($from, [DeviceStatus::Defective, DeviceStatus::Lost], true),
                DeviceStatus::Sold => false,
            };
            if (! $allowed) {
                throw new DeviceTrackingException("Passage de « {$from->label()} » à « {$status->label()} » impossible.", 'STATUT_APPAREIL_INVALIDE');
            }

            $product = $device->product;
            $label = "Appareil {$device->identifier()}".($reason ? " : {$reason}" : '');
            if ($from === DeviceStatus::InStock) {
                $origin = $status === DeviceStatus::Lost ? LossOrigin::Theft : LossOrigin::Spoiled;
                $this->inventory->removeStock($product, StockMovementType::Loss, '1', $userId, $label, $device, $origin);
            } elseif ($status === DeviceStatus::InStock) {
                $this->inventory->addStock($product, StockMovementType::AdjustmentIn, '1', $userId, $label, $device);
            }

            $device->update(['statut' => $status]);
            $this->log($device, match ($status) {
                DeviceStatus::Defective => 'defectueux',
                DeviceStatus::Lost => 'perdu',
                default => 'remis_en_stock',
            }, $reason, null, $userId);

            return $device;
        });
    }

    public function log(Device $device, string $event, ?string $description, ?Model $reference, ?int $userId): void
    {
        $device->events()->create([
            'evenement' => $event,
            'description' => $description,
            'reference_type' => $reference?->getMorphClass(),
            'reference_id' => $reference?->getKey(),
            'cree_par_id' => $userId,
        ]);
    }

    /**
     * IMEI (15 chiffres) ou numéro de série, reconnu automatiquement ; doublons refusés.
     *
     * @param  array<int, string>  $identifiers
     * @return Collection<int, Device>
     */
    private function createDevices(Product $product, array $identifiers, array $attributes): Collection
    {
        $clean = array_map(fn ($value) => strtoupper(preg_replace('/\s+/', '', (string) $value)), $identifiers);
        if (in_array('', $clean, true)) {
            throw new DeviceTrackingException('Un identifiant (IMEI ou n° de série) est vide.', 'APPAREILS_REQUIS');
        }
        if (count($clean) !== count(array_unique($clean))) {
            throw new DeviceTrackingException('Un même IMEI ou numéro de série est saisi deux fois.', 'APPAREIL_EXISTANT');
        }
        $existing = Device::query()->where(fn ($q) => $q->whereIn('imei', $clean)->orWhereIn('numero_serie', $clean))->first();
        if ($existing !== null) {
            throw new DeviceTrackingException("L’appareil {$existing->identifier()} est déjà enregistré.", 'APPAREIL_EXISTANT');
        }

        return collect($clean)->map(fn (string $id) => Device::create([
            ...$attributes,
            'produit_id' => $product->id,
            ...(self::isImei($id) ? ['imei' => $id] : ['numero_serie' => $id]),
            'statut' => DeviceStatus::InStock,
            'etat' => $product->etat,
        ]));
    }

    public static function isImei(string $identifier): bool
    {
        return (bool) preg_match('/^\d{15}$/', $identifier);
    }

    private function unitCount(Product $product, string $quantity): int
    {
        if (bccomp($quantity, (string) (int) $quantity, 3) !== 0) {
            throw new DeviceTrackingException("« {$product->nom} » est suivi à l’unité : la quantité doit être un nombre entier.", 'APPAREILS_REQUIS');
        }

        return (int) $quantity;
    }
}

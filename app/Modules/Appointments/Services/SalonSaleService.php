<?php

namespace App\Modules\Appointments\Services;

use App\Modules\Appointments\Exceptions\SalonException;
use App\Modules\Appointments\Models\Package;
use App\Modules\Appointments\Models\ServiceConsumption;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\Service;
use App\Modules\Catalog\Services\ServiceConsumableService;
use App\Modules\Customers\Models\Customer;
use App\Modules\Features\Services\FeatureGate;
use App\Modules\Sales\Models\Sale;
use App\Modules\Sales\Models\SaleItem;
use App\Modules\Tenancy\Models\Store;

/**
 * Ce que l'encaissement (SaleService) fait en plus pour un salon, chaque
 * point derrière sa feature : consommables des prestations, forfaits
 * vendus, commissions, points de fidélité — et leur annulation.
 */
class SalonSaleService
{
    public function __construct(
        private readonly FeatureGate $features,
        private readonly ServiceConsumableService $consumables,
        private readonly PackageService $packages,
        private readonly CommissionService $commissions,
        private readonly LoyaltyService $loyalty,
    ) {}

    /** @return array<int, string> produits consommés par la prestation (vide sans la feature) */
    public function consumables(Store $store, Service $service, string $quantity): array
    {
        return $this->features->allows($store, 'consommables') ? $this->consumables->consumption($service, $quantity) : [];
    }

    /**
     * Consommation des prestations de la vente : la prévue, ou la réelle
     * saisie (`[{produit_id, quantite}]`, qui la remplace entièrement).
     * Tracée dans consommations_prestations ; renvoie produit_id => quantité
     * à sortir du stock (une seule fois, avec la vente).
     *
     * @param  array<int, string>  $planned
     * @param  array<int, array{produit_id: int, quantite: string|float|int}>|null  $actual
     * @return array<int, string>
     */
    public function settleConsumption(Sale $sale, array $planned, ?array $actual): array
    {
        $final = $planned;
        if ($actual !== null) {
            $final = [];
            foreach ($actual as $line) {
                $final[(int) $line['produit_id']] = bcadd($final[(int) $line['produit_id']] ?? '0', (string) $line['quantite'], 3);
            }
        }
        foreach (array_keys($planned + $final) as $productId) {
            $quantity = $final[$productId] ?? '0';
            ServiceConsumption::create([
                'vente_id' => $sale->id,
                'produit_id' => $productId,
                'quantite_prevue' => $planned[$productId] ?? '0',
                'quantite' => $quantity,
                'cout_unitaire' => Product::withTrashed()->whereKey($productId)->value('prix_achat'),
                'ajustee' => bccomp($quantity, $planned[$productId] ?? '0', 3) !== 0,
            ]);
        }

        return array_filter($final, fn (string $q) => bccomp($q, '0', 3) > 0);
    }

    /**
     * Points utilisés en remise : [points, valeur].
     *
     * @return array{0: int, 1: string}
     */
    public function loyaltyDiscount(Store $store, array $data): array
    {
        $points = (int) ($data['points_utilises'] ?? 0);
        if ($points <= 0) {
            return [0, '0.00'];
        }
        if (! $this->features->allows($store, 'fidelite')) {
            throw new SalonException('La fidélité n’est pas activée pour cette boutique.', 'FIDELITE_INACTIVE');
        }
        $customer = empty($data['client_id']) ? null : Customer::query()->find($data['client_id']);
        if ($customer === null) {
            throw new SalonException('Choisissez le client qui utilise ses points.', 'CLIENT_REQUIS');
        }

        return [$points, $this->loyalty->discountFor($store, $customer, $points)];
    }

    public function packageFor(int $packageId): Package
    {
        $package = Package::query()->find($packageId);
        if ($package === null || ! $package->actif) {
            throw new SalonException('Ce forfait n’est plus proposé.', 'FORFAIT_INVALIDE');
        }

        return $package;
    }

    public function afterCheckout(Store $store, Sale $sale, array $data, ?int $userId, int $usedPoints, string $usedValue): void
    {
        foreach (SaleItem::query()->where('vente_id', $sale->id)->whereNotNull('forfait_id')->get() as $item) {
            $this->packages->sell($sale, Package::withTrashed()->findOrFail($item->forfait_id), $sale->client_id, (int) $item->quantite);
        }
        if ($this->features->allows($store, 'commissions')) {
            $this->commissions->recordForSale($sale);
        }
        if ($sale->client_id !== null && $this->features->allows($store, 'fidelite')) {
            $this->loyalty->recordForSale($store, $sale, Customer::query()->findOrFail($sale->client_id), $usedPoints, $usedValue, $userId);
        }
    }

    public function afterCancel(Sale $sale, ?int $userId): void
    {
        $this->packages->cancelForSale($sale);
        $this->commissions->reverseForSale($sale);
        $this->loyalty->reverseForSale($sale, $userId);
    }
}

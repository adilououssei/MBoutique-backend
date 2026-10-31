<?php

namespace App\Modules\Sales\Services;

use App\Modules\Appointments\Services\SalonSaleService;
use App\Modules\CashRegister\Exceptions\CashRegisterSessionClosedException;
use App\Modules\CashRegister\Models\CashRegister;
use App\Modules\CashRegister\Models\CashRegisterSession;
use App\Modules\CashRegister\Services\CashRegisterService;
use App\Modules\Catalog\Enums\PricingMode;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\Service;
use App\Modules\Catalog\Services\RecipeService;
use App\Modules\Conservation\Services\LotService;
use App\Modules\Customers\Exceptions\PrescriptionException;
use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Models\Prescription;
use App\Modules\Customers\Services\CustomerAccountService;
use App\Modules\Customers\Services\PrescriptionService;
use App\Modules\Devices\Services\DeviceService;
use App\Modules\Features\Services\FeatureGate;
use App\Modules\Inventory\Enums\StockMovementType;
use App\Modules\Inventory\Models\Stock;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Sales\Enums\PaymentMethod;
use App\Modules\Sales\Enums\SaleStatus;
use App\Modules\Sales\Exceptions\InvalidDepositException;
use App\Modules\Sales\Exceptions\InvalidDiscountException;
use App\Modules\Sales\Exceptions\NoOpenCashRegisterSessionException;
use App\Modules\Sales\Exceptions\PricingModeNotAvailableException;
use App\Modules\Sales\Exceptions\SaleAlreadyCancelledException;
use App\Modules\Sales\Models\Sale;
use App\Modules\Sales\Models\SaleItem;
use App\Modules\Sales\Support\Money;
use App\Modules\Tenancy\Models\Store;
use App\Modules\Warranties\Services\WarrantyService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The only place a Sale is ever created — see docs/sales.md
 * §"Checkout". Orchestrates InventoryService and CashRegisterService
 * inside one outer transaction; both are called through their own
 * public contract, never by writing to `stocks`/`cash_movements` directly
 * (Phase 4.3 brief §40).
 */
class SaleService
{
    public function __construct(
        private readonly InventoryService $inventory,
        private readonly CashRegisterService $cashRegisters,
        private readonly FeatureGate $features,
        private readonly CustomerAccountService $customerAccounts,
        private readonly DeviceService $devices,
        private readonly WarrantyService $warranties,
        private readonly RecipeService $recipes,
        private readonly PromotionService $promotions,
        private readonly LotService $lots,
        private readonly PrescriptionService $prescriptions,
        private readonly SalonSaleService $salon,
    ) {}

    /**
     * @param  array{
     *     lignes: array<int, array{produit_id?: int|null, service_id?: int|null, mode_prix?: string|null, quantite: string|float|int, remise?: string|float|int|null}>,
     *     caisse_id: int,
     *     client_id?: int|null,
     *     mode_paiement?: string,
     *     montant_remise?: string|float|int|null,
     *     acompte?: string|float|int|null,
     *     deja_encaisse?: string|null,
     *     cle_idempotence?: string|null,
     * }  $data
     *
     * `deja_encaisse` (usage interne, jamais exposé à l'API) : somme déjà
     * entrée en caisse avant la vente — l'acompte d'une commande (Orders).
     * La vente garde son total complet ; seul le reste entre en caisse.
     */
    public function checkout(Store $store, array $data, ?int $userId): Sale
    {
        $idempotencyKey = $data['cle_idempotence'] ?? null;

        if ($idempotencyKey !== null) {
            $existing = Sale::query()->where('cle_idempotence', $idempotencyKey)->first();
            if ($existing !== null) {
                return $existing;
            }
        }

        // A store without the `stock` feature (salon, atelier, « autre »…)
        // sells products without tracking quantities — docs/sales.md §23.
        $tracksStock = $this->features->allows($store, 'stock');
        // Restaurant : un plat avec recette fait sortir ses ingrédients, pas lui-même.
        $usesRecipes = $tracksStock && $this->features->allows($store, 'recettes');
        // Vêtements : promotions en cours appliquées automatiquement (sauf remise manuelle plus forte).
        $usesPromotions = $this->features->allows($store, 'promotions');
        // Pharmacie : lots contrôlés (périmé, bloqué, rappelé refusés ; lot enregistré) et vente à la boîte.
        $strictLots = $tracksStock && $this->features->allows($store, 'lots_stricts') && $this->features->allows($store, 'conservation');
        $packaging = $this->features->allows($store, 'conditionnement');
        $usesPrescriptions = $this->features->allows($store, 'ordonnances');
        $this->promotions->forget();

        return DB::transaction(function () use ($store, $data, $userId, $idempotencyKey, $tracksStock, $usesRecipes, $usesPromotions, $strictLots, $packaging, $usesPrescriptions) {
            // Re-check under the transaction to close the race between
            // the check above and this one — the (store_id,
            // idempotency_key) unique index is the real guarantee
            // (caught below), this closure just makes the common case fast.
            if ($idempotencyKey !== null) {
                $existing = Sale::query()->where('cle_idempotence', $idempotencyKey)->lockForUpdate()->first();
                if ($existing !== null) {
                    return $existing;
                }
            }

            // Client saisi à la volée (vente à crédit sans fiche préalable) :
            // créé, ou retrouvé par son téléphone.
            if (empty($data['client_id']) && filled($data['nouveau_client']['nom'] ?? null)) {
                $data['client_id'] = $this->customerFor($data['nouveau_client'])->id;
            }

            $register = CashRegister::query()->findOrFail($data['caisse_id']);
            if ($register->session_ouverte_id === null) {
                throw new NoOpenCashRegisterSessionException("La caisse \"{$register->nom}\" n'a pas de session ouverte.");
            }
            $session = CashRegisterSession::query()->findOrFail($register->session_ouverte_id);

            $lines = $this->priceLines($data['lignes'], $usesPromotions);
            $subtotal = $lines->reduce(fn (string $carry, array $line) => bcadd($carry, $line['montant_total'], 2), '0.00');

            // Salon : points de fidélité convertis en remise, ajoutée à la remise globale.
            [$usedPoints, $loyaltyValue] = $this->salon->loyaltyDiscount($store, $data);
            $discount = Money::round(bcadd((string) ($data['montant_remise'] ?? '0'), $loyaltyValue, 2));
            if (bccomp($discount, $subtotal, 2) > 0) {
                throw new InvalidDiscountException('La remise ne peut pas dépasser le sous-total.');
            }
            $total = bcsub($subtotal, $discount, 2);

            // Vente à crédit : seul l'acompte entre en caisse, le reste va au
            // compte client — docs/sales.md §24.
            $onCredit = ($data['mode_paiement'] ?? null) === PaymentMethod::Credit->value;
            $paidNow = $total;
            $alreadyCollected = Money::round((string) ($data['deja_encaisse'] ?? '0'));
            if (isset($data['avoir_echange'])) {
                $alreadyCollected = bccomp((string) $data['avoir_echange'], $total, 2) > 0 ? $total : Money::round((string) $data['avoir_echange']);
            }
            if (bccomp($alreadyCollected, '0', 2) > 0) {
                if (bccomp($alreadyCollected, $total, 2) > 0) {
                    throw new InvalidDepositException("L'acompte déjà versé dépasse le total à encaisser.");
                }
                $paidNow = bcsub($total, $alreadyCollected, 2);
            }
            if ($onCredit) {
                $paidNow = Money::round((string) ($data['acompte'] ?? '0'));
                if (bccomp($paidNow, $total, 2) > 0) {
                    throw new InvalidDepositException("L'acompte ne peut pas dépasser le total de la vente.");
                }
            }

            // Seules les espèces (ou l'acompte d'une vente à crédit) entrent dans le tiroir.
            $method = PaymentMethod::from($data['mode_paiement'] ?? PaymentMethod::Cash->value);
            $cashIn = $method->collectsCash() ? $paidNow : '0.00';

            $sale = $this->createSale($register, $session, $data, $userId, $subtotal, $discount, $total, $idempotencyKey, $onCredit ? $paidNow : null, bcadd($cashIn, $method->collectsCash() ? $alreadyCollected : '0', 2));
            if ($usedPoints > 0) {
                $sale->update(['points_utilises' => $usedPoints, 'remise_fidelite' => $loyaltyValue]);
            }

            $consumed = [];
            $serviceConsumed = [];
            foreach ($lines as $line) {
                $saleItem = SaleItem::create([
                    'vente_id' => $sale->id,
                    'produit_id' => $line['product']?->id,
                    'service_id' => $line['service']?->id,
                    'employe_id' => $line['employe_id'],
                    'forfait_id' => $line['package']?->id,
                    'nom_produit' => $line['nom'],
                    'mode_prix' => $line['mode'],
                    'prix_unitaire' => $line['prix_unitaire'],
                    'quantite' => $line['quantite'],
                    'montant_remise' => $line['montant_remise'],
                    'promotion_id' => $line['promotion_id'] ?? null,
                    'montant_total' => $line['montant_total'],
                ]);

                // A service line moves no stock. Product lines are sorted by
                // product_id in priceLines() — a consistent lock order across
                // every checkout, so two concurrent carts sharing products can
                // never deadlock on Stock rows. See docs/sales.md §"Verrouillage".
                $ingredients = $line['product'] !== null && $usesRecipes ? $this->recipes->consumption($line['product'], (string) $line['quantite']) : [];
                // Salon : produits consommés par la prestation (sortis une seule fois, ici).
                // `sans_consommation_prestations` (interne) : déjà consommé en amont (pressing, au passage « prête »).
                if ($line['service'] !== null && $tracksStock && empty($data['sans_consommation_prestations'])) {
                    foreach ($this->salon->consumables($store, $line['service'], (string) $line['quantite']) as $productId => $quantity) {
                        $serviceConsumed[$productId] = bcadd($serviceConsumed[$productId] ?? '0', $quantity, 3);
                    }
                }
                foreach ($ingredients as $productId => $quantity) {
                    $consumed[$productId] = bcadd($consumed[$productId] ?? '0', $quantity, 3);
                }
                if ($line['product'] !== null && $tracksStock && $ingredients === []) {
                    $stockQuantity = $packaging ? $line['product']->stockQuantityFor($line['mode'], $line['quantite']) : $line['quantite'];
                    if ($strictLots) {
                        $current = (string) (Stock::query()->where('produit_id', $line['product']->id)->value('quantite') ?? '0');
                        $this->lots->assertSellable($line['product'], $stockQuantity, $line['lot_id'], $current);
                        $this->lots->prefer($line['product']->id, $line['lot_id'], true);
                    }
                    $this->inventory->removeStock($line['product'], StockMovementType::Sale, $stockQuantity, $userId, null, $sale);
                    if ($strictLots) {
                        foreach ($this->lots->takeConsumption() as [$lotId, $taken]) {
                            DB::table('lots_vente')->insert(['boutique_id' => $sale->boutique_id, 'ligne_vente_id' => $saleItem->id, 'lot_id' => $lotId, 'quantite' => $taken, 'created_at' => now(), 'updated_at' => now()]);
                        }
                    }
                }

                // Pharmacie : produit sur ordonnance → délivrance contrôlée et tracée sur l'ordonnance liée.
                if ($line['product'] !== null && $usesPrescriptions) {
                    $prescription = filled($data['ordonnance_id'] ?? null) ? Prescription::query()->lockForUpdate()->findOrFail($data['ordonnance_id']) : null;
                    if ($prescription !== null && in_array($prescription->statut, ['delivree', 'annulee'], true) && $line['product']->sur_ordonnance) {
                        throw new PrescriptionException("L’ordonnance {$prescription->numero} est déjà entièrement délivrée ou annulée.", 'ORDONNANCE_CLOSE');
                    }
                    $this->prescriptions->deliver($prescription, $line['product'], $line['quantite'], $sale->id, $saleItem->id, $userId);
                }

                // Appareil suivi à l'unité (IMEI / n° de série) : les exemplaires précis vendus.
                if ($line['product'] !== null && $this->devices->tracks($line['product'])) {
                    $this->devices->sell($line['product'], $line['quantite'], $line['appareil_ids'], $sale, $saleItem, $data['client_id'] ?? null, $userId);
                }
            }

            // Salon : consommation des prestations (prévue, ou réelle saisie à l'encaissement), tracée une fois.
            if ($tracksStock) {
                foreach ($this->salon->settleConsumption($sale, $serviceConsumed, $data['consommations_reelles'] ?? null) as $productId => $quantity) {
                    $consumed[$productId] = bcadd($consumed[$productId] ?? '0', $quantity, 3);
                }
            }

            // Ingrédients des recettes, par produit_id croissant (même ordre de verrous).
            // Un ingrédient dont le stock n'est pas suivi est ignoré.
            ksort($consumed);
            foreach ($consumed as $productId => $quantity) {
                $ingredient = Product::query()->find($productId);
                if ($ingredient !== null && Stock::query()->where('produit_id', $productId)->exists()) {
                    $this->inventory->removeStock($ingredient, StockMovementType::Sale, $quantity, $userId, "Recette / consommable (vente {$sale->reference})", $sale);
                }
            }

            // Garanties des produits qui en offrent une (après les appareils : une par IMEI).
            $this->warranties->createForSale($sale);

            // Salon : forfaits vendus, commissions, points de fidélité.
            $this->salon->afterCheckout($store, $sale, $data, $userId, $usedPoints, $loyaltyValue);

            // Lock order: Inventory (above), then customer, then CashRegister.
            $owed = bcsub(bcsub($total, $paidNow, 2), $alreadyCollected, 2);
            if (bccomp($owed, '0', 2) > 0) {
                $customer = Customer::query()->findOrFail($data['client_id']);
                $this->customerAccounts->recordCreditSale($customer, $owed, $userId, $sale);
            }

            try {
                if ($method->collectsCash() && (bccomp($paidNow, '0', 2) > 0 || (! $onCredit && bccomp($alreadyCollected, '0', 2) === 0))) {
                    $this->cashRegisters->recordSale($session, $paidNow, $userId, $sale);
                }
            } catch (CashRegisterSessionClosedException $e) {
                // The session closed between our check above and here
                // (another request raced closeSession()) — surfaced as
                // the same business error a missing-session checkout gets.
                throw new NoOpenCashRegisterSessionException($e->getMessage(), previous: $e);
            }

            return $sale;
        });
    }

    /**
     * Cancels a completed sale as a whole: every product line goes back to
     * stock (ReturnIn) and the sale total is paid out of an open cash
     * session (Refund). One transaction — either everything is reversed or
     * nothing is. The original sale row is kept (status `annulee`), never
     * deleted: the receipt history stays intact. See docs/sales.md §20.
     *
     * @param  array{motif: string, caisse_id?: int|null}  $data
     */
    public function cancel(Sale $sale, array $data, ?int $userId): Sale
    {
        return DB::transaction(function () use ($sale, $data, $userId) {
            $sale = Sale::query()->whereKey($sale->id)->lockForUpdate()->firstOrFail();

            if ($sale->isCancelled()) {
                throw new SaleAlreadyCancelledException("La vente {$sale->reference} est déjà annulée.");
            }

            // Refund from the requested register, else from the sale's own —
            // either way it must have an open session right now.
            $register = CashRegister::query()->findOrFail($data['caisse_id'] ?? $sale->caisse_id);
            if ($register->session_ouverte_id === null) {
                throw new NoOpenCashRegisterSessionException(
                    "La caisse \"{$register->nom}\" n'a pas de session ouverte : ouvrez-la ou choisissez une autre caisse pour rembourser."
                );
            }
            $session = CashRegisterSession::query()->findOrFail($register->session_ouverte_id);
            $reason = "Annulation de la vente {$sale->reference} : {$data['motif']}";

            // Same lock order as checkout(): Inventory first (products sorted
            // by id), CashRegister last. withTrashed(): a product deleted
            // since the sale still gets its units back.
            // Exactly what this sale took out goes back, product by product:
            // the sold items, or the ingredients of their recipes (nothing if
            // the store didn't track stock at sale time).
            $removed = StockMovement::query()
                ->where('reference_type', $sale->getMorphClass())->where('reference_id', $sale->id)
                ->selectRaw('produit_id, SUM(quantite) as total')->groupBy('produit_id')->orderBy('produit_id')->get();
            $products = Product::withTrashed()->whereIn('id', $removed->pluck('produit_id'))->get()->keyBy('id');
            foreach ($removed as $row) {
                $quantity = bcmul((string) $row->total, '-1', 3);
                if (bccomp($quantity, '0', 3) > 0) {
                    $this->inventory->addStock($products[$row->produit_id], StockMovementType::ReturnIn, $quantity, $userId, $reason, $sale);
                }
            }

            $this->devices->releaseFromSale($sale, $userId);
            $this->prescriptions->reverseForSale($sale->id);
            $this->warranties->cancelForSale($sale);
            $this->salon->afterCancel($sale, $userId);

            // Vente à crédit : la part due sort du compte client, seul
            // l'acompte (ce qui est entré en caisse) est remboursé.
            $paidNow = (string) ($sale->montant_acompte ?? $sale->montant_total);
            $owed = bcsub((string) $sale->montant_total, $paidNow, 2);
            if (bccomp($owed, '0', 2) > 0) {
                $customer = Customer::withTrashed()->findOrFail($sale->client_id);
                $this->customerAccounts->reverseCreditSale($customer, $owed, $userId, $sale, $reason);
            }

            // Ne sort de la caisse que ce qui y est entré (rien pour Mobile Money / carte / virement).
            $cashRefund = $sale->montant_especes !== null ? (string) $sale->montant_especes : $paidNow;
            if (bccomp($cashRefund, '0', 2) > 0) {
                try {
                    $this->cashRegisters->recordRefund($session, $cashRefund, $userId, $sale, $reason);
                } catch (CashRegisterSessionClosedException $e) {
                    throw new NoOpenCashRegisterSessionException($e->getMessage(), previous: $e);
                }
            }

            $sale->update([
                'statut' => SaleStatus::Cancelled,
                'annulee_le' => now(),
                'annulee_par_id' => $userId,
                'motif_annulation' => $data['motif'],
                'session_remboursement_id' => $session->id,
            ]);

            return $sale;
        });
    }

    /** @param  array{nom: string, telephone?: string|null}  $info */
    private function customerFor(array $info): Customer
    {
        $phone = filled($info['telephone'] ?? null) ? trim((string) $info['telephone']) : null;
        if ($phone !== null && ($existing = Customer::query()->where('telephone', $phone)->first())) {
            return $existing;
        }

        return Customer::create(['nom' => trim((string) $info['nom']), 'telephone' => $phone]);
    }

    /**
     * Prices every line server-side (never trusting a client price) and
     * applies the per-line discount. Product lines come first, sorted by
     * product_id (lock order, see checkout()); service lines follow.
     *
     * @return Collection<int, array{product: ?Product, service: ?Service, nom: string, mode: ?PricingMode, prix_unitaire: string, quantite: string, montant_remise: string, montant_total: string}>
     */
    private function priceLines(array $items, bool $usesPromotions = false): Collection
    {
        $items = collect($items);
        $products = Product::query()->whereIn('id', $items->pluck('produit_id')->filter()->unique())->get()->keyBy('id');
        $services = Service::query()->whereIn('id', $items->pluck('service_id')->filter()->unique())->get()->keyBy('id');

        return $items
            ->sortBy(fn (array $item) => filled($item['produit_id'] ?? null) ? [0, (int) $item['produit_id']] : (filled($item['service_id'] ?? null) ? [1, (int) $item['service_id']] : [2, (int) ($item['forfait_id'] ?? 0)]))
            ->values()
            ->map(function (array $item) use ($products, $services, $usesPromotions) {
                $product = null;
                $service = null;
                $package = null;
                $mode = null;

                if (filled($item['produit_id'] ?? null)) {
                    /** @var Product $product */
                    $product = $products->get($item['produit_id']) ?? throw new InvalidArgumentException("Produit #{$item['produit_id']} introuvable.");
                    $mode = PricingMode::from($item['mode_prix']);

                    try {
                        $unitPrice = $product->priceFor($mode);
                    } catch (InvalidArgumentException) {
                        throw PricingModeNotAvailableException::forProduct($product, $mode);
                    }
                } elseif (filled($item['forfait_id'] ?? null)) {
                    // Salon : vente d'un forfait (séances prépayées).
                    $package = $this->salon->packageFor((int) $item['forfait_id']);
                    $unitPrice = Money::round((string) $package->prix);
                } else {
                    /** @var Service $service */
                    $service = $services->get($item['service_id']) ?? throw new InvalidArgumentException("Service #{$item['service_id']} introuvable.");
                    // `prix_impose` (usage interne, jamais exposé à l'API) : prix fixé en amont,
                    // ex. grille par type d'article ou tarif au kilo d'un dépôt de pressing.
                    $unitPrice = Money::round((string) ($item['prix_impose'] ?? $service->prix));
                }

                $quantity = (string) $item['quantite'];
                $gross = Money::round(bcmul($unitPrice, $quantity, 6));
                $lineDiscount = Money::round((string) ($item['remise'] ?? '0'));
                $name = $product?->nom ?? $service?->nom ?? "Forfait {$package->nom}";
                $promotionId = null;
                if ($usesPromotions && $product !== null && ($promo = $this->promotions->bestFor($product, $unitPrice)) !== null) {
                    $promoDiscount = Money::round(bcmul($promo['remise_unitaire'], $quantity, 6));
                    if (bccomp($promoDiscount, $lineDiscount, 2) > 0) {
                        $lineDiscount = $promoDiscount;
                        $promotionId = $promo['promotion']->id;
                    }
                }

                if (bccomp($lineDiscount, $gross, 2) > 0) {
                    throw new InvalidDiscountException("La remise sur \"{$name}\" dépasse le montant de la ligne.");
                }

                return [
                    'product' => $product,
                    'service' => $service,
                    'package' => $package,
                    'employe_id' => isset($item['employe_id']) ? (int) $item['employe_id'] : null,
                    'nom' => $name,
                    'mode' => $mode,
                    'prix_unitaire' => $unitPrice,
                    'quantite' => $quantity,
                    'montant_remise' => $lineDiscount,
                    'promotion_id' => $promotionId,
                    'montant_total' => bcsub($gross, $lineDiscount, 2),
                    'appareil_ids' => (array) ($item['appareil_ids'] ?? []),
                    'lot_id' => isset($item['lot_id']) ? (int) $item['lot_id'] : null,
                ];
            });
    }

    private function createSale(
        CashRegister $register,
        CashRegisterSession $session,
        array $data,
        ?int $userId,
        string $subtotal,
        string $discount,
        string $total,
        ?string $idempotencyKey,
        ?string $deposit = null,
        ?string $cash = null,
    ): Sale {
        $attributes = [
            'caisse_id' => $register->id,
            'session_caisse_id' => $session->id,
            'client_id' => $data['client_id'] ?? null,
            'vendeur_id' => $userId,
            'sous_total' => $subtotal,
            'montant_remise' => $discount,
            'montant_total' => $total,
            'montant_acompte' => $deposit,
            'montant_especes' => $cash,
            'statut' => SaleStatus::Completed,
            'mode_paiement' => PaymentMethod::from($data['mode_paiement'] ?? PaymentMethod::Cash->value),
            'cle_idempotence' => $idempotencyKey,
            'vendue_le' => now(),
        ];

        try {
            $sale = Sale::create($attributes);
        } catch (QueryException $e) {
            if ($idempotencyKey !== null && $this->isUniqueConstraintViolation($e)) {
                // Lost a race on the idempotency key to a concurrent
                // request — return what it created instead of failing.
                return Sale::query()->where('cle_idempotence', $idempotencyKey)->firstOrFail();
            }
            throw $e;
        }

        // reference needs the row's own id, so it's filled in right
        // after insert, inside the same transaction — see docs/sales.md §"Référence".
        $sale->update(['reference' => sprintf('VTE-%s-%06d', now()->format('Ymd'), $sale->id)]);

        return $sale;
    }

    private function isUniqueConstraintViolation(QueryException $e): bool
    {
        return ($e->errorInfo[0] ?? null) === '23000';
    }
}

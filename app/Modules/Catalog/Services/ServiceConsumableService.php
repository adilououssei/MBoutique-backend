<?php

namespace App\Modules\Catalog\Services;

use App\Modules\Catalog\Models\Service;
use App\Modules\Catalog\Models\ServiceConsumable;
use App\Modules\Sales\Support\Money;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Consommables des prestations (salon) : même principe que les recettes du
 * restaurant, appliqué à un service. SaleService ajoute la consommation aux
 * ingrédients sortis du stock à l'encaissement (référencés sur la vente :
 * l'annulation les remet automatiquement).
 */
class ServiceConsumableService
{
    /** @param  array<int, array{produit_id: int, quantite: string|float|int}>  $lines */
    public function save(Service $service, array $lines): void
    {
        DB::transaction(function () use ($service, $lines) {
            $ids = array_map(fn (array $line) => (int) $line['produit_id'], $lines);
            if (count($ids) !== count(array_unique($ids))) {
                throw new InvalidArgumentException('Un même produit apparaît deux fois dans les consommables.');
            }
            ServiceConsumable::query()->where('service_id', $service->id)->delete();
            foreach ($lines as $line) {
                ServiceConsumable::create(['service_id' => $service->id, 'produit_id' => $line['produit_id'], 'quantite' => (string) $line['quantite']]);
            }
        });
    }

    /**
     * Produits consommés pour `quantity` prestations : produit_id => quantité.
     *
     * @return array<int, string>
     */
    public function consumption(Service $service, string $quantity): array
    {
        $needs = [];
        foreach (ServiceConsumable::query()->where('service_id', $service->id)->get() as $line) {
            $needs[$line->produit_id] = bcmul((string) $line->quantite, $quantity, 3);
        }
        // Formule sans consommables propres : ceux de ses composants.
        if ($needs === [] && $service->est_formule) {
            foreach ($service->components()->get() as $component) {
                foreach ($this->consumption($component, $quantity) as $productId => $qty) {
                    $needs[$productId] = bcadd($needs[$productId] ?? '0', $qty, 3);
                }
            }
        }

        return $needs;
    }

    /** Coût des consommables d'une prestation (prix d'achat × quantité) ; null si un produit n'a pas de coût. */
    public function cost(Service $service): ?string
    {
        $total = '0';
        $lines = ServiceConsumable::query()->with('product')->where('service_id', $service->id)->get();
        if ($lines->isEmpty() && $service->est_formule) {
            foreach ($service->components()->get() as $component) {
                $part = $this->cost($component);
                if ($part === null) {
                    return null;
                }
                $total = bcadd($total, $part, 4);
            }

            return Money::round($total);
        }
        foreach ($lines as $line) {
            if ($line->product?->prix_achat === null) {
                return null;
            }
            $total = bcadd($total, bcmul((string) $line->product->prix_achat, (string) $line->quantite, 4), 4);
        }

        return Money::round($total);
    }
}

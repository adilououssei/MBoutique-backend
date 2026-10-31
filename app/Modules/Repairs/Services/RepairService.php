<?php

namespace App\Modules\Repairs\Services;

use App\Modules\CashRegister\Exceptions\CashRegisterSessionClosedException;
use App\Modules\CashRegister\Models\CashRegister;
use App\Modules\CashRegister\Models\CashRegisterSession;
use App\Modules\CashRegister\Services\CashRegisterService;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\Service;
use App\Modules\Customers\Models\Customer;
use App\Modules\Customers\Services\CustomerAccountService;
use App\Modules\Inventory\Enums\StockMovementType;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Repairs\Exceptions\RepairException;
use App\Modules\Repairs\Models\Repair;
use App\Modules\Repairs\Models\RepairLine;
use App\Modules\Repairs\Models\RepairPart;
use App\Modules\Sales\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * Seul point d'écriture des réparations — docs/modules.md §Repairs. Les
 * pièces sortent du stock (mouvement `reparation`) ; les paiements en
 * espèces entrent dans la caisse, les autres non.
 */
class RepairService
{
    public function __construct(
        private readonly InventoryService $inventory,
        private readonly CashRegisterService $cashRegisters,
        private readonly CustomerAccountService $customerAccounts,
    ) {}

    public function open(array $data, ?int $userId): Repair
    {
        return DB::transaction(function () use ($data, $userId) {
            if (empty($data['client_id']) && filled($data['nouveau_client']['nom'] ?? null)) {
                $phone = filled($data['nouveau_client']['telephone'] ?? null) ? trim($data['nouveau_client']['telephone']) : null;
                $customer = ($phone !== null ? Customer::query()->where('telephone', $phone)->first() : null)
                    ?? Customer::create(['nom' => trim($data['nouveau_client']['nom']), 'telephone' => $phone]);
                $data['client_id'] = $customer->id;
            }
            $technicians = $data['techniciens'] ?? null;
            $lines = $data['prestations'] ?? [];
            ksort($lines);
            unset($data['nouveau_client'], $data['techniciens'], $data['prestations']);
            $repair = Repair::create([...$data, 'statut' => 'recu', 'depose_le' => now(), 'montant_paye' => '0.00', 'cree_par_id' => $userId]);
            $repair->update(['numero' => sprintf('REP-%s-%05d', now()->format('Ymd'), $repair->id)]);
            $repair->followUps()->create(['statut' => 'recu', 'note' => $data['panne'], 'cree_par_id' => $userId]);
            $this->syncTechnicians($repair, $technicians);
            foreach ($lines as $line) {
                $this->createLine($repair, $line);
            }

            return $repair;
        });
    }

    public function update(Repair $repair, array $data): Repair
    {
        $this->assertOpen($repair);
        $technicians = $data['techniciens'] ?? null;
        unset($data['techniciens']);
        $repair->update($data);
        $this->syncTechnicians($repair, $technicians);

        return $repair;
    }

    /**
     * Ligne facturée : prestation du catalogue (prix standard, ajustable) ou
     * libre (main-d'œuvre, frais, remise négative).
     *
     * @param  array{type?: string|null, service_id?: int|null, libelle?: string|null, quantite?: string|float|int|null, prix_unitaire?: string|float|int|null}  $data
     */
    public function addLine(Repair $repair, array $data): Repair
    {
        return DB::transaction(function () use ($repair, $data) {
            $this->assertOpen($repair);
            $this->createLine($repair, $data);

            return $repair;
        });
    }

    public function removeLine(Repair $repair, RepairLine $line): Repair
    {
        $this->assertOpen($repair);
        $line->delete();

        return $repair;
    }

    /** @param  array<string, mixed>  $data */
    private function createLine(Repair $repair, array $data): RepairLine
    {
        $service = filled($data['service_id'] ?? null) ? Service::query()->findOrFail($data['service_id']) : null;
        $label = $data['libelle'] ?? $service?->nom;
        if (blank($label)) {
            throw new RepairException('Indiquez la prestation ou son libellé.', 'LIGNE_INVALIDE');
        }
        $quantity = (string) ($data['quantite'] ?? '1');
        $price = Money::round((string) ($data['prix_unitaire'] ?? $service?->prix ?? '0'));

        return $repair->services()->create([
            'type' => $data['type'] ?? ($service ? 'prestation' : 'main_oeuvre'),
            'service_id' => $service?->id,
            'libelle' => $label,
            'quantite' => $quantity,
            'prix_unitaire' => $price,
            'montant' => Money::round(bcmul($price, $quantity, 6)),
        ]);
    }

    /** @param  array<int, int>|null  $employeeIds */
    private function syncTechnicians(Repair $repair, ?array $employeeIds): void
    {
        if ($employeeIds === null) {
            return;
        }
        $repair->syncTechnicians($employeeIds);
        // Le premier devient le technicien principal s'il n'y en a pas.
        if ($repair->technicien_id === null && $employeeIds !== []) {
            $repair->update(['technicien_id' => $employeeIds[0]]);
        }
    }

    /**
     * Étape suivante ; annuler exige qu'aucun paiement n'ait été reçu. Livrer
     * est distinct du paiement : un reste dû bloque, sauf `laisser_a_credit`
     * (client enregistré) — il passe alors au compte du client. On note qui a
     * remis le travail, à qui, et la confirmation de réception.
     *
     * @param  array{laisser_a_credit?: bool, remis_a?: string|null, reception_confirmee?: bool}  $delivery
     */
    public function changeStatus(Repair $repair, string $status, ?string $note, ?int $userId, array $delivery = []): Repair
    {
        return DB::transaction(function () use ($repair, $status, $note, $userId, $delivery) {
            $repair = Repair::query()->whereKey($repair->id)->lockForUpdate()->firstOrFail();
            $this->assertOpen($repair);
            if ($status === 'livre') {
                $remaining = $repair->remaining();
                if (bccomp($remaining, '0', 2) > 0) {
                    if (! ($delivery['laisser_a_credit'] ?? false)) {
                        throw new RepairException("Il reste {$remaining} FCFA à payer avant de rendre le travail (ou laissez-le au compte du client).", 'PAIEMENT_INCOMPLET');
                    }
                    if ($repair->client_id === null) {
                        throw new RepairException('Un reste dû ne peut aller qu’au compte d’un client enregistré.', 'CLIENT_REQUIS');
                    }
                    $customer = Customer::withTrashed()->findOrFail($repair->client_id);
                    $this->customerAccounts->recordCreditSale($customer, $remaining, $userId, $repair, "Reste du dossier {$repair->numero}");
                    $repair->montant_credit = bcadd((string) $repair->montant_credit, $remaining, 2);
                }
                $repair->fill(['livre_par_id' => $userId, 'remis_a' => $delivery['remis_a'] ?? null, 'reception_confirmee' => (bool) ($delivery['reception_confirmee'] ?? false)]);
            }
            if ($status === 'annule' && bccomp((string) $repair->montant_paye, '0', 2) > 0) {
                throw new RepairException('Des paiements ont été reçus : remboursez-les avant d’annuler.', 'REPARATION_PAYEE');
            }
            $repair->update(['statut' => $status, 'livre_le' => $status === 'livre' ? now() : $repair->livre_le]);
            $repair->followUps()->create(['statut' => $status, 'note' => $note, 'cree_par_id' => $userId]);

            return $repair;
        });
    }

    /**
     * Pièce ou matériau utilisé : sort du stock (mouvement lié au dossier, donc
     * jamais déduit deux fois). `facturee=false` : déjà compris dans le prix
     * (devis, forfait) — seul son coût est suivi.
     */
    public function addPart(Repair $repair, Product $product, string $quantity, ?string $unitPrice, ?int $userId, bool $billed = true): RepairPart
    {
        return DB::transaction(function () use ($repair, $product, $quantity, $unitPrice, $userId, $billed) {
            $this->assertOpen($repair);
            $this->inventory->removeStock($product, StockMovementType::Repair, $quantity, $userId, "Dossier {$repair->numero}", $repair);
            $price = Money::round((string) ($unitPrice ?? $product->prix_detail ?? '0'));

            return $repair->parts()->create(['produit_id' => $product->id, 'nom_produit' => $product->nom, 'quantite' => $quantity, 'prix_unitaire' => $price, 'montant' => Money::round(bcmul($price, $quantity, 6)), 'facturee' => $billed]);
        });
    }

    /** Pièce retirée (non utilisée) : retour en stock. */
    public function removePart(Repair $repair, RepairPart $part, ?int $userId): void
    {
        DB::transaction(function () use ($repair, $part, $userId) {
            $this->assertOpen($repair);
            $product = Product::withTrashed()->findOrFail($part->produit_id);
            $this->inventory->addStock($product, StockMovementType::ReturnIn, (string) $part->quantite, $userId, "Pièce retirée de la réparation {$repair->numero}", $repair);
            $part->delete();
        });
    }

    /** Acompte ou solde : jamais au-delà du montant dû quand il est connu. */
    public function pay(Repair $repair, string $amount, string $mode, ?int $registerId, ?int $userId): Repair
    {
        return DB::transaction(function () use ($repair, $amount, $mode, $registerId, $userId) {
            $repair = Repair::query()->whereKey($repair->id)->lockForUpdate()->firstOrFail();
            $this->assertOpen($repair);
            $amount = Money::round($amount);
            if (bccomp($repair->amountDue(), '0', 2) > 0 && bccomp($amount, $repair->remaining(), 2) > 0) {
                throw new RepairException("Le paiement dépasse le reste à payer ({$repair->remaining()} FCFA).", 'PAIEMENT_INVALIDE');
            }

            $sessionId = null;
            if ($mode === 'especes') {
                $register = CashRegister::query()->findOrFail($registerId);
                if ($register->session_ouverte_id === null) {
                    throw new RepairException("La caisse « {$register->nom} » n’a pas de session ouverte.", 'AUCUNE_SESSION_CAISSE_OUVERTE');
                }
                $session = CashRegisterSession::query()->findOrFail($register->session_ouverte_id);
                try {
                    $this->cashRegisters->recordReceipt($session, $amount, $userId, $repair, "Réparation {$repair->numero}");
                } catch (CashRegisterSessionClosedException $e) {
                    throw new RepairException($e->getMessage(), 'AUCUNE_SESSION_CAISSE_OUVERTE');
                }
                $sessionId = $session->id;
            }
            $repair->payments()->create(['montant' => $amount, 'mode' => $mode, 'session_caisse_id' => $sessionId, 'cree_par_id' => $userId]);
            $repair->update(['montant_paye' => bcadd((string) $repair->montant_paye, $amount, 2)]);

            return $repair;
        });
    }

    private function assertOpen(Repair $repair): void
    {
        if (! $repair->isOpen()) {
            throw new RepairException("La réparation {$repair->numero} est close.", 'REPARATION_CLOSE');
        }
    }
}

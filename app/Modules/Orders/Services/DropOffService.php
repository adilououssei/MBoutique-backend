<?php

namespace App\Modules\Orders\Services;

use App\Modules\Orders\Enums\OrderStatus;
use App\Modules\Orders\Exceptions\OrderClosedException;
use App\Modules\Orders\Exceptions\OrderLineException;
use App\Modules\Orders\Models\DropOffItem;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderHandover;
use App\Modules\Tenancy\Models\Store;
use Illuminate\Support\Facades\DB;

/**
 * Pressing : remise des pièces au client (totale ou partielle) et suivi
 * pièce par pièce. Le paiement reste celui des commandes (acompte →
 * caisse, règlement → vente) ; la remise ne fait que le constater ou
 * l'exiger, selon le réglage « solde avant remise » de la boutique.
 */
class DropOffService
{
    public function __construct(
        private readonly OrderService $orders,
        private readonly DropOffTracker $tracker,
    ) {}

    /** Réglage de la boutique : le solde doit-il être réglé avant la dernière remise ? (oui par défaut) */
    public static function balanceRequiredBeforeHandover(Store $store): bool
    {
        return (bool) ($store->parametres['pressing']['solde_avant_remise'] ?? true);
    }

    /**
     * @param  array{article_ids?: array<int, int>|null, mode?: string|null, note?: string|null, paiement?: array{montant: string|float|int, caisse_id: int, mode_paiement?: string|null}|null, differer_paiement?: bool, caisse_id?: int|null}  $data
     */
    public function handover(Store $store, Order $order, array $data, ?int $userId, bool $mayDefer): Order
    {
        return DB::transaction(function () use ($store, $order, $data, $userId, $mayDefer) {
            $order = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            if (! $order->statut->isOpen()) {
                throw new OrderClosedException("La commande {$order->reference} est déjà close ou annulée.");
            }
            $open = DropOffItem::query()->where('commande_id', $order->id)->whereNotIn('statut', ['remis', 'annule'])->get();
            $selected = empty($data['article_ids']) ? $open : $open->whereIn('id', $data['article_ids']);
            if ($selected->isEmpty()) {
                throw new OrderLineException('Aucune pièce à remettre (déjà remises ou introuvables).', 'AUCUN_ARTICLE');
            }
            $notReady = $selected->where('statut', '!=', 'pret');
            if ($notReady->isNotEmpty()) {
                throw new OrderLineException('Pièce(s) pas encore prête(s) : '.$notReady->pluck('code')->implode(', ').'.', 'ARTICLE_NON_PRET');
            }
            $final = $selected->count() === $open->count();

            // Paiement reçu à la remise : solde complet → règlement (vente) ; sinon acompte.
            $paid = (string) ($data['paiement']['montant'] ?? '0');
            if (bccomp($paid, '0', 2) > 0) {
                if ($order->vente_id !== null) {
                    throw new OrderLineException('Cette commande est déjà réglée : un reste éventuel se paie sur le compte du client.', 'COMMANDE_REGLEE');
                }
                $balance = $this->tracker->paymentSummary($order)['solde'];
                if ($final && bccomp($paid, $balance, 2) >= 0) {
                    $order = $this->orders->checkout($store, $order, ['caisse_id' => $data['paiement']['caisse_id'], 'mode_paiement' => $data['paiement']['mode_paiement'] ?? 'especes', 'client_id' => $order->client_id], $userId);
                } else {
                    $order = $this->orders->recordDeposit($order, ['montant' => $paid, 'caisse_id' => $data['paiement']['caisse_id']], $userId);
                }
                $order = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            }

            // Dernière remise : la commande doit être réglée, ou le reste mis sur le compte du client.
            if ($final && $order->vente_id === null) {
                $summary = $this->tracker->paymentSummary($order);
                $registerId = $data['paiement']['caisse_id'] ?? $data['caisse_id'] ?? null;
                if (bccomp($summary['solde'], '0', 2) <= 0) {
                    $this->requireRegister($registerId);
                    $order = $this->orders->checkout($store, $order, ['caisse_id' => $registerId, 'mode_paiement' => 'especes', 'client_id' => $order->client_id], $userId);
                } elseif (($data['differer_paiement'] ?? false) || ! self::balanceRequiredBeforeHandover($store)) {
                    if (! $mayDefer) {
                        throw new OrderLineException("Solde de {$summary['solde']} à régler avant la remise : vous n’êtes pas autorisé à le reporter.", 'PAIEMENT_DIFFERE_INTERDIT');
                    }
                    if ($order->client_id === null) {
                        throw new OrderLineException('Pour remettre sans tout régler, la commande doit avoir un client enregistré (le reste va sur son compte).', 'CLIENT_REQUIS');
                    }
                    $this->requireRegister($registerId);
                    $order = $this->orders->checkout($store, $order, ['caisse_id' => $registerId, 'mode_paiement' => 'credit', 'acompte' => '0', 'client_id' => $order->client_id], $userId);
                } else {
                    throw new OrderLineException("Solde de {$summary['solde']} à régler avant de remettre les dernières pièces.", 'SOLDE_A_REGLER');
                }
                $order = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            }

            $handover = OrderHandover::create([
                'commande_id' => $order->id,
                'mode' => $data['mode'] ?? 'retrait',
                'nombre_articles' => $selected->count(),
                'montant_paye' => $paid,
                'note' => $data['note'] ?? null,
                'remis_par_id' => $userId,
                'remis_le' => now(),
            ]);
            DropOffItem::query()->whereIn('id', $selected->pluck('id'))->update(['statut' => 'remis', 'remise_id' => $handover->id, 'remis_le' => now()]);
            foreach ($selected as $article) {
                $this->tracker->log($order, 'remise', $article->statut, 'remis', null, $userId, $article->id);
            }

            if ($final) {
                $from = $order->statut;
                $order->update([
                    'statut' => $order->vente_id !== null ? OrderStatus::Paid : OrderStatus::Served,
                    'remise_le' => now(),
                    'remise_par_id' => $userId,
                    ...(($data['mode'] ?? null) === 'livraison' ? ['statut_livraison' => 'livree', 'livree_le' => now()] : []),
                ]);
                $this->tracker->log($order, 'remise', $from->value, 'remise', ($data['mode'] ?? 'retrait') === 'livraison' ? 'Livrée' : 'Remise au client', $userId);
            } else {
                $this->tracker->log($order, 'remise', null, null, "Remise partielle : {$selected->count()} pièce(s), ".($open->count() - $selected->count()).' restante(s)', $userId);
            }

            return $order;
        });
    }

    /**
     * Description, employé ou étape d'une pièce ; quand toutes les pièces
     * encore au pressing sont prêtes, la commande passe « prête ».
     *
     * @param  array<string, mixed>  $data
     */
    public function updateArticle(Order $order, DropOffItem $article, array $data, ?int $userId): DropOffItem
    {
        return DB::transaction(function () use ($order, $article, $data, $userId) {
            $order = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            if (! $order->statut->isOpen() || ! $article->isOpen()) {
                throw new OrderLineException('Cette pièce est déjà remise ou annulée.', 'ARTICLE_CLOS');
            }
            $before = $article->only(['statut', 'employe_id']);
            $article->update(array_intersect_key($data, array_flip(['type_article', 'couleur', 'marque', 'etat', 'defauts', 'instructions', 'statut', 'employe_id'])));
            if (array_key_exists('statut', $data) && $before['statut'] !== $article->statut) {
                $this->tracker->log($order, 'statut', $before['statut'], $article->statut, null, $userId, $article->id);
                $this->tracker->syncFromArticles($order, $userId);
            }
            if (array_key_exists('employe_id', $data) && (string) $before['employe_id'] !== (string) $article->employe_id) {
                $this->tracker->log($order, 'affectation', $before['employe_id'] === null ? null : (string) $before['employe_id'], $article->employe_id === null ? null : (string) $article->employe_id, 'employe_id', $userId, $article->id);
            }

            return $article;
        });
    }

    private function requireRegister(?int $registerId): void
    {
        if ($registerId === null) {
            throw new OrderLineException('Choisissez la caisse qui enregistre le règlement.', 'CAISSE_REQUISE');
        }
    }
}

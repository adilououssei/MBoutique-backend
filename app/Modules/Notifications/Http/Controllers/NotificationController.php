<?php

namespace App\Modules\Notifications\Http\Controllers;

use App\Modules\Notifications\Http\Resources\NotificationResource;
use App\Modules\Tenancy\Models\Store;
use App\Shared\Http\Controllers\ApiController;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Http\Request;

/**
 * Centre de notifications de l'utilisateur connecté, limité à la boutique
 * de l'URL (un membre de plusieurs boutiques voit chaque boutique à part).
 * Aucune permission spécifique : chacun ne voit que ses propres notifications.
 */
class NotificationController extends ApiController
{
    public function index(Store $store, Request $request)
    {
        $notifications = $this->forStore($request, $store)
            ->when($request->boolean('non_lues'), fn ($q) => $q->whereNull('read_at'))
            ->latest()
            ->paginate(min((int) $request->integer('par_page', 30), 100));

        return $this->success(NotificationResource::collection($notifications));
    }

    /** GET /notifications/compteur — pour le badge de la cloche. */
    public function count(Store $store, Request $request)
    {
        return $this->success(['non_lues' => $this->forStore($request, $store)->whereNull('read_at')->count()]);
    }

    public function markRead(Store $store, Request $request, string $notification)
    {
        $found = $this->forStore($request, $store)->whereKey($notification)->firstOrFail();
        $found->markAsRead();

        return $this->success(new NotificationResource($found));
    }

    public function markAllRead(Store $store, Request $request)
    {
        $this->forStore($request, $store)->whereNull('read_at')->update(['read_at' => now()]);

        return $this->success(null, 'Toutes les notifications sont lues.');
    }

    private function forStore(Request $request, Store $store): MorphMany
    {
        return $request->user()->notifications()->where('data->boutique_id', $store->id);
    }
}

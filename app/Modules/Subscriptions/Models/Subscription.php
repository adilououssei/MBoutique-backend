<?php

namespace App\Modules\Subscriptions\Models;

use App\Modules\Subscriptions\Enums\SubscriptionStatus;
use App\Modules\Tenancy\Models\Business;
use Database\Factories\Modules\Subscriptions\SubscriptionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['forfait_id', 'statut', 'fin_essai_le', 'debut_periode_le', 'fin_periode_le', 'annule_le'])]
#[Table('abonnements')]
class Subscription extends Model
{
    use HasFactory;

    protected $attributes = [
        'statut' => 'actif',
    ];

    protected static function newFactory(): SubscriptionFactory
    {
        return SubscriptionFactory::new();
    }

    protected function casts(): array
    {
        return [
            'statut' => SubscriptionStatus::class,
            'fin_essai_le' => 'datetime',
            'debut_periode_le' => 'datetime',
            'fin_periode_le' => 'datetime',
            'annule_le' => 'datetime',
        ];
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class, 'entreprise_id');
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class, 'forfait_id');
    }
}

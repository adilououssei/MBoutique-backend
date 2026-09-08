<?php

namespace App\Modules\Subscriptions\Models;

use App\Modules\Subscriptions\Enums\SubscriptionStatus;
use App\Modules\Tenancy\Models\Business;
use Database\Factories\Modules\Subscriptions\SubscriptionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['plan_id', 'status', 'trial_ends_at', 'current_period_starts_at', 'current_period_ends_at', 'cancelled_at'])]
class Subscription extends Model
{
    use HasFactory;

    protected $attributes = [
        'status' => 'active',
    ];

    protected static function newFactory(): SubscriptionFactory
    {
        return SubscriptionFactory::new();
    }

    protected function casts(): array
    {
        return [
            'status' => SubscriptionStatus::class,
            'trial_ends_at' => 'datetime',
            'current_period_starts_at' => 'datetime',
            'current_period_ends_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }
}

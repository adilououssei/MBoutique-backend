<?php

namespace App\Modules\Subscriptions\Services;

use App\Modules\Subscriptions\Exceptions\SubscriptionLimitExceededException;
use App\Modules\Tenancy\Models\Business;

/**
 * Limits (counts) are deliberately separate from Features (on/off) — see
 * docs/subscriptions.md §3 and docs/feature-gate.md. Checked only at the
 * moment a counted resource is created, never on every request the way
 * FeatureGate is.
 *
 * A Business without a Subscription is treated as unrestricted (fail
 * open): Phase 1 does not create a Subscription automatically, so every
 * Business created so far has none — failing closed here would lock
 * every existing Business out of creating further stores the moment this
 * phase ships. Manual/no subscription = administered by hand, per
 * docs/subscriptions.md §5.
 */
class SubscriptionLimits
{
    public function assertCanCreateStore(Business $business): void
    {
        $plan = $business->subscription?->plan;

        if (! $plan || $plan->max_boutiques === null) {
            return;
        }

        if ($business->stores()->count() >= $plan->max_boutiques) {
            throw SubscriptionLimitExceededException::forStores($plan->max_boutiques);
        }
    }
}

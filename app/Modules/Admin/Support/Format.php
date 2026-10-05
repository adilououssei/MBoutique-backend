<?php

namespace App\Modules\Admin\Support;

use App\Enums\UserStatus;
use App\Modules\Subscriptions\Enums\SubscriptionStatus;
use App\Modules\Tenancy\Enums\BusinessStatus;
use App\Modules\Tenancy\Enums\StoreStatus;
use Illuminate\Support\Str;

/** Formatage partagé par les vues d'administration (mêmes conventions que lib/format.ts du mobile). */
final class Format
{
    public static function money(string|float|int|null $value, string $currency = 'FCFA'): string
    {
        // XOF / XAF s'affichent « FCFA », comme dans l'application.
        $currency = in_array($currency, ['XOF', 'XAF'], true) ? 'FCFA' : $currency;

        return number_format((float) $value, 0, ',', "\u{202F}").' '.$currency;
    }

    public static function number(string|float|int|null $value): string
    {
        return number_format((float) $value, 0, ',', "\u{202F}");
    }

    /** Initiales d'un nom (« Awa Koné » → « AK »), comme les vignettes mobiles. */
    public static function initials(?string $name): string
    {
        $words = collect(preg_split('/\s+/', trim((string) $name)))->filter(fn ($w) => $w !== '' && ! is_numeric($w))->values();

        return Str::upper(mb_substr($words->first() ?? '?', 0, 1).($words->count() > 1 ? mb_substr($words->last(), 0, 1) : ''));
    }

    /** @return array{0: string, 1: string} libellé, ton */
    public static function businessStatus(BusinessStatus $status): array
    {
        return match ($status) {
            BusinessStatus::Active => ['Active', 'success'],
            BusinessStatus::Suspended => ['Suspendue', 'danger'],
        };
    }

    /** @return array{0: string, 1: string} */
    public static function storeStatus(StoreStatus $status): array
    {
        return match ($status) {
            StoreStatus::Active => ['Active', 'success'],
            StoreStatus::Inactive => ['Désactivée', 'danger'],
        };
    }

    /** @return array{0: string, 1: string} */
    public static function userStatus(UserStatus $status): array
    {
        return match ($status) {
            UserStatus::Active => ['Actif', 'success'],
            UserStatus::Inactive => ['Désactivé', 'danger'],
        };
    }

    /** @return array{0: string, 1: string} */
    public static function subscriptionStatus(?SubscriptionStatus $status): array
    {
        return match ($status) {
            SubscriptionStatus::Trialing => ['Essai', 'info'],
            SubscriptionStatus::Active => ['Actif', 'success'],
            SubscriptionStatus::PastDue => ['Impayé', 'warning'],
            SubscriptionStatus::Cancelled => ['Annulé', 'danger'],
            null => ['Sans abonnement', 'neutral'],
        };
    }
}

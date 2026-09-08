<?php

namespace App\Shared\Tenancy\Contracts;

/**
 * Holds the "current store" for the lifetime of one HTTP request.
 *
 * Bound as a singleton in AppServiceProvider and populated by the
 * store-resolution middleware (see docs/multi-tenancy.md). Every module
 * that needs to scope data to the current tenant depends on this
 * contract instead of reading the route parameter directly, so the
 * resolution strategy can change (route param, subdomain, header) without
 * touching module code.
 */
interface TenantContextContract
{
    public function setStoreId(int|string $storeId): void;

    public function getStoreId(): int|string|null;

    public function hasStore(): bool;

    public function clear(): void;
}

<?php

namespace App\Shared\Tenancy;

use App\Shared\Tenancy\Contracts\TenantContextContract;

/**
 * Default, in-memory implementation of TenantContextContract.
 * One instance per request (bound as a singleton).
 */
class TenantContext implements TenantContextContract
{
    private int|string|null $storeId = null;

    public function setStoreId(int|string $storeId): void
    {
        $this->storeId = $storeId;
    }

    public function getStoreId(): int|string|null
    {
        return $this->storeId;
    }

    public function hasStore(): bool
    {
        return $this->storeId !== null;
    }

    public function clear(): void
    {
        $this->storeId = null;
    }
}

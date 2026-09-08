<?php

namespace Database\Seeders;

use App\Modules\Authorization\Support\Permissions;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;

/**
 * Permission definitions are global (spatie's `permissions` table has no
 * team_foreign_key column — only roles and their pivots are team-scoped,
 * see docs/permissions.md §5 bis). Store-level Role rows, by contrast,
 * are provisioned per-store by StoreRoleProvisioner, not here.
 */
class PermissionSeeder extends Seeder
{
    public function run(): void
    {
        foreach (Permissions::all() as $name) {
            Permission::findOrCreate($name, 'web');
        }
    }
}

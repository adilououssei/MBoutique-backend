<?php

namespace App\Modules\Catalog\Models;

use App\Shared\Tenancy\Concerns\BelongsToStore;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Collection ou saison (été, rentrée, fêtes…) à laquelle des articles appartiennent. */
#[Fillable(['nom', 'saison', 'debut_le', 'fin_le', 'actif'])]
#[Table('collections')]
class Collection extends Model
{
    use BelongsToStore;

    protected $attributes = [
        'actif' => true,
    ];

    protected function casts(): array
    {
        return ['debut_le' => 'date', 'fin_le' => 'date', 'actif' => 'boolean'];
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class, 'collection_id');
    }
}

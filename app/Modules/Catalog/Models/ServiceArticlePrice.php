<?php

namespace App\Modules\Catalog\Models;

use App\Shared\Tenancy\Concerns\BelongsToStore;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;

/** Pressing : prix d'une prestation pour un type d'article (chemise, costume, couverture…). */
#[Fillable(['service_id', 'type_article', 'prix'])]
#[Table('tarifs_articles_service')]
class ServiceArticlePrice extends Model
{
    use BelongsToStore;

    protected function casts(): array
    {
        return ['prix' => 'decimal:2'];
    }
}

<?php

namespace App\Modules\Catalog\Models;

use App\Modules\Tenancy\Models\Store;
use App\Shared\Contracts\Sellable;
use App\Shared\Tenancy\Concerns\BelongsToStore;
use Database\Factories\Modules\Catalog\ServiceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A prestation. No stock, optionally a duration (used later by
 * Appointments). See docs/catalog.md.
 */
#[Fillable(['categorie_id', 'nom', 'slug', 'description', 'consignes', 'unite_tarif', 'delai_heures', 'prix', 'prix_variable', 'est_formule', 'duree_minutes', 'commission_type', 'commission_valeur', 'actif'])]
class Service extends Model implements Sellable
{
    use BelongsToStore, HasFactory, SoftDeletes;

    protected $attributes = [
        'actif' => true,
    ];

    protected static function newFactory(): ServiceFactory
    {
        return ServiceFactory::new();
    }

    protected function casts(): array
    {
        return [
            'prix' => 'decimal:2',
            'prix_variable' => 'boolean',
            'est_formule' => 'boolean',
            'commission_valeur' => 'decimal:2',
            'actif' => 'boolean',
        ];
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class, 'boutique_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'categorie_id');
    }

    /** Salon : produits consommés par prestation. */
    public function consumables(): HasMany
    {
        return $this->hasMany(ServiceConsumable::class, 'service_id');
    }

    /** Pressing : grille de prix par type d'article. */
    public function articlePrices(): HasMany
    {
        return $this->hasMany(ServiceArticlePrice::class, 'service_id')->orderBy('type_article');
    }

    /** Prix pour un type d'article (grille), sinon le prix de la prestation. */
    public function priceForArticle(?string $articleType): string
    {
        if ($articleType !== null && $articleType !== '') {
            $price = ServiceArticlePrice::query()->where('service_id', $this->id)->where('type_article', $articleType)->value('prix');
            if ($price !== null) {
                return (string) $price;
            }
        }

        return (string) $this->prix;
    }

    /** Formule combinée : les prestations qui la composent. */
    public function components(): BelongsToMany
    {
        return $this->belongsToMany(self::class, 'composants_formule', 'formule_id', 'service_id')->withPivot('ordre')->orderBy('composants_formule.ordre')->withTimestamps();
    }

    /** Durée de la prestation ; pour une formule sans durée propre, la somme de ses composants. */
    public function effectiveDuration(): ?int
    {
        if ($this->duree_minutes !== null || ! $this->est_formule) {
            return $this->duree_minutes;
        }
        $sum = (int) $this->components()->sum('services.duree_minutes');

        return $sum > 0 ? $sum : null;
    }

    public function getSellableLabel(): string
    {
        return $this->nom;
    }

    public function getSellablePrice(): string
    {
        return $this->prix;
    }

    public function tracksStock(): bool
    {
        return false;
    }
}

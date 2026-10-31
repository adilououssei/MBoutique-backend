<?php

namespace App\Modules\Devices\Models;

use App\Models\User;
use App\Shared\Tenancy\Concerns\BelongsToStore;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

#[Fillable(['appareil_id', 'evenement', 'description', 'reference_type', 'reference_id', 'cree_par_id'])]
#[Table('historique_appareils')]
class DeviceEvent extends Model
{
    use BelongsToStore;

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class, 'appareil_id');
    }

    public function reference(): MorphTo
    {
        return $this->morphTo('reference');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cree_par_id');
    }
}

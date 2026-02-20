<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use App\Models\Scopes\TenantScope;

class Rejection extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_id',
        'date',
        'penalty',
        'disputed',
        'carrier_controllable',
        'driver_controllable',
        'rejection_reason',
    ];

    /**
     * Get the advanced rejected block for this rejection.
     */
    public function advancedRejectedBlock()
    {
        return $this->hasMany(AdvancedRejectedBlock::class);
    }

    /**
     * Get the rejected block for this rejection.
     */
    public function rejectedBlock()
    {
        return $this->hasMany(RejectedBlock::class);
    }

    /**
     * Get the rejected load for this rejection.
     */
    public function rejectedLoad()
    {
        return $this->hasMany(RejectedLoad::class);
    }

    /**
     * Get the tenant associated with the rejection.
     */
    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    protected static function booted()
    {
        if (Auth::check()) {
            static::addGlobalScope(new TenantScope);
        }
    }
}

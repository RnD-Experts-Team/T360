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
     * Internal flag to skip controllable enforcement.
     * Not persisted to DB.
     */
    protected bool $skipControllableEnforcement = false;

    /**
     * Public method to explicitly disable enforcement.
     * Keeps service layer clean and expressive.
     */
    public function skipControllableEnforcement(): self
    {
        $this->skipControllableEnforcement = true;
        return $this;
    }

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

        // Automatically adjust 'driver_controllable' and 'carrier_controllable' before saving or updating
        static::saving(function (Rejection $rejection) {

            // 🚫 Skip enforcement if explicitly disabled
            if ($rejection->skipControllableEnforcement) {
                return true;
            }

            if (empty($rejection->rejection_reason)) {
                return true;
            }

            if (preg_match('/amazon/i', $rejection->rejection_reason)) {
                $rejection->driver_controllable  = false;
                $rejection->carrier_controllable = false;
                return true;
            }

            if (
                preg_match('/mechanical[_]?trailer/i', $rejection->rejection_reason) ||
                preg_match('/weather/i', $rejection->rejection_reason)
            ) {
                $rejection->driver_controllable  = false;
                $rejection->carrier_controllable = false;
            }

            return true;
        });

        // 🔒 Safety: always reset flag after save
        static::saved(function (Rejection $rejection) {
            $rejection->skipControllableEnforcement = false;
        });
    }
}

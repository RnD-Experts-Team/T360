<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use App\Models\Scopes\TenantScope;

class RejectedLoad extends Model
{
    use HasFactory;

    protected $fillable = [
        'rejection_id',
        'load_id',
        'driver_name',
        'origin_yard_arrival',
        'rejection_bucket',
    ];

    /**
     * Get the rejection that owns the rejected load.
     */
    public function rejection()
    {
        return $this->belongsTo(Rejection::class);
    }
}

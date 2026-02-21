<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use App\Models\Scopes\TenantScope;

class RejectedBlock extends Model
{
    use HasFactory;

    protected $fillable = [
        'rejection_id',
        'block_id',
        'driver_name',
        'block_start',
        'block_end',
        'rejection_datetime',
        'rejection_bucket',
    ];

    /**
     * Get the rejection that owns the rejected block.
     */
    public function rejection()
    {
        return $this->belongsTo(Rejection::class);
    }
}

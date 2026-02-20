<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use App\Models\Scopes\TenantScope;

class AdvancedRejectedBlock extends Model
{
    use HasFactory;

    protected $fillable = [
        'advance_block_rejection_id',
        'rejection_id',
        'week_start',
        'week_end',
        'impacted_blocks',
        'expected_blocks',
    ];

    /**
     * Get the rejection that owns the advanced rejected block.
     */
    public function rejection()
    {
        return $this->belongsTo(Rejection::class);
    }

    protected static function booted()
    {
        if (Auth::check()) {
            static::addGlobalScope(new TenantScope);
        }
    }
}

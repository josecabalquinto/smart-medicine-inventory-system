<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property Carbon $expires_at
 */
#[Fillable(['id', 'scale_id', 'medicine_id', 'user_id', 'calibration_profile_id', 'calibration_session_id', 'boot_id', 'tare_revision', 'acknowledged', 'expires_at'])]
class CountingSession extends Model
{
    protected $attributes = ['acknowledged' => false];

    public $incrementing = false;

    protected $keyType = 'string';

    /** @return BelongsTo<Medicine, $this> */
    public function medicine(): BelongsTo
    {
        return $this->belongsTo(Medicine::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['acknowledged' => 'boolean', 'expires_at' => 'datetime'];
    }
}

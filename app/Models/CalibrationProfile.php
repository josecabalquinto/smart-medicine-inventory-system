<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property Carbon $calibrated_at
 */
#[Fillable(['medicine_id', 'scale_id', 'calibration_session_id', 'original_tare_raw', 'one_piece_net', 'ten_piece_net', 'sample_quantity', 'raw_units_per_piece', 'version', 'active', 'calibrated_by', 'calibrated_at', 'notes'])]
class CalibrationProfile extends Model
{
    protected $attributes = ['active' => true, 'sample_quantity' => 10];

    /** @return BelongsTo<Medicine, $this> */
    public function medicine(): BelongsTo
    {
        return $this->belongsTo(Medicine::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['active' => 'boolean', 'calibrated_at' => 'datetime', 'raw_units_per_piece' => 'double', 'original_tare_raw' => 'double', 'one_piece_net' => 'double', 'ten_piece_net' => 'double', 'version' => 'integer'];
    }
}

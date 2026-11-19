<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['count_method', 'medicine_id', 'previous_quantity', 'new_quantity', 'difference', 'source', 'reason', 'notes', 'user_id', 'scale_id', 'calibration_profile_id', 'profile_version', 'boot_id', 'counting_session_id', 'calibration_session_id', 'tare_revision', 'reading_sequence', 'idempotency_key', 'payload_hash'])]
class InventoryCount extends Model
{
    /** @return BelongsTo<Medicine, $this> */
    public function medicine(): BelongsTo
    {
        return $this->belongsTo(Medicine::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [];
    }
}

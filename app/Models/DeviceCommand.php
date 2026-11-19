<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property Carbon $expires_at
 */
#[Fillable(['expected_calibration_session_id', 'id', 'scale_id', 'counting_session_id', 'calibration_profile_id', 'boot_id', 'status', 'error', 'expires_at'])]
class DeviceCommand extends Model
{
    protected $attributes = ['status' => 'pending'];

    public $incrementing = false;

    protected $keyType = 'string';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['expires_at' => 'datetime'];
    }
}

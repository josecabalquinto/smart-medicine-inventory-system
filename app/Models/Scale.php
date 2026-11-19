<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property Carbon|null $last_seen_at
 */
#[Fillable(['device_identifier', 'name', 'active', 'simulation', 'credential_hash', 'last_seen_at', 'firmware_version', 'boot_id', 'last_sequence', 'revision'])]
class Scale extends Model
{
    protected $attributes = ['active' => true, 'simulation' => false, 'last_sequence' => -1, 'revision' => 0];

    protected $hidden = ['credential_hash'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['active' => 'boolean', 'simulation' => 'boolean', 'last_seen_at' => 'datetime', 'last_sequence' => 'integer', 'revision' => 'integer'];
    }
}

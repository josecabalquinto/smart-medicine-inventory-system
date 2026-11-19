<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['code', 'generic_name', 'brand_name', 'strength', 'dosage_form', 'manufacturer', 'notes', 'low_stock_threshold', 'active', 'simulation', 'quantity', 'stock_revision'])]
class Medicine extends Model
{
    protected $attributes = ['active' => true, 'simulation' => false, 'stock_revision' => 0, 'low_stock_threshold' => 10];

    public function identity(): string
    {
        return implode(' / ', array_filter([$this->generic_name, $this->strength, $this->dosage_form, $this->manufacturer]));
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['active' => 'boolean', 'simulation' => 'boolean', 'quantity' => 'integer', 'stock_revision' => 'integer'];
    }
}

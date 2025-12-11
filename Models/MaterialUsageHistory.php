<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MaterialUsageHistory extends Model
{
    use HasFactory;

    protected $table = 'material_usage_history';

    protected $fillable = [
        'material_id',
        'quantity_used',
        'cost_per_unit',
        'total_cost',
    ];

    protected $casts = [
        'quantity_used' => 'integer',
        'cost_per_unit' => 'decimal:2',
        'total_cost' => 'decimal:2',
    ];

    public function material()
    {
        return $this->belongsTo(Material::class);
    }
}


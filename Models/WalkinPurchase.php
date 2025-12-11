<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class WalkinPurchase extends Model
{
    use HasFactory;

    protected $fillable = [
        'customer_name',
        'customer_email', 
        'customer_contact',
        'product_name',
        'unit_price',
        'quantity',
        'total_price',
        'item_type',
        'category'
    ];

    protected $casts = [
        'unit_price' => 'decimal:2',
        'total_price' => 'decimal:2',
        'quantity' => 'integer'
    ];
}
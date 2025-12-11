<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Cart extends Model
{
    protected $fillable = [
        'user_id',
        'product_id',
        'custom_proposal_id', // This is the key field for customized items
        'available_sizes',
        'quantity',
        'size_price',
        'price',
        'total_price',
        'is_customized'
    ];

    protected $casts = [
        'quantity' => 'integer',
        'size_price' => 'decimal:2',
        'price' => 'decimal:2',
        'total_price' => 'decimal:2',
        'is_customized' => 'boolean'
    ];

    protected $attributes = [
        'available_sizes' => '',
        'is_customized' => false
    ];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function customProposal()
    {
        return $this->belongsTo(CustomProposal::class, 'custom_proposal_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    // Helper method to get the item name
    public function getItemNameAttribute()
    {
        if ($this->is_customized) {
            return isset($this->customProposal->name) ? $this->customProposal->name : 'Custom Item';
        } else {
            return isset($this->product->name) ? $this->product->name : 'Product';
        }
    }

    // Helper method to get item image - ENHANCED
    public function getItemImageAttribute()
    {
        if ($this->is_customized) {
            $images = isset($this->customProposal->images) ? $this->customProposal->images : [];
            return !empty($images) ? $images[0] : null;
        } else {
            return isset($this->product->images[0]) ? $this->product->images[0] : null;
        }
    }

    // NEW: Helper method to get item material
    public function getItemMaterialAttribute()
    {
        if ($this->is_customized) {
            return isset($this->customProposal->material) ? $this->customProposal->material : null;
        } else {
            return isset($this->product->material) ? $this->product->material : null;
        }
    }

    // NEW: Helper method to check if item has images
    public function getHasImagesAttribute()
    {
        if ($this->is_customized) {
            $images = isset($this->customProposal->images) ? $this->customProposal->images : [];
            return !empty($images);
        } else {
            return isset($this->product->images) && !empty($this->product->images);
        }
    }
}
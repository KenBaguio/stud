<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OrderItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_id',
        'product_id',
        'custom_proposal_id',
        'name',
        'price',
        'size_price',
        'quantity',
        'size',
        'image',
        'is_customized',
        'customization_details',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'size_price' => 'decimal:2',
        'quantity' => 'integer',
        'is_customized' => 'boolean',
        'customization_details' => 'array',
    ];

    protected $appends = [
        'item_type',
        'display_name',
        'display_image',
        'display_description',
        'display_material',
        'total_price',
        'product_type'
    ];

    // Related order
    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    // Related product (for regular products)
    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    // Related custom proposal (for customized items)
    public function customProposal()
    {
        return $this->belongsTo(CustomProposal::class, 'custom_proposal_id');
    }

    // ACCESSORS

    // Helper method to get item type
    public function getItemTypeAttribute()
    {
        return $this->is_customized ? 'custom' : 'regular';
    }

    // Helper method to get product type for revenue categorization
    public function getProductTypeAttribute()
    {
        return $this->is_customized ? 'customized' : 'reference';
    }

    // Helper method to get item name
    public function getDisplayNameAttribute()
    {
        if ($this->is_customized && $this->customProposal) {
            return $this->customProposal->name ?? 'Custom Item';
        }
        return $this->name ?? 'Product';
    }

    // Helper method to get item image
    public function getDisplayImageAttribute()
    {
        if ($this->is_customized && $this->customProposal && !empty($this->customProposal->images)) {
            return is_array($this->customProposal->images) 
                ? $this->customProposal->images[0] 
                : $this->customProposal->images;
        }
        return $this->image;
    }

    // Helper method to get item description
    public function getDisplayDescriptionAttribute()
    {
        if ($this->is_customized && $this->customProposal) {
            return $this->customProposal->customization_request ?? 'Custom order';
        }
        return null;
    }

    // Helper method to get material
    public function getDisplayMaterialAttribute()
    {
        if ($this->is_customized) {
            if ($this->customization_details) {
                return $this->customization_details['material'] ?? null;
            }
            if ($this->customProposal) {
                return $this->customProposal->material ?? null;
            }
        }
        return null;
    }

    // Get total price for this item
    public function getTotalPriceAttribute()
    {
        $unitPrice = $this->size_price ?? $this->price;
        return $unitPrice * $this->quantity;
    }

    // Get unit price (with size price if available)
    public function getUnitPriceAttribute()
    {
        return $this->size_price ?? $this->price;
    }

    // SCOPES

    // Scope for reference items
    public function scopeReferenceItems($query)
    {
        return $query->where('is_customized', false);
    }

    // Scope for customized items
    public function scopeCustomizedItems($query)
    {
        return $query->where('is_customized', true);
    }

    // Scope to include product relationships
    public function scopeWithProductDetails($query)
    {
        return $query->with(['product', 'customProposal']);
    }

    // BUSINESS LOGIC METHODS

    // Check if this item contributes to reference revenue
    public function isReferenceItem()
    {
        return !$this->is_customized;
    }

    // Check if this item contributes to customized revenue
    public function isCustomizedItem()
    {
        return $this->is_customized;
    }

    // Get revenue amount for this item
    public function getRevenueAmount()
    {
        return $this->quantity * ($this->size_price ?? $this->price);
    }

    // Get revenue categorization for dashboard
    public function getRevenueCategorization()
    {
        $amount = $this->getRevenueAmount();
        
        return [
            'type' => $this->is_customized ? 'customized' : 'reference',
            'amount' => $amount,
            'order_type' => 'online', // Since this is from Order model
            'item_id' => $this->id,
            'product_type' => $this->product_type
        ];
    }
}
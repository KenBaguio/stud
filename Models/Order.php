<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'customer',      
        'subtotal',      
        'shipping_fee',  
        'total_amount',  
        'voucher_id',
        'voucher_code',
        'discount_amount',
        'payment_method',
        'payment_status',
        'status',
    ];

    protected $casts = [
        'customer' => 'array',
        'subtotal' => 'decimal:2',
        'shipping_fee' => 'decimal:2',
        'total_amount' => 'decimal:2',
        'discount_amount' => 'decimal:2',
    ];

    protected $appends = [
        'revenue_breakdown',
        'has_custom_items',
        'has_regular_items',
        'total_items_count',
        'custom_items_count',
        'regular_items_count'
    ];

    // User who placed the order
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    // Voucher used (if any)
    public function voucher()
    {
        return $this->belongsTo(Voucher::class);
    }

    // Order items - ENHANCED to include both regular and custom items
    public function items()
    {
        return $this->hasMany(OrderItem::class);
    }

    // Regular product items only (reference items)
    public function regularItems()
    {
        return $this->hasMany(OrderItem::class)->where('is_customized', false);
    }

    // Custom proposal items only (customized items)
    public function customItems()
    {
        return $this->hasMany(OrderItem::class)->where('is_customized', true);
    }

    // ACCESSORS

    // Check if order has custom items
    public function getHasCustomItemsAttribute()
    {
        return $this->items()->where('is_customized', true)->exists();
    }

    // Check if order has regular items
    public function getHasRegularItemsAttribute()
    {
        return $this->items()->where('is_customized', false)->exists();
    }

    // Get order item count
    public function getTotalItemsCountAttribute()
    {
        return $this->items()->sum('quantity');
    }

    // Get custom items count
    public function getCustomItemsCountAttribute()
    {
        return $this->items()->where('is_customized', true)->sum('quantity');
    }

    // Get regular items count
    public function getRegularItemsCountAttribute()
    {
        return $this->items()->where('is_customized', false)->sum('quantity');
    }

    // Revenue breakdown between reference and customized items
    public function getRevenueBreakdownAttribute()
    {
        $referenceAmount = 0;
        $customizedAmount = 0;
        
        foreach ($this->items as $item) {
            $itemTotal = $item->quantity * ($item->size_price ?? $item->price);
            
            if ($item->is_customized) {
                $customizedAmount += $itemTotal;
            } else {
                $referenceAmount += $itemTotal;
            }
        }
        
        $total = $referenceAmount + $customizedAmount;
        
        return [
            'reference' => $referenceAmount,
            'customized' => $customizedAmount,
            'total' => $total,
            'reference_percentage' => $total > 0 ? ($referenceAmount / $total) * 100 : 0,
            'customized_percentage' => $total > 0 ? ($customizedAmount / $total) * 100 : 0
        ];
    }

    // Get order type classification
    public function getOrderTypeClassificationAttribute()
    {
        if ($this->has_custom_items && $this->has_regular_items) {
            return 'mixed';
        } elseif ($this->has_custom_items) {
            return 'customized';
        } else {
            return 'reference';
        }
    }

    // SCOPES

    // Scope for reference item orders (only regular products)
    public function scopeReferenceOrders($query)
    {
        return $query->whereHas('items', function ($q) {
            $q->where('is_customized', false);
        });
    }

    // Scope for customized item orders (has custom proposals)
    public function scopeCustomizedOrders($query)
    {
        return $query->whereHas('items', function ($q) {
            $q->where('is_customized', true);
        });
    }

    // Scope for mixed orders (both reference and customized)
    public function scopeMixedOrders($query)
    {
        return $query->whereHas('items', function ($q) {
            $q->where('is_customized', false);
        })->whereHas('items', function ($q) {
            $q->where('is_customized', true);
        });
    }

    // Scope to eager load with revenue breakdown
    public function scopeWithRevenueBreakdown($query)
    {
        return $query->with(['items' => function ($q) {
            $q->select('id', 'order_id', 'product_id', 'custom_proposal_id', 'quantity', 'price', 'size_price', 'is_customized');
        }]);
    }

    // Helper method to calculate revenue for dashboard
    public function calculateItemTypeRevenue()
    {
        $referenceRevenue = 0;
        $customizedRevenue = 0;

        foreach ($this->items as $item) {
            $itemTotal = $item->quantity * ($item->size_price ?? $item->price);
            
            if ($item->is_customized) {
                $customizedRevenue += $itemTotal;
            } else {
                $referenceRevenue += $itemTotal;
            }
        }

        return [
            'reference' => $referenceRevenue,
            'customized' => $customizedRevenue
        ];
    }
}
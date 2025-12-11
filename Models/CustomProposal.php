<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CustomProposal extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'customer_id',
        'name',
        'customization_request',
        'product_type',
        'category',
        'customer_name',
        'customer_email',
        'quantity',
        'total_price',
        'designer_message',
        'material',
        'features',
        'images',
        'size_options',
    ];

    protected $casts = [
        'features' => 'array',
        'images' => 'array',
        'size_options' => 'array',
        'quantity' => 'integer',
        'total_price' => 'decimal:2'
    ];

    // Relationship to user (clerk/designer who created the proposal)
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    // Relationship to customer (user who requested the customization)
    public function customer()
    {
        return $this->belongsTo(User::class, 'customer_id');
    }

    // Relationship to cart items
    public function cartItems()
    {
        return $this->hasMany(Cart::class, 'custom_proposal_id');
    }

    // Get display price
    public function getDisplayPriceAttribute()
    {
        $price = $this->total_price ? $this->total_price : 0;
        $price = (float) $price;
        return '₱' . number_format($price, 2);
    }

    // Alternative method for getting price as float
    public function getPriceFloatAttribute()
    {
        return (float) ($this->total_price ? $this->total_price : 0);
    }

    /**
     * Get images with HTTPS URLs
     * Note: This accessor works with the 'images' => 'array' cast
     */
    public function getImagesAttribute($value)
    {
        // Get the raw attribute value (before cast)
        $rawValue = $this->attributes['images'] ?? null;
        
        if (!$rawValue) {
            return [];
        }
        
        // Handle JSON string or array (cast might have already converted it)
        if (is_string($rawValue)) {
            $images = json_decode($rawValue, true) ?? [];
        } else if (is_array($rawValue)) {
            $images = $rawValue;
        } else {
            return [];
        }
        
        if (!is_array($images)) {
            return [];
        }
        
        return collect($images)->map(function($image) {
            if (!$image) {
                return null;
            }
            
            // If image is already a full URL, convert HTTP to HTTPS if needed
            if (filter_var($image, FILTER_VALIDATE_URL)) {
                return $this->ensureHttps($image);
            }
            
            return $image;
        })->filter()->values()->toArray();
    }

    /**
     * Ensure URL uses HTTPS - Always convert HTTP to HTTPS for production domains
     */
    private function ensureHttps($url) {
        // If already HTTPS, return as is
        if (strpos($url, 'https://') === 0) {
            return $url;
        }
        
        // If HTTP, convert to HTTPS
        if (strpos($url, 'http://') === 0) {
            $appUrl = config('app.url', 'http://localhost');
            
            // Always convert to HTTPS if:
            // 1. APP_URL uses HTTPS
            // 2. In production environment
            // 3. Request is secure (HTTPS)
            // 4. URL contains production/test domain (mgexclusive.shop)
            $isProductionDomain = (
                strpos($url, 'mgexclusive.shop') !== false ||
                strpos($url, 'api-test.mgexclusive.shop') !== false ||
                strpos($url, 'api.mgexclusive.shop') !== false
            );
            
            if (strpos($appUrl, 'https://') === 0 || 
                config('app.env') === 'production' || 
                $isProductionDomain) {
                return str_replace('http://', 'https://', $url);
            }
            
            // Also check if request is secure (if available)
            try {
                if (request() && request()->secure()) {
                    return str_replace('http://', 'https://', $url);
                }
                
                // Check Referer header to see if request came from HTTPS page
                $referer = request()->header('Referer');
                if ($referer && strpos($referer, 'https://') === 0) {
                    return str_replace('http://', 'https://', $url);
                }
            } catch (\Exception $e) {
                // Request not available, continue with other checks
            }
        }
        
        return $url;
    }
}
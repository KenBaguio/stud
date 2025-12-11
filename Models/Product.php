<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use App\Helpers\R2Helper;

class Product extends Model {
    use HasFactory;

    protected $fillable = [
        'name','price','description','category_id','status',
        'material','color','note','dimensions','weight','compartments',
        'features','available_sizes','prices','size_dimensions','size_weights','image','images'
    ];

    protected $casts = [
        'features' => 'array',
        'available_sizes' => 'array',
        'images' => 'array',
        'prices' => 'array',
        'size_dimensions' => 'array',
        'size_weights' => 'array',
    ];

    protected $appends = [
        'image_url',
        'image_urls',
    ];

    public function category() {
        return $this->belongsTo(Category::class);
    }

    public function getImageUrlAttribute() {
        if (!($this->attributes['image'] ?? null)) {
            return null;
        }
        
        $image = $this->attributes['image'];
        
        // If image is already a full URL, convert HTTP to HTTPS if needed
        if (filter_var($image, FILTER_VALIDATE_URL)) {
            return $this->ensureHttps($image);
        }
        
        // Extract path from URL if it contains /storage/
        if (strpos($image, '/storage/') !== false) {
            $path = parse_url($image, PHP_URL_PATH);
            $path = ltrim($path, '/');
            if (strpos($path, 'storage/') === 0) {
                $path = substr($path, 8); // Remove 'storage/' prefix
            }
            // Use API endpoint to serve images from R2 (handles R2 URLs properly)
            // This avoids 400 errors from direct R2 URLs
            return $this->ensureHttps(url('/api/storage/' . ltrim($path, '/')));
        }
        
        // Otherwise, use API endpoint for R2 images
        // Check if it's an R2 path (products/, profile_images/, etc.)
        if (strpos($image, 'products/') === 0 || 
            strpos($image, 'profile_images/') === 0 ||
            strpos($image, 'reviews/') === 0 ||
            strpos($image, 'vouchers/') === 0) {
            return $this->ensureHttps(url('/api/storage/' . ltrim($image, '/')));
        }
        
        // Fallback to public storage
        return $this->ensureHttps(Storage::disk('public')->url($image));
    }

    public function getImageUrlsAttribute() {
        $images = $this->attributes['images'] ?? null;
        
        if (!$images) {
            return [];
        }
        
        // Handle JSON string or array
        if (is_string($images)) {
            $images = json_decode($images, true) ?? [];
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
            
            // Extract path from URL if it contains /storage/
            if (strpos($image, '/storage/') !== false) {
                $path = parse_url($image, PHP_URL_PATH);
                $path = ltrim($path, '/');
                if (strpos($path, 'storage/') === 0) {
                    $path = substr($path, 8); // Remove 'storage/' prefix
                }
                // Use API endpoint to serve images from R2 (handles R2 URLs properly)
                // Path is already clean (no /storage/ prefix), so use it directly
                return $this->ensureHttps(url('/api/storage/' . ltrim($path, '/')));
            }
            
            // Handle paths that start with /storage/ or storage/ directly (from database)
            // Database format: "/storage/products/..." or "storage/products/..."
            if (strpos($image, '/storage/') === 0) {
                $path = substr($image, 10); // Remove '/storage/' prefix
                return $this->ensureHttps(url('/api/storage/' . ltrim($path, '/')));
            } elseif (strpos($image, 'storage/') === 0) {
                $path = substr($image, 8); // Remove 'storage/' prefix
                return $this->ensureHttps(url('/api/storage/' . ltrim($path, '/')));
            }
            
            // Otherwise, use API endpoint for R2 images
            // Check if it's an R2 path (products/, profile_images/, etc.)
            if (strpos($image, 'products/') === 0 || 
                strpos($image, 'profile_images/') === 0 ||
                strpos($image, 'reviews/') === 0 ||
                strpos($image, 'vouchers/') === 0) {
                return $this->ensureHttps(url('/api/storage/' . ltrim($image, '/')));
            }
            
            // Fallback to public storage
            return $this->ensureHttps(Storage::disk('public')->url($image));
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
            // Always convert HTTP to HTTPS for production/test domains
            $isProductionDomain = (
                strpos($url, 'mgexclusive.shop') !== false ||
                strpos($url, 'api-test.mgexclusive.shop') !== false ||
                strpos($url, 'api.mgexclusive.shop') !== false ||
                strpos($url, 'test.mgexclusive.shop') !== false
            );
            
            // Always convert for production domains
            if ($isProductionDomain) {
                return str_replace('http://', 'https://', $url);
            }
            
            $appUrl = config('app.url', 'http://localhost');
            
            // Also convert if:
            // 1. APP_URL uses HTTPS
            // 2. In production environment
            // 3. Request is secure (HTTPS)
            if (strpos($appUrl, 'https://') === 0 || 
                config('app.env') === 'production') {
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

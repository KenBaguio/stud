<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use App\Helpers\R2Helper;

class Material extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'type',
        'quantity',
        'cost',
        'image',
        'total_used_cost',
    ];

    protected $appends = ['image_url'];

    public function usageHistory()
    {
        return $this->hasMany(MaterialUsageHistory::class);
    }

    /**
     * Get the full URL for the material image (R2 compatible)
     */
    public function getImageUrlAttribute()
    {
        if (!($this->attributes['image'] ?? null)) {
            return null;
        }
        
        $image = $this->attributes['image'];
        
        // If image is already a full URL, return as is
        if (filter_var($image, FILTER_VALIDATE_URL)) {
            return $image;
        }
        
        // Try R2 first, fallback to public for backward compatibility
        try {
            if (Storage::disk('r2')->exists($image)) {
                return R2Helper::getR2Url($image);
            }
        } catch (\Exception $e) {
            \Log::warning('R2 storage check failed: ' . $e->getMessage());
        }
        
        // Fallback to public storage
        try {
            return Storage::disk('public')->url($image);
        } catch (\Exception $e) {
            \Log::warning('Public storage URL generation failed: ' . $e->getMessage());
            return null;
        }
    }
}

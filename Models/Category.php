<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Log;

class Category extends Model {
    use HasFactory, SoftDeletes;

    protected $fillable = ['name', 'type'];

    protected static function booted(): void
    {
        static::deleting(function (Category $category) {
            $productIds = $category->products()->pluck('id');

            if ($productIds->isEmpty()) {
                return;
            }

            Product::whereIn('id', $productIds)->update(['status' => 'inactive']);

            \App\Models\Wishlist::whereIn('product_id', $productIds)->delete();
            \App\Models\Cart::whereIn('product_id', $productIds)->delete();

            Log::info("Category {$category->id} deleted. Products inactivated and removed from carts/wishlists.", [
                'category_id' => $category->id,
                'product_count' => count($productIds),
            ]);
        });
    }

    public function products() {
        return $this->hasMany(Product::class);
    }
}

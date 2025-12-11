<?php

namespace App\Http\Controllers\Api;

use App\Models\Product;
use App\Models\Wishlist;
use App\Models\Cart;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;
use App\Helpers\R2Helper;

class ProductController extends Controller
{
    public function __construct() {
        $this->middleware('jwt.auth')->except(['index', 'show']);
        $this->middleware(function($request, $next){
            if(auth()->check() && auth()->user()->role !== 'admin') {
                return response()->json(['message'=>'Unauthorized'], 403);
            }
            return $next($request);
        })->except(['index','show', 'deactivateProduct']);
    }

    // Public listing of active products with enhanced search
    public function index(Request $request) {
        try {
            $query = Product::query()->where('status', 'active');

            // Eager load relationships based on include parameter
            if ($request->has('include')) {
                $includes = explode(',', $request->include);
                if (in_array('category', $includes)) {
                    $query->with('category');
                }
                if (in_array('images', $includes)) {
                    // Images are stored as JSON in the database, no need for relationship
                }
            } else {
                // Default eager loading
                $query->with('category');
            }

            // Enhanced search functionality
            if ($search = $request->query('search')) {
                $query->where(function($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                      ->orWhere('description', 'like', "%{$search}%")
                      ->orWhere('material', 'like', "%{$search}%")
                      ->orWhere('color', 'like', "%{$search}%")
                      ->orWhere('note', 'like', "%{$search}%")
                      ->orWhere('features', 'like', "%{$search}%")
                      ->orWhereHas('category', function($categoryQuery) use ($search) {
                          $categoryQuery->where('name', 'like', "%{$search}%");
                      });
                });
            }

            // Category filter
            if ($categoryId = $request->query('category')) {
                $query->where('category_id', $categoryId);
            }

            // Sorting (latest products first by default)
            $sort = $request->query('sort', 'latest');
            if ($sort === 'oldest') {
                $query->orderBy('created_at', 'asc');
            } else {
                $query->orderBy('created_at', 'desc');
            }

            // Limit results only if explicitly requested
            if ($request->has('limit')) {
                $limit = (int) $request->query('limit');
                $limit = min($limit, 100);
                $query->limit($limit);
            }

            // Optimized: Use select to reduce data transfer
            $products = $query->select([
                'id', 'name', 'description', 'price', 'prices', 'available_sizes',
                'size_dimensions', 'size_weights', 'category_id',
                'material', 'color', 'features', 'image', 'images', 'status',
                'weight', 'dimensions', 'compartments', 'note', 'stock_quantity',
                'created_at', 'updated_at'
            ])->get();

            // Format response to include images properly
            $formattedProducts = $products->map(function($product) {
                try {
                    return [
                        'id' => $product->id,
                        'name' => $product->name,
                        'price' => $product->price,
                        'prices' => $product->prices,
                        'size_dimensions' => $product->size_dimensions,
                        'size_weights' => $product->size_weights,
                        'available_sizes' => $product->available_sizes,
                        'description' => $product->description,
                        'category_id' => $product->category_id,
                        'category' => $product->category,
                        'status' => $product->status,
                        'material' => $product->material,
                        'color' => $product->color,
                        'note' => $product->note,
                        'dimensions' => $product->dimensions,
                        'weight' => $product->weight,
                        'compartments' => $product->compartments,
                        'features' => $product->features,
                        'image' => $product->image_url, // Use accessor for HTTPS-compatible URL
                        'images' => $product->image_urls, // Use accessor for HTTPS-compatible URLs
                        'stock_quantity' => $product->stock_quantity,
                        'created_at' => $product->created_at,
                        'updated_at' => $product->updated_at
                    ];
                } catch (\Exception $e) {
                    \Log::error('Error formatting product: ' . $e->getMessage(), [
                        'product_id' => $product->id ?? null
                    ]);
                    // Return minimal product data on error
                    return [
                        'id' => $product->id ?? null,
                        'name' => $product->name ?? 'Unknown Product',
                        'error' => 'Error loading product data'
                    ];
                }
            })->filter(); // Remove any null entries

            return response()->json($formattedProducts, 200);

        } catch (\Exception $e) {
            \Log::error('Products API Error: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);
            // Return empty array on error instead of error response
            return response()->json([]);
        }
    }

    // Admin listing (all products) with enhanced search
    public function adminIndex(Request $request) {
        try {
            $query = Product::with('category');

            // Enhanced search functionality for admin
            if ($search = $request->query('search')) {
                $query->where(function($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                      ->orWhere('description', 'like', "%{$search}%")
                      ->orWhere('material', 'like', "%{$search}%")
                      ->orWhere('color', 'like', "%{$search}%")
                      ->orWhere('note', 'like', "%{$search}%")
                      ->orWhereHas('category', function($categoryQuery) use ($search) {
                          $categoryQuery->where('name', 'like', "%{$search}%");
                      });
                });
            }

            // Status filter for admin
            if ($status = $request->query('status')) {
                $query->where('status', $status);
            }

            $limit = $request->query('limit', 5);
            $products = $query->orderBy('created_at', 'desc')->paginate($limit);
            
            // Format response to use HTTPS-compatible image URLs
            $products->getCollection()->transform(function($product) {
                $productArray = $product->toArray();
                $productArray['image'] = $product->image_url; // Use accessor for HTTPS
                $productArray['images'] = $product->image_urls; // Use accessor for HTTPS
                return $productArray;
            });
            
            return response()->json($products, 200);

        } catch (\Exception $e) {
            \Log::error('Admin Products API Error: ' . $e->getMessage());
            return response()->json(['error' => 'Server error'], 500);
        }
    }

    public function show($id) {
        try {
            $product = Product::with('category')->findOrFail($id);
            
            // Format response to use HTTPS-compatible image URLs
            $productArray = $product->toArray();
            $productArray['image'] = $product->image_url; // Use accessor for HTTPS
            $productArray['images'] = $product->image_urls; // Use accessor for HTTPS
            
            return response()->json($productArray, 200);
        } catch (\Exception $e) {
            \Log::error('Product Show API Error: ' . $e->getMessage());
            // Return null on error instead of error response
            return response()->json(null, 404);
        }
    }

    // Create product
    public function store(Request $request) {
        $request->validate([
            'name' => 'required|string|max:255',
            'price' => 'nullable|numeric',
            'prices' => 'nullable|array',
            'size_dimensions' => 'nullable|array',
            'size_weights' => 'nullable|array',
            'category_id' => 'required|exists:categories,id',
            'description' => 'nullable|string',
            'main_image' => 'nullable|file|image|max:51200',
            'images' => 'nullable|array',
            'images.*' => 'nullable|file|image|max:51200',
            'status' => 'required|in:active,inactive',
            'material' => 'nullable|string',
            'color' => 'nullable|string',
            'note' => 'nullable|string',
            'dimensions' => 'nullable|string',
            'weight' => 'nullable|string',
            'compartments' => 'nullable|string',
            'features' => 'nullable|array',
            'sizes' => 'nullable|array'
        ]);

        $category = \App\Models\Category::findOrFail($request->category_id);

        // Handle main image - store just the path (e.g., "products/filename.jpg")
        $disk = R2Helper::getStorageDisk();
        $imagePath = null;
        if ($request->hasFile('main_image')) {
            try {
                $imagePath = $request->file('main_image')->store('products', $disk);
            } catch (\Exception $e) {
                \Log::error('Failed to upload product main image: ' . $e->getMessage());
                return response()->json(['message' => 'Failed to upload main image'], 500);
            }
        }

        // Handle gallery images - store just the paths
        $imagesPaths = [];
        if ($request->hasFile('images')) {
            foreach ($request->file('images') as $file) {
                try {
                    $imagesPaths[] = $file->store('products', $disk);
                } catch (\Exception $e) {
                    \Log::error('Failed to upload product gallery image: ' . $e->getMessage());
                }
            }
        }

        // Pricing logic based on category type
        if (strtolower($category->type) === 'apparel') {
            $sizes = $request->sizes ?? [];
            $prices = $request->prices ?? [];
            $sizeDimensions = $request->size_dimensions ?? [];
            $sizeWeights = $request->size_weights ?? [];
            $price = null;
        } else {
            $sizes = null;
            $prices = null;
            $sizeDimensions = null;
            $sizeWeights = null;
            $price = $request->price ?? 0;
        }

        $product = Product::create([
            'name' => $request->name,
            'price' => $price,
            'prices' => $prices,
            'available_sizes' => $sizes,
            'description' => $request->description,
            'category_id' => $request->category_id,
            'status' => $request->status,
            'material' => $request->material,
            'color' => $request->color,
            'note' => $request->note,
            'dimensions' => $request->dimensions,
            'weight' => $request->weight,
            'compartments' => $request->compartments,
            'features' => $request->features,
            'size_dimensions' => $sizeDimensions,
            'size_weights' => $sizeWeights,
            'image' => $imagePath, // Store just the path: "products/filename.jpg"
            'images' => $imagesPaths // Store just the paths: ["products/file1.jpg", "products/file2.jpg"]
        ]);

        $product->load('category');
        
        // Format response to use HTTPS-compatible image URLs
        $productArray = $product->toArray();
        $productArray['image'] = $product->image_url; // Use accessor for HTTPS
        $productArray['images'] = $product->image_urls; // Use accessor for HTTPS
        
        return response()->json($productArray, 201);
    }

    // Update product with automatic cleanup when status changes to inactive
    public function update(Request $request, $id) {
        $request->validate([
            'name' => 'required|string|max:255',
            'price' => 'nullable|numeric',
            'prices' => 'nullable|array',
            'size_dimensions' => 'nullable|array',
            'size_weights' => 'nullable|array',
            'category_id' => 'required|exists:categories,id',
            'description' => 'nullable|string',
            'main_image' => 'nullable|file|image|max:51200',
            'main_image_url' => 'nullable|string',
            'images' => 'nullable|array',
            'images.*' => 'nullable|file|image|max:51200',
            'retain_images' => 'nullable|array',
            'retain_images.*' => 'nullable|string',
            'status' => 'required|in:active,inactive',
            'material' => 'nullable|string',
            'color' => 'nullable|string',
            'note' => 'nullable|string',
            'dimensions' => 'nullable|string',
            'weight' => 'nullable|string',
            'compartments' => 'nullable|string',
            'features' => 'nullable|array',
            'sizes' => 'nullable|array'
        ]);

        $product = Product::findOrFail($id);
        $category = \App\Models\Category::findOrFail($request->category_id);

        // Store old status to check if it changed to inactive
        $oldStatus = $product->status;
        $newStatus = $request->status;

        // Main image handling
        $disk = R2Helper::getStorageDisk();
        if ($request->hasFile('main_image')) {
            if ($product->image) {
                // Extract path from URL if it's a full URL, otherwise use as is
                $oldPath = $this->extractStoragePath($product->image);
                try {
                    Storage::disk($disk)->delete($oldPath);
                } catch (\Exception $e) {
                    \Log::warning('Failed to delete old product main image: ' . $e->getMessage());
                }
            }
            try {
                $product->image = $request->file('main_image')->store('products', $disk); // Store just the path
            } catch (\Exception $e) {
                \Log::error('Failed to upload product main image: ' . $e->getMessage());
                return response()->json(['message' => 'Failed to upload main image'], 500);
            }
        } elseif ($request->filled('main_image_url')) {
            $product->image = $request->main_image_url;
        }

        // Gallery images handling
        $existingImages = is_array($product->images) ? $product->images : ($product->images ? (array)$product->images : []);
        $retain = $request->input('retain_images', []);
        $toDelete = array_values(array_diff($existingImages, $retain));
        foreach ($toDelete as $oldUrl) {
            if ($oldUrl) {
                $oldPath = $this->extractStoragePath($oldUrl);
                try {
                    Storage::disk($disk)->delete($oldPath);
                } catch (\Exception $e) {
                    \Log::warning('Failed to delete old product image: ' . $e->getMessage());
                }
            }
        }

        $newImagesList = array_values($retain);
        $newUploads = $request->hasFile('images') ? $request->file('images') : [];
        $allowedRemaining = 5 - count($newImagesList);
        if (count($newUploads) > $allowedRemaining) {
            return response()->json([
                'message' => "You can only have up to 5 images. You tried to upload " . count($newUploads) . " files but only {$allowedRemaining} slots are available."
            ], 422);
        }
        foreach ($newUploads as $file) {
            try {
                $newImagesList[] = $file->store('products', $disk); // Store just the path
            } catch (\Exception $e) {
                \Log::error('Failed to upload product gallery image: ' . $e->getMessage());
            }
        }
        $product->images = $newImagesList;

        // Pricing logic based on category type
        if (strtolower($category->type) === 'apparel') {
            $sizes = $request->sizes ?? [];
            $prices = $request->prices ?? [];
            $sizeDimensions = $request->size_dimensions ?? [];
            $sizeWeights = $request->size_weights ?? [];
            $price = null;
        } else {
            $sizes = null;
            $prices = null;
            $sizeDimensions = null;
            $sizeWeights = null;
            $price = $request->price ?? 0;
        }

        $product->update([
            'name' => $request->name,
            'price' => $price,
            'prices' => $prices,
            'available_sizes' => $sizes,
            'description' => $request->description,
            'category_id' => $request->category_id,
            'status' => $newStatus,
            'material' => $request->material,
            'color' => $request->color,
            'note' => $request->note,
            'dimensions' => $request->dimensions,
            'weight' => $request->weight,
            'compartments' => $request->compartments,
            'features' => $request->features,
            'size_dimensions' => $sizeDimensions,
            'size_weights' => $sizeWeights,
        ]);

        // If status changed from active to inactive, remove from user collections
        if ($oldStatus === 'active' && $newStatus === 'inactive') {
            $this->removeProductFromUserCollections($product->id);
        }

        $product->load('category');
        
        // Format response to use HTTPS-compatible image URLs
        $productArray = $product->toArray();
        $productArray['image'] = $product->image_url; // Use accessor for HTTPS
        $productArray['images'] = $product->image_urls; // Use accessor for HTTPS
        
        return response()->json($productArray, 200);
    }

    // Remove product from all user wishlists and carts when deactivated
    public function deactivateProduct(Product $product)
    {
        try {
            DB::transaction(function () use ($product) {
                // Remove from all wishlists
                Wishlist::where('product_id', $product->id)->delete();
                
                // Remove from all carts
                Cart::where('product_id', $product->id)->delete();
                
                // Log the cleanup
                \Log::info("Product {$product->id} removed from all user collections due to deactivation");
            });
            
            return response()->json([
                'message' => 'Product removed from all user collections successfully',
                'removed_from_wishlists' => true,
                'removed_from_carts' => true
            ]);
            
        } catch (\Exception $e) {
            \Log::error("Failed to remove product {$product->id} from collections: " . $e->getMessage());
            return response()->json([
                'message' => 'Failed to remove product from collections',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    // Internal method to remove product from user collections
    private function removeProductFromUserCollections($productId)
    {
        try {
            \Log::info("Removing product {$productId} from user collections...");
            
            // Remove from all wishlists
            $wishlistCount = Wishlist::where('product_id', $productId)->delete();
            
            // Remove from all carts
            $cartCount = Cart::where('product_id', $productId)->delete();
            
            \Log::info("Product {$productId} removed from {$wishlistCount} wishlists and {$cartCount} carts");
            
            return [
                'wishlist_removals' => $wishlistCount,
                'cart_removals' => $cartCount
            ];
            
        } catch (\Exception $e) {
            \Log::error("Failed to remove product {$productId} from collections: " . $e->getMessage());
            throw $e;
        }
    }

    // Enhanced product deletion with cleanup
    public function destroy($id)
    {
        try {
            $product = Product::findOrFail($id);
            
            // Remove from user collections first
            $this->removeProductFromUserCollections($product->id);
            
            // Delete images from storage
            $disk = R2Helper::getStorageDisk();
            if ($product->image) {
                $oldPath = $this->extractStoragePath($product->image);
                try {
                    Storage::disk($disk)->delete($oldPath);
                } catch (\Exception $e) {
                    \Log::warning('Failed to delete product main image: ' . $e->getMessage());
                }
            }
            
            if ($product->images && is_array($product->images)) {
                foreach ($product->images as $imageUrl) {
                    if ($imageUrl) {
                        $oldPath = $this->extractStoragePath($imageUrl);
                        try {
                            Storage::disk($disk)->delete($oldPath);
                        } catch (\Exception $e) {
                            \Log::warning('Failed to delete product gallery image: ' . $e->getMessage());
                        }
                    }
                }
            }
            
            // Delete the product
            $product->delete();
            
            return response()->json([
                'message' => 'Product deleted successfully',
                'removed_from_collections' => true
            ], 200);
            
        } catch (\Exception $e) {
            \Log::error('Product Delete API Error: ' . $e->getMessage());
            return response()->json(['error' => 'Failed to delete product'], 500);
        }
    }

    // Get products with status filter for admin
    public function getProductsByStatus(Request $request, $status)
    {
        try {
            if (!in_array($status, ['active', 'inactive'])) {
                return response()->json(['error' => 'Invalid status'], 400);
            }
            
            $query = Product::with('category')->where('status', $status);
            
            // Search functionality
            if ($search = $request->query('search')) {
                $query->where(function($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                      ->orWhere('description', 'like', "%{$search}%")
                      ->orWhere('material', 'like', "%{$search}%")
                      ->orWhereHas('category', function($categoryQuery) use ($search) {
                          $categoryQuery->where('name', 'like', "%{$search}%");
                      });
                });
            }
            
            $products = $query->get();
            
            // Format response to use HTTPS-compatible image URLs
            $formattedProducts = $products->map(function($product) {
                $productArray = $product->toArray();
                $productArray['image'] = $product->image_url; // Use accessor for HTTPS
                $productArray['images'] = $product->image_urls; // Use accessor for HTTPS
                return $productArray;
            });
            
            return response()->json($formattedProducts, 200);
            
        } catch (\Exception $e) {
            \Log::error('Products by Status API Error: ' . $e->getMessage());
            return response()->json(['error' => 'Server error'], 500);
        }
    }

    // Bulk status update with automatic cleanup
    public function bulkUpdateStatus(Request $request)
    {
        $request->validate([
            'product_ids' => 'required|array',
            'product_ids.*' => 'exists:products,id',
            'status' => 'required|in:active,inactive'
        ]);
        
        try {
            $productIds = $request->product_ids;
            $newStatus = $request->status;
            
            DB::transaction(function () use ($productIds, $newStatus) {
                // Get current status of products
                $products = Product::whereIn('id', $productIds)->get();
                
                // Update status
                Product::whereIn('id', $productIds)->update(['status' => $newStatus]);
                
                // If setting to inactive, remove from collections
                if ($newStatus === 'inactive') {
                    foreach ($productIds as $productId) {
                        $this->removeProductFromUserCollections($productId);
                    }
                }
            });
            
            return response()->json([
                'message' => 'Products status updated successfully',
                'updated_count' => count($productIds),
                'status' => $newStatus
            ], 200);
            
        } catch (\Exception $e) {
            \Log::error('Bulk Status Update Error: ' . $e->getMessage());
            return response()->json(['error' => 'Failed to update products status'], 500);
        }
    }

    /**
     * Extract storage path from a full URL or return the path as is
     */
    private function extractStoragePath($urlOrPath)
    {
        // If it's already a relative path, return as is
        if (!filter_var($urlOrPath, FILTER_VALIDATE_URL)) {
            return $urlOrPath;
        }
        
        // Extract path from URL
        $parsed = parse_url($urlOrPath);
        $path = $parsed['path'] ?? '';
        
        // Remove /storage prefix if present
        $path = ltrim($path, '/');
        if (strpos($path, 'storage/') === 0) {
            $path = substr($path, 8); // Remove 'storage/' prefix
        }
        
        return $path;
    }
}
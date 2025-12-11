<?php

namespace App\Http\Controllers\Api;

use App\Models\Wishlist;
use App\Events\WishlistUpdated;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Http\Controllers\Controller;

class WishlistController extends Controller
{
    // Get all wishlist items for logged-in user
    public function index(Request $request)
    {
        try {
            $user = Auth::user();
            
            if (!$user) {
                return response()->json(['message' => 'Unauthorized'], 401);
            }

            $limit = $request->query('limit', 5);
            $limit = min((int) $limit, 100);

            // Load the related product data
            $wishlist = Wishlist::with('product')->where('user_id', $user->id)->limit($limit)->get();
            
            // Ensure product images use HTTPS URLs
            $formattedWishlist = $wishlist->map(function($item) {
                $itemArray = $item->toArray();
                if ($item->product) {
                    $itemArray['product']['image'] = $item->product->image_url;
                    $itemArray['product']['images'] = $item->product->image_urls;
                }
                return $itemArray;
            });

            return response()->json($formattedWishlist);
        } catch (\Exception $e) {
            \Log::error('Wishlist index error: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);
            
            // Return empty array on error instead of error response
            return response()->json([]);
        }
    }

    // Toggle item in wishlist (add/remove)
    public function toggle(Request $request)
    {
        $user = Auth::user();

        $request->validate([
            'product_id' => 'required|exists:products,id',
        ]);

        $wishlist = Wishlist::where('user_id', $user->id)
                            ->where('product_id', $request->product_id)
                            ->first();

        if ($wishlist) {
            // Load product before deleting for broadcast
            $wishlist->load('product');
            $productId = $wishlist->product_id;
            $wishlist->delete();
            
            // Broadcast real-time event
            event(new WishlistUpdated(null, 'removed', $user->id, $productId));
            
            return response()->json(['message' => 'Removed from wishlist']);
        } else {
            $wishlist = Wishlist::create([
                'user_id' => $user->id,
                'product_id' => $request->product_id,
            ]);

            // Load the product relation for frontend display
            $wishlist->load('product');

            // Broadcast real-time event
            event(new WishlistUpdated($wishlist, 'added', $user->id, $request->product_id));

            // Format response to use HTTPS-compatible image URLs
            $wishlistArray = $wishlist->toArray();
            if ($wishlist->product) {
                $wishlistArray['product']['image'] = $wishlist->product->image_url;
                $wishlistArray['product']['images'] = $wishlist->product->image_urls;
            }

            return response()->json([
                'message' => 'Added to wishlist',
                'data' => $wishlistArray
            ], 201);
        }
    }

    // Remove from wishlist using DELETE request
    public function remove($productId)
    {
        $user = Auth::user();

        $wishlist = Wishlist::where('user_id', $user->id)
                            ->where('product_id', $productId)
                            ->first();

        if (!$wishlist) {
            return response()->json(['message' => 'Wishlist item not found'], 404);
        }

        // Load product before deleting for broadcast
        $wishlist->load('product');
        $productId = $wishlist->product_id;
        $wishlist->delete();

        // Broadcast real-time event
        event(new WishlistUpdated(null, 'removed', $user->id, $productId));

        return response()->json(['message' => 'Removed from wishlist']);
    }
}

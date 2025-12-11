<?php

namespace App\Http\Controllers\ProfileDashboard;

use App\Http\Controllers\Controller;
use App\Models\Cart;
use App\Models\Product;
use App\Models\CustomProposal;
use App\Events\CartUpdated;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class CartController extends Controller
{
    // GET /api/cart - FIXED: Only use existing columns
    public function index(Request $request)
    {
        $user = Auth::user();
        if (!$user) return response()->json(['message' => 'Unauthorized'], 401);

        try {
            $limit = $request->query('limit', 5);
            $limit = min((int) $limit, 100);
            
            // FIXED: Only select columns that actually exist in products table
            $items = Cart::with([
                'product' => function($query) {
                    $query->select([
                        'id', 'name', 'price', 'prices', 'available_sizes', 
                        'description', 'category_id', 'status', 'material',
                        'features', 'image', 'images', 'created_at', 'updated_at'
                    ])->with(['category' => function($q) {
                        $q->select('id', 'name', 'type');
                    }]);
                },
                'customProposal' => function($query) {
                    $query->select([
                        'id', 'user_id', 'customer_id', 'name', 'customization_request',
                        'product_type', 'category', 'customer_name', 'customer_email',
                        'quantity', 'total_price', 'designer_message', 'material',
                        'features', 'images', 'size_options', 'created_at', 'updated_at'
                    ]);
                }
            ])
            ->where('user_id', $user->id)
            ->limit($limit)
            ->get();

            Log::info("Cart retrieved for user {$user->id}: {$items->count()} items");

            return response()->json(['items' => $items]);

        } catch (\Exception $e) {
            Log::error("Cart fetch error for user {$user->id}: " . $e->getMessage());
            return response()->json([
                'message' => 'Failed to fetch cart items',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    // POST /api/cart - FOR REGULAR PRODUCTS - FIXED
    public function store(Request $request)
    {
        $user = Auth::user();
        if (!$user) return response()->json(['message' => 'Unauthorized'], 401);

        try {
            $validated = $request->validate([
                'product_id' => 'required|exists:products,id',
                'quantity'   => 'required|integer|min:1',
                'size'       => 'nullable|string|max:50',
            ]);

            $selectedOption = isset($validated['size']) ? $validated['size'] : '';
            $product = Product::with(['category' => function($q) {
                $q->select('id', 'name', 'type');
            }])->findOrFail($validated['product_id']);

            $isApparel = $this->isApparelProduct($product);

            $optionPrice = null;
            $finalPrice = (float) (isset($product->price) ? $product->price : 0);

            if ($isApparel && $selectedOption) {
                $options = $this->parseJsonSafe($product->available_sizes);
                $prices  = $this->parseJsonSafe($product->prices);

                if (count($options) === count($prices)) {
                    $index = array_search($selectedOption, $options);
                    if ($index !== false && isset($prices[$index])) {
                        $optionPrice = (float) $prices[$index];
                        $finalPrice = $optionPrice;
                    }
                }
            }

            $cartItem = Cart::where('user_id', $user->id)
                ->where('product_id', $validated['product_id'])
                ->where('available_sizes', $selectedOption)
                ->where('is_customized', false)
                ->first();

            $totalPrice = $finalPrice * $validated['quantity'];

            $action = 'updated';
            if ($cartItem) {
                $cartItem->quantity += $validated['quantity'];
                $cartItem->price = $finalPrice;
                $cartItem->size_price = $isApparel ? $optionPrice : null;
                $cartItem->total_price = $finalPrice * $cartItem->quantity;
                $cartItem->save();
                
                Log::info("Cart item updated for user {$user->id}: {$product->name} (Qty: {$cartItem->quantity})");
            } else {
                $cartItem = Cart::create([
                    'user_id'         => $user->id,
                    'product_id'      => $validated['product_id'],
                    'available_sizes' => $selectedOption,
                    'quantity'        => $validated['quantity'],
                    'size_price'      => $isApparel ? $optionPrice : null,
                    'price'           => $finalPrice,
                    'total_price'     => $totalPrice,
                    'is_customized'   => false
                ]);
                $action = 'added';
                
                Log::info("Cart item created for user {$user->id}: {$product->name} (Qty: {$validated['quantity']})");
            }

            // FIXED: Only select existing columns
            $cartItem->load(['product' => function($query) {
                $query->select([
                    'id', 'name', 'price', 'prices', 'available_sizes', 
                    'description', 'category_id', 'status', 'material',
                    'features', 'image', 'images', 'created_at', 'updated_at'
                ])->with(['category' => function($q) {
                    $q->select('id', 'name', 'type');
                }]);
            }]);

            // Broadcast real-time event
            event(new CartUpdated($cartItem, $action, $user->id));

            return response()->json([
                'message' => 'Item added to cart',
                'item'    => $cartItem
            ], 201);

        } catch (\Exception $e) {
            Log::error("Cart store error for user {$user->id}: " . $e->getMessage());
            return response()->json([
                'message' => 'Failed to add item to cart',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    // POST /api/cart/custom-proposal - FOR CUSTOMIZED PROPOSALS
    public function storeCustomProposal(Request $request)
    {
        $user = Auth::user();
        if (!$user) return response()->json(['message' => 'Unauthorized'], 401);

        try {
            $validated = $request->validate([
                'custom_proposal_id' => 'required|exists:custom_proposals,id',
                'quantity' => 'required|integer|min:1',
                'selected_size' => 'nullable|array',
            ]);

            $proposal = CustomProposal::findOrFail($validated['custom_proposal_id']);
            
            // Check if proposal belongs to user
            if ($proposal->customer_id !== $user->id) {
                return response()->json(['message' => 'Unauthorized access to this proposal'], 403);
            }

            $selectedSize = isset($validated['selected_size']) ? $validated['selected_size'] : null;
            
            $unitPrice = (float) (isset($proposal->total_price) ? $proposal->total_price : 0);

            // Calculate price based on size if apparel
            if ($proposal->category === 'apparel' && $selectedSize && isset($selectedSize['price'])) {
                $unitPrice = (float) $selectedSize['price'];
            }

            $totalPrice = $unitPrice * $validated['quantity'];

            // Handle available_sizes for both apparel and non-apparel items
            $availableSizes = '';
            if ($selectedSize) {
                $availableSizes = json_encode($selectedSize);
            } else if ($proposal->category === 'apparel') {
                // For apparel without selected size, use default
                $availableSizes = json_encode(['label' => 'One Size', 'price' => $unitPrice]);
            } else {
                // For non-apparel items, use empty JSON array
                $availableSizes = '[]';
            }

            // Include available_sizes in the search to allow different sizes for same proposal
            $cartItem = Cart::where('user_id', $user->id)
                ->where('custom_proposal_id', $validated['custom_proposal_id'])
                ->where('available_sizes', $availableSizes)
                ->where('is_customized', true)
                ->first();

            $action = 'updated';
            if ($cartItem) {
                $cartItem->quantity += $validated['quantity'];
                $cartItem->price = $unitPrice;
                $cartItem->total_price = $unitPrice * $cartItem->quantity;
                $cartItem->save();
                
                $sizeLabel = $selectedSize && isset($selectedSize['label']) ? $selectedSize['label'] : 'One Size';
                Log::info("Custom cart item updated for user {$user->id}: {$proposal->name} (Size: {$sizeLabel}, Qty: {$cartItem->quantity})");
            } else {
                $cartItem = Cart::create([
                    'user_id' => $user->id,
                    'custom_proposal_id' => $validated['custom_proposal_id'],
                    'available_sizes' => $availableSizes,
                    'quantity' => $validated['quantity'],
                    'price' => $unitPrice,
                    'total_price' => $totalPrice,
                    'is_customized' => true
                ]);
                $action = 'added';
                
                $sizeLabel = $selectedSize && isset($selectedSize['label']) ? $selectedSize['label'] : 'One Size';
                Log::info("Custom cart item created for user {$user->id}: {$proposal->name} (Size: {$sizeLabel}, Qty: {$validated['quantity']})");
            }

            $cartItem->load(['customProposal' => function($query) {
                $query->select([
                    'id', 'user_id', 'customer_id', 'name', 'customization_request',
                    'product_type', 'category', 'customer_name', 'customer_email',
                    'quantity', 'total_price', 'designer_message', 'material',
                    'features', 'images', 'size_options', 'created_at', 'updated_at'
                ]);
            }]);

            // Broadcast real-time event
            event(new CartUpdated($cartItem, $action, $user->id));

            return response()->json([
                'message' => 'Custom proposal added to cart',
                'item' => $cartItem
            ], 201);

        } catch (\Exception $e) {
            Log::error("Custom proposal cart store error for user {$user->id}: " . $e->getMessage());
            return response()->json([
                'message' => 'Failed to add custom proposal to cart',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    // PUT /api/cart/{id} - FIXED column selection
    public function update(Request $request, $id)
    {
        $user = Auth::user();
        if (!$user) return response()->json(['message' => 'Unauthorized'], 401);

        try {
            $validated = $request->validate([
                'quantity' => 'required|integer|min:1'
            ]);

            $cartItem = Cart::where('user_id', $user->id)->findOrFail($id);
            
            // Handle regular products
            if (!$cartItem->is_customized) {
                $product = $cartItem->product;
                $finalPrice = (float) (isset($cartItem->price) ? $cartItem->price : 0);
                $optionPrice = $cartItem->size_price ? (float) $cartItem->size_price : null;

                if ($finalPrice <= 0) {
                    $finalPrice = (float) (isset($product->price) ? $product->price : 0);
                    $isApparel = $this->isApparelProduct($product);

                    if ($isApparel && $cartItem->available_sizes) {
                        $options = $this->parseJsonSafe($product->available_sizes);
                        $prices  = $this->parseJsonSafe($product->prices);

                        if (count($options) === count($prices)) {
                            $index = array_search($cartItem->available_sizes, $options);
                            if ($index !== false) {
                                $optionPrice = (float) $prices[$index];
                                $finalPrice = $optionPrice;
                            }
                        }
                    }
                }

                $cartItem->price = $finalPrice;
                $cartItem->size_price = $optionPrice;
            }

            $oldQuantity = $cartItem->quantity;
            $cartItem->quantity = $validated['quantity'];
            $cartItem->total_price = (float) $cartItem->price * $cartItem->quantity;
            $cartItem->save();

            // FIXED: Only select existing columns
            if ($cartItem->is_customized) {
                $cartItem->load(['customProposal' => function($query) {
                    $query->select([
                        'id', 'user_id', 'customer_id', 'name', 'customization_request',
                        'product_type', 'category', 'customer_name', 'customer_email',
                        'quantity', 'total_price', 'designer_message', 'material',
                        'features', 'images', 'size_options', 'created_at', 'updated_at'
                    ]);
                }]);
            } else {
                $cartItem->load(['product' => function($query) {
                    $query->select([
                        'id', 'name', 'price', 'prices', 'available_sizes', 
                        'description', 'category_id', 'status', 'material',
                        'features', 'image', 'images', 'created_at', 'updated_at'
                    ])->with(['category' => function($q) {
                        $q->select('id', 'name', 'type');
                    }]);
                }]);
            }

            $itemName = $cartItem->is_customized ? 
                ($cartItem->customProposal ? $cartItem->customProposal->name : 'Custom Item') : 
                ($cartItem->product ? $cartItem->product->name : 'Product');

            Log::info("Cart item quantity updated for user {$user->id}: {$itemName} (Qty: {$oldQuantity} -> {$cartItem->quantity})");

            // Broadcast real-time event
            event(new CartUpdated($cartItem, 'updated', $user->id));

            return response()->json([
                'message' => 'Cart updated',
                'item'    => $cartItem
            ]);

        } catch (\Exception $e) {
            Log::error("Cart update error for user {$user->id}, item {$id}: " . $e->getMessage());
            return response()->json([
                'message' => 'Failed to update cart item',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    // DELETE /api/cart/{id}
    public function destroy($id)
    {
        $user = Auth::user();
        if (!$user) return response()->json(['message' => 'Unauthorized'], 401);

        try {
            $cartItem = Cart::where('user_id', $user->id)->findOrFail($id);
            
            $itemName = $cartItem->is_customized ? 
                ($cartItem->customProposal ? $cartItem->customProposal->name : 'Custom Item') : 
                ($cartItem->product ? $cartItem->product->name : 'Product');
            
            // Load relations before deleting for broadcast
            if ($cartItem->is_customized) {
                $cartItem->load('customProposal');
            } else {
                $cartItem->load('product');
            }
                
            $cartItem->delete();

            // Broadcast real-time event (with deleted item data)
            event(new CartUpdated($cartItem, 'removed', $user->id));

            Log::info(" Cart item removed for user {$user->id}: {$itemName}");

            return response()->json(['message' => 'Item removed']);

        } catch (\Exception $e) {
            Log::error("Cart delete error for user {$user->id}, item {$id}: " . $e->getMessage());
            return response()->json([
                'message' => 'Failed to remove item from cart',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    // DELETE /api/cart/clear - Clear entire cart
    public function clear()
    {
        $user = Auth::user();
        if (!$user) return response()->json(['message' => 'Unauthorized'], 401);

        try {
            $deletedCount = Cart::where('user_id', $user->id)->delete();

            // Create a dummy cart item for broadcast (since all items are deleted)
            $dummyCart = new Cart();
            $dummyCart->id = 0;
            $dummyCart->user_id = $user->id;
            
            // Broadcast real-time event for cart cleared
            event(new CartUpdated($dummyCart, 'cleared', $user->id));

            Log::info("🧹 Cart cleared for user {$user->id}: {$deletedCount} items deleted");

            return response()->json([
                'message' => 'Cart cleared successfully',
                'deleted_count' => $deletedCount
            ]);

        } catch (\Exception $e) {
            Log::error("Cart clear error for user {$user->id}: " . $e->getMessage());
            return response()->json([
                'message' => 'Failed to clear cart',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    // GET /api/cart/count - Get cart items count
    public function count()
    {
        $user = Auth::user();
        if (!$user) return response()->json(['message' => 'Unauthorized'], 401);

        try {
            $cartCount = Cart::where('user_id', $user->id)->count();

            return response()->json([
                'count' => $cartCount
            ]);

        } catch (\Exception $e) {
            Log::error("Cart count error for user {$user->id}: " . $e->getMessage());
            return response()->json([
                'message' => 'Failed to get cart count',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    // GET /api/cart/total - Get cart total price with proper relationships
    public function total()
    {
        $user = Auth::user();
        if (!$user) return response()->json(['message' => 'Unauthorized'], 401);

        try {
            // FIXED: Only select existing columns
            $cartItems = Cart::with([
                'product' => function($query) {
                    $query->select([
                        'id', 'name', 'price', 'prices', 'available_sizes', 
                        'description', 'category_id', 'status', 'material',
                        'features', 'image', 'images', 'created_at', 'updated_at'
                    ]);
                },
                'customProposal' => function($query) {
                    $query->select([
                        'id', 'user_id', 'customer_id', 'name', 'customization_request',
                        'product_type', 'category', 'customer_name', 'customer_email',
                        'quantity', 'total_price', 'designer_message', 'material',
                        'features', 'images', 'size_options', 'created_at', 'updated_at'
                    ]);
                }
            ])
            ->where('user_id', $user->id)
            ->get();

            $total = $cartItems->sum(function($item) {
                return (float) $item->price * $item->quantity;
            });

            return response()->json([
                'total' => $total,
                'formatted_total' => '₱' . number_format($total, 2)
            ]);

        } catch (\Exception $e) {
            Log::error("Cart total error for user {$user->id}: " . $e->getMessage());
            return response()->json([
                'message' => 'Failed to calculate cart total',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    // POST /api/cart/validate
    public function validateCart(Request $request)
    {
        $user = Auth::user();
        $items = $request->input('items', []);
        $valid = true;
        $invalidItems = [];

        try {
            foreach ($items as $index => $item) {
                // Handle regular products
                if (isset($item['product_id'])) {
                    $product = Product::find($item['product_id']);
                    
                    if (!$product) {
                        $valid = false;
                        $invalidItems[] = [
                            'index' => $index,
                            'product_id' => $item['product_id'],
                            'reason' => 'Product not found'
                        ];
                        continue;
                    }

                    if (!$product->is_active) {
                        $valid = false;
                        $invalidItems[] = [
                            'index' => $index,
                            'product_id' => $item['product_id'],
                            'product_name' => $product->name,
                            'reason' => 'Product is no longer available'
                        ];
                        continue;
                    }

                    $requestedQuantity = isset($item['quantity']) ? $item['quantity'] : 1;
                    // Removed stock_quantity check since column doesn't exist
                    // You can add this back when you have the stock_quantity column
                }
                // Handle custom proposals
                else if (isset($item['custom_proposal_id'])) {
                    $proposal = CustomProposal::find($item['custom_proposal_id']);
                    
                    if (!$proposal) {
                        $valid = false;
                        $invalidItems[] = [
                            'index' => $index,
                            'custom_proposal_id' => $item['custom_proposal_id'],
                            'reason' => 'Custom proposal not found'
                        ];
                        continue;
                    }
                }
            }

            Log::info("🔍 Cart validation for user: " . ($user->id ? $user->id : 'guest') . " - Valid: " . ($valid ? 'Yes' : 'No'));

            return response()->json([
                'valid' => $valid,
                'invalid_items' => $invalidItems
            ]);

        } catch (\Exception $e) {
            Log::error("Cart validation error: " . $e->getMessage());
            return response()->json([
                'message' => 'Failed to validate cart',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    // POST /api/cart/bulk-update - Update multiple cart items at once
    public function bulkUpdate(Request $request)
    {
        $user = Auth::user();
        if (!$user) return response()->json(['message' => 'Unauthorized'], 401);

        try {
            $validated = $request->validate([
                'items' => 'required|array|min:1',
                'items.*.id' => 'required|integer|exists:carts,id',
                'items.*.quantity' => 'required|integer|min:1'
            ]);

            $updatedItems = [];
            
            foreach ($validated['items'] as $itemData) {
                $cartItem = Cart::where('user_id', $user->id)
                    ->where('id', $itemData['id'])
                    ->first();

                if ($cartItem) {
                    $oldQuantity = $cartItem->quantity;
                    $cartItem->quantity = $itemData['quantity'];
                    $cartItem->total_price = (float) $cartItem->price * $cartItem->quantity;
                    $cartItem->save();
                    
                    // FIXED: Only select existing columns
                    if ($cartItem->is_customized) {
                        $cartItem->load(['customProposal' => function($query) {
                            $query->select([
                                'id', 'user_id', 'customer_id', 'name', 'customization_request',
                                'product_type', 'category', 'customer_name', 'customer_email',
                                'quantity', 'total_price', 'designer_message', 'material',
                                'features', 'images', 'size_options', 'created_at', 'updated_at'
                            ]);
                        }]);
                    } else {
                        $cartItem->load(['product' => function($query) {
                            $query->select([
                                'id', 'name', 'price', 'prices', 'available_sizes', 
                                'description', 'category_id', 'status', 'material',
                                'features', 'image', 'images', 'created_at', 'updated_at'
                            ])->with(['category' => function($q) {
                                $q->select('id', 'name', 'type');
                            }]);
                        }]);
                    }

                    $updatedItems[] = $cartItem;
                    
                    $itemName = $cartItem->is_customized ? 
                        ($cartItem->customProposal ? $cartItem->customProposal->name : 'Custom Item') : 
                        ($cartItem->product ? $cartItem->product->name : 'Product');
                        
                    Log::info("Bulk update - User {$user->id}: {$itemName} (Qty: {$oldQuantity} -> {$cartItem->quantity})");
                }
            }

            return response()->json([
                'message' => 'Cart items updated successfully',
                'updated_items' => $updatedItems
            ]);

        } catch (\Exception $e) {
            Log::error("Cart bulk update error for user {$user->id}: " . $e->getMessage());
            return response()->json([
                'message' => 'Failed to bulk update cart items',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    // POST /api/cart/sync - Sync cart with frontend (for guest to logged-in user conversion)
    public function sync(Request $request)
    {
        $user = Auth::user();
        if (!$user) return response()->json(['message' => 'Unauthorized'], 401);

        try {
            $validated = $request->validate([
                'items' => 'required|array',
                'items.*.product_id' => 'nullable|integer|exists:products,id',
                'items.*.custom_proposal_id' => 'nullable|integer|exists:custom_proposals,id',
                'items.*.quantity' => 'required|integer|min:1',
                'items.*.size' => 'nullable|string|max:50',
                'items.*.price' => 'nullable|numeric',
                'items.*.size_price' => 'nullable|numeric',
                'items.*.is_customized' => 'boolean',
            ]);

            $syncedItems = [];
            $skippedItems = [];

            foreach ($validated['items'] as $itemData) {
                // Handle regular products
                if (isset($itemData['product_id']) && !(isset($itemData['is_customized']) ? $itemData['is_customized'] : false)) {
                    $product = Product::find($itemData['product_id']);
                    if (!$product || !$product->is_active) {
                        $skippedItems[] = [
                            'product_id' => $itemData['product_id'],
                            'reason' => 'Product not available'
                        ];
                        continue;
                    }

                    $size = isset($itemData['size']) ? $itemData['size'] : '';

                    $existingItem = Cart::where('user_id', $user->id)
                        ->where('product_id', $itemData['product_id'])
                        ->where('available_sizes', $size)
                        ->where('is_customized', false)
                        ->first();

                    if ($existingItem) {
                        $existingItem->quantity += $itemData['quantity'];
                        $existingItem->total_price = (float) $existingItem->price * $existingItem->quantity;
                        $existingItem->save();
                        // FIXED: Only select existing columns
                        $existingItem->load(['product' => function($query) {
                            $query->select([
                                'id', 'name', 'price', 'prices', 'available_sizes', 
                                'description', 'category_id', 'status', 'material',
                                'features', 'image', 'images', 'created_at', 'updated_at'
                            ])->with(['category' => function($q) {
                                $q->select('id', 'name', 'type');
                            }]);
                        }]);
                        $syncedItems[] = $existingItem;
                    } else {
                        $cartItem = Cart::create([
                            'user_id' => $user->id,
                            'product_id' => $itemData['product_id'],
                            'available_sizes' => $size,
                            'quantity' => $itemData['quantity'],
                            'size_price' => isset($itemData['size_price']) ? $itemData['size_price'] : null,
                            'price' => (float) (isset($itemData['price']) ? $itemData['price'] : $product->price),
                            'total_price' => (float) (isset($itemData['price']) ? $itemData['price'] : $product->price) * $itemData['quantity'],
                            'is_customized' => false
                        ]);
                        // FIXED: Only select existing columns
                        $cartItem->load(['product' => function($query) {
                            $query->select([
                                'id', 'name', 'price', 'prices', 'available_sizes', 
                                'description', 'category_id', 'status', 'material',
                                'features', 'image', 'images', 'created_at', 'updated_at'
                            ])->with(['category' => function($q) {
                                $q->select('id', 'name', 'type');
                            }]);
                        }]);
                        $syncedItems[] = $cartItem;
                    }
                }
                // Handle custom proposals
                else if (isset($itemData['custom_proposal_id']) && (isset($itemData['is_customized']) ? $itemData['is_customized'] : false)) {
                    $proposal = CustomProposal::find($itemData['custom_proposal_id']);
                    if (!$proposal) {
                        $skippedItems[] = [
                            'custom_proposal_id' => $itemData['custom_proposal_id'],
                            'reason' => 'Custom proposal not available'
                        ];
                        continue;
                    }

                    // Handle available_sizes for sync as well
                    $availableSizes = isset($itemData['size']) ? $itemData['size'] : '[]';
                    if (empty($availableSizes)) {
                        $availableSizes = '[]';
                    }

                    // Include available_sizes in the search
                    $existingItem = Cart::where('user_id', $user->id)
                        ->where('custom_proposal_id', $itemData['custom_proposal_id'])
                        ->where('available_sizes', $availableSizes)
                        ->where('is_customized', true)
                        ->first();

                    if ($existingItem) {
                        $existingItem->quantity += $itemData['quantity'];
                        $existingItem->total_price = (float) $existingItem->price * $existingItem->quantity;
                        $existingItem->save();
                        $existingItem->load(['customProposal' => function($query) {
                            $query->select([
                                'id', 'user_id', 'customer_id', 'name', 'customization_request',
                                'product_type', 'category', 'customer_name', 'customer_email',
                                'quantity', 'total_price', 'designer_message', 'material',
                                'features', 'images', 'size_options', 'created_at', 'updated_at'
                            ]);
                        }]);
                        $syncedItems[] = $existingItem;
                    } else {
                        $cartItem = Cart::create([
                            'user_id' => $user->id,
                            'custom_proposal_id' => $itemData['custom_proposal_id'],
                            'available_sizes' => $availableSizes,
                            'quantity' => $itemData['quantity'],
                            'price' => (float) (isset($itemData['price']) ? $itemData['price'] : $proposal->total_price),
                            'total_price' => (float) (isset($itemData['price']) ? $itemData['price'] : $proposal->total_price) * $itemData['quantity'],
                            'is_customized' => true
                        ]);
                        $cartItem->load(['customProposal' => function($query) {
                            $query->select([
                                'id', 'user_id', 'customer_id', 'name', 'customization_request',
                                'product_type', 'category', 'customer_name', 'customer_email',
                                'quantity', 'total_price', 'designer_message', 'material',
                                'features', 'images', 'size_options', 'created_at', 'updated_at'
                            ]);
                        }]);
                        $syncedItems[] = $cartItem;
                    }
                }
            }

            Log::info("Cart synced for user {$user->id}: " . count($syncedItems) . " items synced, " . count($skippedItems) . " skipped");

            return response()->json([
                'message' => 'Cart synced successfully',
                'synced_items' => $syncedItems,
                'skipped_items' => $skippedItems
            ]);

        } catch (\Exception $e) {
            Log::error("Cart sync error for user {$user->id}: " . $e->getMessage());
            return response()->json([
                'message' => 'Failed to sync cart',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    // --- Helpers ---
    private function isApparelProduct($product)
    {
        if ($product->category) {
            $apparelCategories = ['T-Shirts', 'Pants', 'Shorts', 'Jackets', 'Hoodies', 'Dresses', 'Skirts'];
            if (in_array($product->category->name, $apparelCategories)) return true;
            if (isset($product->category->type) && $product->category->type === 'apparel') return true;
        }
        return false;
    }

    private function parseJsonSafe($data)
    {
        if (is_array($data)) return $data;
        if (is_string($data)) {
            $decoded = @json_decode($data, true);
            return is_array($decoded) ? $decoded : [];
        }
        return [];
    }
}
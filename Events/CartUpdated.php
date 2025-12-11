<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use App\Models\Cart;

class CartUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $cartItem;
    public $action; // 'added', 'updated', 'removed'
    public $userId;

    public function __construct(Cart $cartItem, string $action, int $userId)
    {
        $this->cartItem = $cartItem;
        $this->action = $action;
        $this->userId = $userId;
        
        // Only load relations if cart item exists and has an ID (not a dummy object or deleted item)
        if (isset($cartItem->id) && $cartItem->id && property_exists($cartItem, 'exists') && $cartItem->exists) {
            if ($cartItem->is_customized) {
                // Load custom proposal relation
                $this->cartItem->load(['customProposal' => function($query) {
                    $query->select([
                        'id', 'user_id', 'customer_id', 'name', 'customization_request',
                        'product_type', 'category', 'customer_name', 'customer_email',
                        'quantity', 'total_price', 'designer_message', 'material',
                        'features', 'images', 'size_options', 'created_at', 'updated_at'
                    ]);
                }]);
            } else {
                // Load product relation for frontend
                $this->cartItem->load(['product' => function($query) {
                    $query->select([
                        'id', 'name', 'price', 'prices', 'available_sizes', 
                        'description', 'category_id', 'status', 'material',
                        'features', 'image', 'images', 'created_at', 'updated_at'
                    ])->with(['category' => function($q) {
                        $q->select('id', 'name', 'type');
                    }]);
                }]);
            }
        }
    }

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('cart.' . $this->userId);
    }

    public function broadcastWith(): array
    {
        $data = [
            'action' => $this->action,
            'cart_count' => Cart::where('user_id', $this->userId)->count(),
            'cart_total' => Cart::where('user_id', $this->userId)->sum('total_price') ?? 0,
        ];
        
        // Only include item data if cart item exists and has an ID
        // For deleted items, the ID still exists but exists property is false
        if (isset($this->cartItem->id) && $this->cartItem->id) {
            $data['item'] = $this->cartItem->toArray();
        } else {
            $data['item'] = null;
        }
        
        return $data;
    }

    public function broadcastAs(): string
    {
        return 'cart.updated';
    }
}


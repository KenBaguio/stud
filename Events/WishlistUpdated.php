<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use App\Models\Wishlist;

class WishlistUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $wishlistItem;
    public $action; // 'added', 'removed'
    public $userId;
    public $productId;

    public function __construct($wishlistItem, string $action, int $userId, int $productId)
    {
        $this->wishlistItem = $wishlistItem;
        $this->action = $action;
        $this->userId = $userId;
        $this->productId = $productId;
        
        // Load product relation if item exists
        if ($wishlistItem instanceof Wishlist) {
            $this->wishlistItem->load('product');
        }
    }

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('wishlist.' . $this->userId);
    }

    public function broadcastWith(): array
    {
        return [
            'action' => $this->action,
            'product_id' => $this->productId,
            'item' => $this->wishlistItem ? $this->wishlistItem->toArray() : null,
            'wishlist_count' => Wishlist::where('user_id', $this->userId)->count(),
        ];
    }

    public function broadcastAs(): string
    {
        return 'wishlist.updated';
    }
}


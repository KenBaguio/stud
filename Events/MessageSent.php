<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use App\Models\Message;
use Illuminate\Support\Arr;

class MessageSent implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $message;

    public function __construct(Message $message)
    {
        $this->message = $message;
        $this->message->loadMissing(['sender', 'receiver']);
        
        // Ensure all data is properly parsed
        if ($this->message->product && is_string($this->message->product)) {
            try {
                $this->message->product = json_decode($this->message->product, true);
            } catch (\Exception $e) {
                $this->message->product = null;
            }
        }
        
        if ($this->message->images && is_string($this->message->images)) {
            try {
                $this->message->images = json_decode($this->message->images, true);
            } catch (\Exception $e) {
                $this->message->images = [];
            }
        }
        
        $this->message->isProductReference = !!$this->message->product;
        $this->message->hasImages = !empty($this->message->images);
    }

    /**
     * Broadcast to multiple channels for real-time delivery
     * - Conversation channel: Both customer and clerk receive messages
     * - Receiver channel: Direct delivery to receiver (customer or clerk)
     * - Sender channel: Echo back to sender (confirmation)
     * 
     * This ensures:
     * - Customer receives clerk messages in real-time
     * - Clerk receives customer messages in real-time (customer service)
     * - Both can see messages immediately
     */
    public function broadcastOn(): array
    {
        $channels = [];

        // 1. Conversation channel - both customer and clerk subscribe to this
        // This ensures all participants in the conversation receive the message
        if ($this->message->conversation_id) {
            $channels[] = new PrivateChannel('chat.conversation.' . $this->message->conversation_id);
        }

        // 2. Receiver channel - direct delivery to the receiver
        // Customer sends -> Clerk receives via chat.{clerk_id}
        // Clerk sends -> Customer receives via chat.{customer_id}
        if ($this->message->receiver_id) {
            $channels[] = new PrivateChannel('chat.' . $this->message->receiver_id);
        }

        // 3. Sender channel - echo back to sender for confirmation
        // This ensures sender sees their own message immediately
        if ($this->message->sender_id && $this->message->sender_id !== $this->message->receiver_id) {
            $channels[] = new PrivateChannel('chat.' . $this->message->sender_id);
        }

        return $channels;
    }

    public function broadcastAs(): string
    {
        return 'message.sent';
    }

    public function broadcastWith(): array
    {
        // Include receiver information for better frontend handling
        return [
            'message' => [
                'id' => $this->message->id,
                'sender_id' => $this->message->sender_id,
                'receiver_id' => $this->message->receiver_id,
                'conversation_id' => $this->message->conversation_id,
                'message' => $this->message->message,
                'product' => $this->message->product,
                'images' => $this->message->images,
                'isProductReference' => $this->message->isProductReference,
                'hasImages' => $this->message->hasImages,
                'is_quick_option' => $this->message->is_quick_option,
                'created_at' => $this->message->created_at,
                'updated_at' => $this->message->updated_at,
                'sender' => $this->message->sender
                    ? Arr::only($this->message->sender->toArray(), [
                        'id',
                        'first_name',
                        'last_name',
                        'email',
                        'role',
                        'display_name',
                        'profile_image',
                    ])
                    : null,
                'receiver' => $this->message->receiver
                    ? Arr::only($this->message->receiver->toArray(), [
                        'id',
                        'first_name',
                        'last_name',
                        'email',
                        'role',
                    ])
                    : null,
            ]
        ];
    }
}
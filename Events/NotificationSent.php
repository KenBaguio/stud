<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class NotificationSent implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $notification;

    public function __construct($notification)
    {
        // Load sender relationship for customer information in real-time notifications
        if ($notification && !$notification->relationLoaded('sender')) {
            $notification->load('sender');
        }
        $this->notification = $notification;
    }

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('notifications.' . $this->notification->receiver_id);
    }

    public function broadcastWith(): array
    {
        // Include sender information in the broadcast for easier frontend access
        $notificationArray = $this->notification->toArray();
        
        // Add sender information if available
        if ($this->notification->sender) {
            $notificationArray['sender_name'] = $this->notification->sender->display_name 
                ?? trim(($this->notification->sender->first_name ?? '') . ' ' . ($this->notification->sender->last_name ?? ''))
                ?: 'Customer';
            $notificationArray['sender_email'] = $this->notification->sender->email;
        }
        
        // Extract customer info from data if available for message notifications
        if ($this->notification->type === 'message' && is_array($this->notification->data)) {
            $notificationArray['customer_id'] = $this->notification->data['customer_id'] ?? $this->notification->sender_id;
            $notificationArray['customer_name'] = $this->notification->data['customer_name'] ?? $notificationArray['sender_name'] ?? 'Customer';
        }
        
        return ['notification' => $notificationArray];
    }

    public function broadcastAs(): string
    {
        return 'notification.sent';
    }
}

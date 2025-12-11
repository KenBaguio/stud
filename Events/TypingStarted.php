<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class TypingStarted implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $userId;
    public $conversationId;
    public $user;

    public function __construct($userId, $conversationId, $user = null)
    {
        $this->userId = $userId;
        $this->conversationId = $conversationId;
        $this->user = $user;
    }

    public function broadcastOn(): array
    {
        $channels = [];

        // Broadcast to conversation channel
        if ($this->conversationId) {
            $channels[] = new PrivateChannel('chat.conversation.' . $this->conversationId);
        }

        return $channels;
    }

    public function broadcastAs(): string
    {
        return 'typing.started';
    }

    public function broadcastWith(): array
    {
        return [
            'user_id' => $this->userId,
            'conversation_id' => $this->conversationId,
            'user' => $this->user ? [
                'id' => $this->user->id,
                'first_name' => $this->user->first_name,
                'last_name' => $this->user->last_name,
                'display_name' => $this->user->display_name,
                'role' => $this->user->role,
                'profile_image' => $this->user->profile_image,
            ] : null,
        ];
    }
}


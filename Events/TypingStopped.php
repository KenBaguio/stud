<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class TypingStopped implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $userId;
    public $conversationId;

    public function __construct($userId, $conversationId)
    {
        $this->userId = $userId;
        $this->conversationId = $conversationId;
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
        return 'typing.stopped';
    }

    public function broadcastWith(): array
    {
        return [
            'user_id' => $this->userId,
            'conversation_id' => $this->conversationId,
        ];
    }
}


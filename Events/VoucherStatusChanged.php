<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use App\Models\Voucher;

class VoucherStatusChanged implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $voucher;
    public $status;
    public $userId;

    public function __construct(Voucher $voucher, string $status, int $userId)
    {
        $this->voucher = $voucher;
        $this->status = $status;
        $this->userId = $userId;
    }

    public function broadcastOn()
    {
        return new PrivateChannel('vouchers.' . $this->userId);
    }

    public function broadcastWith(): array
    {
        return [
            'voucher_id' => $this->voucher->id,
            'status' => $this->status,
            'name' => $this->voucher->name,
            'message' => $this->status === 'disabled' 
                ? 'This voucher has been disabled and is no longer available for use.'
                : 'This voucher has been enabled and is now available for use.',
        ];
    }

    public function broadcastAs(): string
    {
        return 'voucher.status.changed';
    }
}


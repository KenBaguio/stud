<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use App\Models\UserVoucher;
use App\Models\Voucher;

class VoucherSent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $userVoucher;
    public $voucher;
    public $userId;

    public function __construct(UserVoucher $userVoucher, Voucher $voucher)
    {
        $this->userVoucher = $userVoucher;
        $this->voucher = $voucher;
        $this->userId = $userVoucher->user_id;
    }

    public function broadcastOn()
    {
        return new PrivateChannel('vouchers.' . $this->userId);
    }

    public function broadcastWith(): array
    {
        return [
            'user_voucher_id' => $this->userVoucher->id,
            'voucher_id' => $this->voucher->id,
            'voucher_code' => $this->userVoucher->voucher_code,
            'name' => $this->voucher->name,
            'description' => $this->voucher->description,
            'percent' => $this->voucher->percent,
            'expires_at' => $this->userVoucher->expires_at ? $this->userVoucher->expires_at->toISOString() : null,
            'message' => "You've received a new voucher: {$this->voucher->name} ({$this->voucher->percent}% off)",
        ];
    }

    public function broadcastAs(): string
    {
        return 'voucher.sent';
    }
}


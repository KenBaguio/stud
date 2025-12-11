<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Carbon\Carbon;

class UserVoucher extends Model
{
    use HasFactory;

    protected $table = 'user_voucher';

    protected $fillable = [
        'user_id',
        'voucher_id',
        'voucher_code',
        'sent_at',
        'viewed_at',
        'used_at',
        'expires_at'
    ];

    protected $casts = [
        'sent_at' => 'datetime',
        'viewed_at' => 'datetime',
        'used_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function voucher()
    {
        return $this->belongsTo(Voucher::class);
    }

    // Check if this specific voucher instance is used
    public function isUsed()
    {
        return !is_null($this->used_at);
    }

    // Check if this specific voucher instance is expired
    public function isExpired()
    {
        if ($this->isUsed()) {
            return true;
        }

        if ($this->expires_at) {
            return Carbon::now()->greaterThan($this->expires_at);
        }

        return false;
    }

    // Mark this specific instance as used
    public function markAsUsed()
    {
        $this->update(['used_at' => now()]);
    }

    // Check if this voucher has been viewed
    public function isViewed()
    {
        return !is_null($this->viewed_at);
    }

    // Mark this voucher as viewed
    public function markAsViewed()
    {
        if (!$this->isViewed()) {
            $this->update(['viewed_at' => now()]);
        }
    }
}
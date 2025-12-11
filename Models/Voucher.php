<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\User;
use App\Models\Order;
use Illuminate\Support\Str;

class Voucher extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'description',
        'type',
        'percent',
        'image',
        'status',
        'expiration_type',
        'expiration_duration',
    ];

    // Users who own this voucher (many-to-many)
    public function users()
    {
        return $this->belongsToMany(User::class, 'user_voucher', 'voucher_id', 'user_id')
                    ->withPivot('id', 'voucher_code', 'sent_at', 'used_at', 'expires_at')
                    ->withTimestamps();
    }

    // Individual voucher instances
    public function userVouchers()
    {
        return $this->hasMany(UserVoucher::class);
    }

    // Orders that used this voucher
    public function orders()
    {
        return $this->hasMany(Order::class, 'voucher_id');
    }

    // Generate unique voucher code
    public function generateVoucherCode()
    {
        return 'VCH-' . strtoupper(Str::random(8)) . '-' . time();
    }

    // Check if voucher is active
    public function isActive()
    {
        return $this->status === 'enabled';
    }
}
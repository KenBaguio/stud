<?php

namespace App\Models;

use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Tymon\JWTAuth\Contracts\JWTSubject;

class User extends Authenticatable implements JWTSubject, MustVerifyEmail
{
    use HasFactory, Notifiable;

    protected $fillable = [
        'first_name',
        'last_name',
        'organization_name',
        'email',
        'phone',
        'password',
        'is_organization',
        'dob',
        'date_founded',
        'profile_image',
        'role',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password'          => 'hashed',
            'is_organization'   => 'boolean',
        ];
    }

    // JWT
    public function getJWTIdentifier()
    {
        return $this->getKey();
    }

    public function getJWTCustomClaims()
    {
        return [
            'email'           => $this->email,
            'is_organization' => $this->is_organization,
            'role'            => $this->role,
        ];
    }

    // Relationships
    public function addresses()
    {
        return $this->hasMany(Address::class);
    }

    public function vouchers()
    {
        return $this->belongsToMany(\App\Models\Voucher::class, 'user_voucher', 'user_id', 'voucher_id')
                     ->withPivot('used_at')
                    ->withTimestamps();
    }

    public function disabledVouchers()
    {
        return $this->belongsToMany(\App\Models\Voucher::class, 'disabled_vouchers', 'user_id', 'voucher_id')
                    ->withTimestamps();
    }

    // Helper for display name
    public function getDisplayNameAttribute()
    {
        return $this->is_organization
            ? $this->organization_name
            : trim($this->first_name . ' ' . $this->last_name);
    }
}

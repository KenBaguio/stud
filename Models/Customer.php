<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Customer extends Model
{
    use HasFactory;

    protected $table = 'users'; // same table as User

    protected $fillable = [
        'first_name',
        'last_name',
        'organization_name',
        'email',
        'phone',
        'is_organization',
        'dob',
        'date_founded',
        'profile_image',
        'role',
    ];

    // Scope for customers only
    public function scopeCustomers($query)
    {
        return $query->where('role', 'customer'); // ensure 'customer' matches DB exactly
    }

    // Display name helper
    public function getDisplayNameAttribute()
    {
        return $this->is_organization
            ? $this->organization_name
            : trim($this->first_name . ' ' . $this->last_name);
    }
}

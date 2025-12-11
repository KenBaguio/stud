<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Address extends Model
{
    use SoftDeletes; // <- Add this

    protected $fillable = [
        'first_name',
        'last_name',
        'email',
        'phone',
        'main_address',
        'specific_address',
        'location_type',
        'cebu_location',
    ];
    public function user()
    {
        return $this->belongsTo(User::class);
    }
}

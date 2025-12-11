<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Review extends Model
{
    use HasFactory, SoftDeletes; 

    protected $fillable = [
        'user_id',
        'rating',
        'comment',
        'images',
        'admin_reply',
    ];

    protected $dates = ['deleted_at']; // soft delete column

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function getImagesAttribute($value)
    {
        return $value ? json_decode($value, true) : [];
    }

    public function setImagesAttribute($value)
    {
        $this->attributes['images'] = $value ? json_encode($value) : null;
    }
}

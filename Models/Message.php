<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Events\MessageSent;

class Message extends Model
{
    use HasFactory;

    protected $fillable = [
        'conversation_id',  // bigint UN - Foreign key to conversations table
        'sender_id',        // bigint UN - Foreign key to users table (who sent the message)
        'receiver_id',      // bigint UN - Foreign key to users table (who receives the message)
        'message',          // text - The message content
        'product',          // json - Product data if message includes a product
        'is_quick_option',  // tinyint(1) - Boolean flag for quick option messages
        'images'            // json - Array of image URLs if message includes images
    ];

    protected $touches = [
        'conversation',     // Update conversation's updated_at when message is created/updated
    ];

    protected $casts = [
        'is_quick_option' => 'boolean',  // Cast tinyint(1) to boolean
        'product' => 'array',            // Cast json to array (handled by accessor/mutator)
        'images' => 'array',             // Cast json to array (handled by accessor/mutator)
    ];

    protected $dispatchesEvents = [
        'created' => MessageSent::class,
    ];

    /**
     * Sender relationship
     */
    public function sender()
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    /**
     * Receiver relationship
     */
    public function receiver()
    {
        return $this->belongsTo(User::class, 'receiver_id');
    }

    /**
     * Conversation relationship
     */
    public function conversation()
    {
        return $this->belongsTo(Conversation::class);
    }

    /**
     * Accessor for parsed product data
     */
    public function getProductAttribute($value)
    {
        if (is_string($value)) {
            try {
                return json_decode($value, true);
            } catch (\Exception $e) {
                return null;
            }
        }
        return $value;
    }

    /**
     * Mutator for product data
     */
    public function setProductAttribute($value)
    {
        if (is_array($value)) {
            $this->attributes['product'] = json_encode($value);
        } else {
            $this->attributes['product'] = $value;
        }
    }

    /**
     * Accessor for parsed images data
     */
    public function getImagesAttribute($value)
    {
        if (is_string($value)) {
            try {
                return json_decode($value, true);
            } catch (\Exception $e) {
                return [];
            }
        }
        return $value ?? [];
    }

    /**
     * Mutator for images data
     */
    public function setImagesAttribute($value)
    {
        if (is_array($value)) {
            $this->attributes['images'] = json_encode($value);
        } else {
            $this->attributes['images'] = $value;
        }
    }

    /**
     * Check if message has images
     */
    public function getHasImagesAttribute()
    {
        return !empty($this->images);
    }
}
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->json('customer'); // shipping info (first_name, last_name, email, phone, main_address, specific_address)
            $table->decimal('subtotal', 10, 2);
            $table->decimal('shipping_fee', 10, 2);
            $table->decimal('total_amount', 10, 2);
            
            // Voucher fields - updated for specific voucher instance tracking
            $table->foreignId('voucher_id')->nullable()->constrained('vouchers')->onDelete('set null');
            $table->string('voucher_code')->nullable(); // Store specific voucher code used
            $table->decimal('discount_amount', 10, 2)->default(0); // Store actual discount amount applied
            
            $table->enum('payment_method', ['cod','gcash','credit-card'])->nullable();
            $table->enum('payment_status', ['pending','paid','failed'])->default('pending');
            
            // Match frontend statuses exactly (removed 'cancelled')
            $table->enum('status', [
                'pending',
                'confirmed', 
                'processing',
                'packaging',
                'on_delivery', 
                'delivered'
            ])->default('pending');
            
            $table->timestamps();
            
            // Indexes for better performance
            $table->index(['user_id', 'status']);
            $table->index(['voucher_id', 'voucher_code']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
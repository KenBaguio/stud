<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // 📦 Vouchers table
        Schema::create('vouchers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->enum('type', ['product_discount', 'free_shipping', 'shipping_discount'])->default('product_discount');
            $table->integer('percent');
            $table->string('image')->nullable();
            $table->string('status')->default('enabled');
            $table->enum('expiration_type', ['hours', 'days'])->default('days'); // ⏰ type of expiry
            $table->integer('expiration_duration')->default(1); // ⏰ how many hours/days
            $table->timestamp('expiration_date')->nullable(); // optional
            $table->timestamps();
        });

        // 🧾 Track vouchers assigned to users (with used_at) - UPDATED
        Schema::create('user_voucher', function (Blueprint $table) {
            $table->id(); // Primary key to track individual instances
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('voucher_id')->constrained('vouchers')->onDelete('cascade');
            $table->string('voucher_code')->nullable(); // Unique code for each instance
            $table->timestamp('sent_at')->useCurrent(); // When voucher was sent
            $table->timestamp('used_at')->nullable(); // When voucher was used
            $table->timestamp('expires_at')->nullable(); // Individual expiry
            $table->timestamps();
            
            // Index for better performance
            $table->index(['user_id', 'voucher_id', 'used_at']);
            $table->unique('voucher_code'); // Ensure unique codes
        });

        // 📬 Voucher sent log (optional, for tracking)
        Schema::create('voucher_sents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('voucher_id')->constrained('vouchers')->onDelete('cascade');
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('voucher_sents');
        Schema::dropIfExists('user_voucher');
        Schema::dropIfExists('vouchers');
    }
};
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('walkin_purchases', function (Blueprint $table) {
            $table->id();
            $table->string('customer_name');
            $table->string('customer_email');
            $table->string('customer_contact');
            $table->string('product_name');
            $table->decimal('unit_price', 10, 2);
            $table->integer('quantity');
            $table->decimal('total_price', 10, 2);
            $table->enum('item_type', ['reference', 'customized'])->default('reference');
            $table->enum('category', ['apparel', 'accessory'])->default('apparel');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('walkin_purchases');
    }
};
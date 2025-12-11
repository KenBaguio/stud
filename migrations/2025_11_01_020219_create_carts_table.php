<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('carts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('product_id')->nullable();
            $table->unsignedBigInteger('custom_proposal_id')->nullable();
            $table->string('available_sizes')->default('');
            $table->integer('quantity')->default(1);
            $table->decimal('size_price', 10, 2)->nullable();
            $table->decimal('price', 12, 2)->nullable();
            $table->decimal('total_price', 12, 2)->nullable();
            $table->boolean('is_customized')->default(false);
            $table->timestamps();

            // Foreign keys
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('product_id')->references('id')->on('products')->onDelete('cascade');
            $table->foreign('custom_proposal_id')->references('id')->on('custom_proposals')->onDelete('cascade');

            // For regular products
            $table->unique(['user_id', 'product_id', 'available_sizes'], 'cart_product_unique');
            
            // For custom proposals - include available_sizes to allow different sizes
            $table->unique(['user_id', 'custom_proposal_id', 'available_sizes'], 'cart_proposal_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('carts');
    }
};
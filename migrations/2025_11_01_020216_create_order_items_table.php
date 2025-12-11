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
        Schema::create('order_items', function (Blueprint $table) {
            $table->id();

            // Foreign key to orders table
            $table->foreignId('order_id')
                  ->constrained('orders')
                  ->cascadeOnDelete();

            // Foreign key to products table (nullable for custom proposals)
            $table->foreignId('product_id')
                  ->nullable()
                  ->constrained('products')
                  ->cascadeOnDelete();

            // Foreign key to custom_proposals table (nullable for regular products)
            $table->foreignId('custom_proposal_id')
                  ->nullable()
                  ->constrained('custom_proposals')
                  ->cascadeOnDelete();

            // Product details stored at the time of order
            $table->string('name');          // Product name
            $table->decimal('price', 10, 2)->nullable();       // Price for accessories/gears
            $table->decimal('size_price', 10, 2)->nullable();  // Price for apparels by size
            $table->integer('quantity');     // Quantity ordered

            // Optional attributes
            $table->string('size')->nullable();  // Selected size for apparels
            $table->string('image')->nullable(); // Product image URL

            // Custom proposal fields
            $table->boolean('is_customized')->default(false); // Flag to distinguish custom items
            $table->json('customization_details')->nullable(); // Store custom proposal details

            $table->timestamps();
            
            // Indexes for better performance
            $table->index(['order_id', 'product_id']);
            $table->index(['order_id', 'custom_proposal_id']);
            $table->index(['is_customized']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('order_items');
    }
};
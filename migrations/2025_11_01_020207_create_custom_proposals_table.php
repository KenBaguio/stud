<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('custom_proposals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->string('name');
            $table->text('customization_request')->nullable();
            $table->string('product_type')->nullable();
            $table->enum('category', ['apparel', 'accessory', 'gear'])->nullable();
            $table->string('customer_name')->nullable();
            $table->string('customer_email')->nullable();
            $table->integer('quantity')->nullable();
            $table->decimal('total_price', 10, 2)->nullable();
            $table->text('designer_message')->nullable();
            $table->string('material')->nullable();
            $table->json('features')->nullable();
            $table->json('images')->nullable();
            $table->json('size_options')->nullable();
            $table->timestamps();

            $table->foreign('customer_id')->references('id')->on('users')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('custom_proposals');
    }
};
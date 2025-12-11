<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->decimal('price', 10, 2)->nullable(); // single price if no sizes
            $table->text('description')->nullable();
            $table->foreignId('category_id')->constrained()->cascadeOnDelete();
            $table->enum('status', ['active','inactive'])->default('active');
            $table->string('image')->nullable();
            $table->json('images')->nullable();
            $table->string('material')->nullable();
            $table->text('note')->nullable();
            $table->string('dimensions')->nullable();
            $table->string('weight')->nullable();
            $table->string('compartments')->nullable();
            $table->text('features')->nullable();
            $table->json('available_sizes')->nullable(); // array of sizes
            $table->json('prices')->nullable(); // array or single price
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void {
        Schema::dropIfExists('products');
    }
};

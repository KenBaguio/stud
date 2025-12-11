<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('material_usage_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('material_id')->constrained('materials')->onDelete('cascade');
            $table->integer('quantity_used')->default(0);
            $table->decimal('cost_per_unit', 10, 2)->default(0);
            $table->decimal('total_cost', 10, 2)->default(0);
            $table->timestamps();
            
            $table->index('material_id');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('material_usage_history');
    }
};


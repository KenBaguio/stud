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
        if (Schema::hasTable('messages')) {
            Schema::table('messages', function (Blueprint $table) {
                if (!Schema::hasColumn('messages', 'product')) {
                    $table->json('product')->nullable()->after('message');
                }
                
                if (!Schema::hasColumn('messages', 'is_quick_option')) {
                    $table->boolean('is_quick_option')->default(false)->after('product');
                }

                if (!Schema::hasColumn('messages', 'images')) {
                    $table->json('images')->nullable()->after('is_quick_option');
                }
            });
        } else {
            Schema::create('messages', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('sender_id');
                $table->unsignedBigInteger('receiver_id');
                $table->text('message')->nullable();
                $table->json('product')->nullable();
                $table->boolean('is_quick_option')->default(false);
                $table->json('images')->nullable();
                $table->timestamps();

                $table->foreign('sender_id')->references('id')->on('users')->onDelete('cascade');
                $table->foreign('receiver_id')->references('id')->on('users')->onDelete('cascade');
                
                $table->index(['sender_id', 'receiver_id']);
                $table->index(['receiver_id', 'sender_id']);
                $table->index(['created_at']);
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropColumn(['product', 'is_quick_option', 'images']);
        });
    }
};
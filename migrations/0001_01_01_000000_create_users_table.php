<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();

            // For individual customers
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();

            // For organization customers
            $table->string('organization_name')->nullable();

            $table->string('email')->unique();
         $table->string('phone')->unique()->nullable();
            $table->string('password');

            // Flags
            $table->boolean('is_organization')->default(false);

            // Extra info
            $table->date('dob')->nullable(); // for individuals
            $table->date('date_founded')->nullable(); // for organizations
            $table->string('profile_image')->nullable();

            // Role
            $table->string('role')->default('customer'); // customer, clerk, admin

            $table->timestamp('email_verified_at')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (!Schema::hasTable('conversations')) {
            Schema::create('conversations', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id')->nullable();
                $table->unsignedBigInteger('active_clerk_id')->nullable();
                $table->timestamp('last_message_at')->nullable();
                $table->timestamps();

                $table->foreign('user_id')
                    ->references('id')
                    ->on('users')
                    ->onDelete('cascade');

                $table->foreign('active_clerk_id')
                    ->references('id')
                    ->on('users')
                    ->nullOnDelete();

                $table->unique('user_id');
            });
        } else {
            Schema::table('conversations', function (Blueprint $table) {
                if (!Schema::hasColumn('conversations', 'user_id')) {
                    $table->unsignedBigInteger('user_id')->nullable()->after('id');
                    $table->foreign('user_id')
                        ->references('id')
                        ->on('users')
                        ->onDelete('cascade');
                    $table->unique('user_id');
                }

                if (!Schema::hasColumn('conversations', 'active_clerk_id')) {
                    $table->unsignedBigInteger('active_clerk_id')->nullable()->after('user_id');
                    $table->foreign('active_clerk_id')
                        ->references('id')
                        ->on('users')
                        ->nullOnDelete();
                }

                if (!Schema::hasColumn('conversations', 'last_message_at')) {
                    $table->timestamp('last_message_at')->nullable()->after('updated_at');
                }
            });
        }

        Schema::table('messages', function (Blueprint $table) {
            if (!Schema::hasColumn('messages', 'conversation_id')) {
                $table->unsignedBigInteger('conversation_id')->nullable()->after('id');
                $table->foreign('conversation_id')
                    ->references('id')
                    ->on('conversations')
                    ->cascadeOnDelete();
                $table->index('conversation_id');
            }
        });

        $userRoles = DB::table('users')->pluck('role', 'id');

        DB::table('messages')
            ->orderBy('id')
            ->chunkById(200, function ($messages) use ($userRoles) {
                foreach ($messages as $message) {
                    $senderRole = $userRoles[$message->sender_id] ?? null;
                    $receiverRole = $userRoles[$message->receiver_id] ?? null;

                    $customerId = null;
                    if ($senderRole === 'user') {
                        $customerId = $message->sender_id;
                    } elseif ($receiverRole === 'user') {
                        $customerId = $message->receiver_id;
                    }

                    if (!$customerId) {
                        continue;
                    }

                    $conversation = DB::table('conversations')->where('user_id', $customerId)->first();
                    if (!$conversation) {
                        $conversationId = DB::table('conversations')->insertGetId([
                            'user_id' => $customerId,
                            'active_clerk_id' => $senderRole === 'clerk' ? $message->sender_id : null,
                            'last_message_at' => $message->created_at,
                            'created_at' => $message->created_at ?? now(),
                            'updated_at' => $message->created_at ?? now(),
                        ]);
                    } else {
                        $conversationId = $conversation->id;

                        $updateData = [
                            'last_message_at' => $message->created_at,
                            'updated_at' => $message->created_at ?? now(),
                        ];

                        if (!$conversation->active_clerk_id && $senderRole === 'clerk') {
                            $updateData['active_clerk_id'] = $message->sender_id;
                        }

                        DB::table('conversations')
                            ->where('id', $conversationId)
                            ->update($updateData);
                    }

                    DB::table('messages')
                        ->where('id', $message->id)
                        ->update([
                            'conversation_id' => $conversationId,
                            'receiver_id' => $customerId,
                        ]);
                }
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('messages') && Schema::hasColumn('messages', 'conversation_id')) {
            Schema::table('messages', function (Blueprint $table) {
                $table->dropForeign(['conversation_id']);
                $table->dropIndex(['conversation_id']);
                $table->dropColumn('conversation_id');
            });
        }

        if (Schema::hasTable('conversations')) {
            Schema::table('conversations', function (Blueprint $table) {
                if (Schema::hasColumn('conversations', 'active_clerk_id')) {
                    $table->dropForeign(['active_clerk_id']);
                    $table->dropColumn('active_clerk_id');
                }

                if (Schema::hasColumn('conversations', 'user_id')) {
                    $table->dropForeign(['user_id']);
                    $table->dropUnique(['user_id']);
                    $table->dropColumn('user_id');
                }

                if (Schema::hasColumn('conversations', 'last_message_at')) {
                    $table->dropColumn('last_message_at');
                }
            });
        }
    }
};


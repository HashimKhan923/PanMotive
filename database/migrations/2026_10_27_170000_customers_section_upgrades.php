<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // When the buyer's oldest unanswered message arrived — drives "Needs reply", overdue flags
        // and response-time stats. Cleared the moment the seller replies.
        Schema::table('conversations', function (Blueprint $table) {
            $table->timestamp('awaiting_reply_since')->nullable()->after('last_message_at');
            $table->index(['seller_id', 'awaiting_reply_since']);
        });

        Schema::table('messages', function (Blueprint $table) {
            $table->json('attachments')->nullable()->after('body');
        });

        Schema::create('seller_saved_replies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('seller_id')->constrained('users')->cascadeOnDelete();
            $table->string('title', 80);
            $table->text('body');
            $table->unsignedInteger('use_count')->default(0);
            $table->timestamps();
        });

        Schema::create('seller_blocked_buyers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('seller_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('buyer_id')->constrained('users')->cascadeOnDelete();
            $table->string('reason', 255)->nullable();
            $table->timestamps();
            $table->unique(['seller_id', 'buyer_id']);
        });

        // Backfill: threads whose latest message came from the buyer are waiting on the seller.
        foreach (DB::table('conversations')->get(['id', 'buyer_id']) as $c) {
            $last = DB::table('messages')->where('conversation_id', $c->id)->orderByDesc('id')->first(['sender_id']);
            if (! $last || (int) $last->sender_id !== (int) $c->buyer_id) {
                continue;
            }
            $lastSeller = DB::table('messages')->where('conversation_id', $c->id)
                ->where('sender_id', '!=', $c->buyer_id)->max('id');
            $since = DB::table('messages')->where('conversation_id', $c->id)
                ->where('sender_id', $c->buyer_id)
                ->when($lastSeller, fn ($q) => $q->where('id', '>', $lastSeller))
                ->min('created_at');
            DB::table('conversations')->where('id', $c->id)->update(['awaiting_reply_since' => $since]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('seller_blocked_buyers');
        Schema::dropIfExists('seller_saved_replies');
        Schema::table('messages', fn (Blueprint $table) => $table->dropColumn('attachments'));
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropIndex(['seller_id', 'awaiting_reply_since']);
            $table->dropColumn('awaiting_reply_since');
        });
    }
};

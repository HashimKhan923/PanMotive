<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('seller_profiles', function (Blueprint $table) {
            $table->string('slug')->nullable()->unique()->after('business_name');
            $table->text('bio')->nullable()->after('slug');
            $table->text('return_policy')->nullable()->after('bio');
            $table->boolean('vacation_mode')->default(false)->after('return_policy');
            $table->string('vacation_message')->nullable()->after('vacation_mode');
            $table->string('logo_path')->nullable()->after('vacation_message');
            $table->decimal('handling_fee', 8, 2)->default(0)->after('logo_path');
            $table->decimal('free_shipping_threshold', 8, 2)->nullable()->after('handling_fee');
        });

        Schema::table('reviews', function (Blueprint $table) {
            $table->text('seller_response')->nullable()->after('body');
            $table->timestamp('seller_responded_at')->nullable()->after('seller_response');
        });

        Schema::create('conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('buyer_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('seller_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->string('subject')->nullable();
            $table->timestamp('last_message_at')->nullable();
            $table->unsignedInteger('buyer_unread')->default(0);
            $table->unsignedInteger('seller_unread')->default(0);
            $table->boolean('closed')->default(false);
            $table->timestamps();

            $table->index(['seller_id', 'last_message_at']);
            $table->index(['buyer_id', 'last_message_at']);
        });

        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sender_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('body');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->index(['conversation_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('messages');
        Schema::dropIfExists('conversations');

        Schema::table('reviews', fn (Blueprint $t) => $t->dropColumn(['seller_response', 'seller_responded_at']));

        Schema::table('seller_profiles', fn (Blueprint $t) => $t->dropColumn([
            'slug', 'bio', 'return_policy', 'vacation_mode', 'vacation_message',
            'logo_path', 'handling_fee', 'free_shipping_threshold',
        ]));
    }
};

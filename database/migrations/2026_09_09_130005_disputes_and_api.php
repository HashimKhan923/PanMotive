<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // SLA + escalation on the existing RMA flow.
        Schema::table('return_requests', function (Blueprint $table) {
            $table->timestamp('respond_by')->nullable()->after('status');
            $table->timestamp('escalated_at')->nullable()->after('respond_by');
            $table->string('escalated_by')->nullable()->after('escalated_at'); // buyer | system
        });

        // Audit timeline for a return / dispute — visible to buyer, seller, admin.
        Schema::create('dispute_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('return_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_role'); // buyer | seller | admin | system
            $table->string('event');      // requested | responded | approved | rejected | escalated | resolved | note
            $table->text('note')->nullable();
            $table->timestamps();
        });

        // Seller API access.
        Schema::create('api_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('seller_id')->constrained('users')->cascadeOnDelete();
            $table->string('name');
            $table->string('token_hash', 64)->unique();
            $table->string('preview', 12);                 // pm_live_xxxx… for display
            $table->json('abilities');                     // ['products:read','products:write','orders:read','orders:write']
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });

        Schema::create('webhook_endpoints', function (Blueprint $table) {
            $table->id();
            $table->foreignId('seller_id')->constrained('users')->cascadeOnDelete();
            $table->string('url');
            $table->string('secret', 48);
            $table->json('events');                         // ['order.created','order.shipped',...]
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('failure_count')->default(0);
            $table->string('last_status')->nullable();
            $table->timestamp('last_delivered_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_endpoints');
        Schema::dropIfExists('api_tokens');
        Schema::dropIfExists('dispute_events');
        Schema::table('return_requests', fn (Blueprint $t) => $t->dropColumn(['respond_by', 'escalated_at', 'escalated_by']));
    }
};

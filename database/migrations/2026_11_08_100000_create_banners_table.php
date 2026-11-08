<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('banners', function (Blueprint $table) {
            $table->id();
            $table->string('eyebrow', 40)->nullable();
            $table->string('title', 120);
            $table->string('subtitle', 280)->nullable();
            $table->string('button_label', 30)->nullable();
            $table->string('button_url', 500)->nullable();
            $table->string('second_label', 30)->nullable();
            $table->string('second_url', 500)->nullable();
            $table->string('image_path')->nullable();
            $table->string('style', 20)->default('charcoal');
            $table->string('placement', 30)->default('below_categories');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->dateTime('starts_at')->nullable();
            $table->dateTime('ends_at')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['placement', 'is_active', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('banners');
    }
};

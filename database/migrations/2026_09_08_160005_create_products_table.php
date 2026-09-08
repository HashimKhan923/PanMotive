<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('seller_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('category_id')->constrained();
            $table->foreignId('brand_id')->nullable()->constrained();
            $table->string('title');
            $table->string('slug');
            $table->string('sku')->nullable();
            $table->string('part_number')->nullable()->index();
            $table->string('oem_number')->nullable()->index();
            $table->longText('description')->nullable();
            $table->json('bullet_points')->nullable();
            $table->decimal('price', 10, 2);
            $table->decimal('sale_price', 10, 2)->nullable();
            $table->decimal('cost_price', 10, 2)->nullable();
            $table->unsignedInteger('quantity')->default(0);
            $table->decimal('weight_lbs', 8, 2)->nullable();
            $table->json('dimensions')->nullable(); // {length, width, height}
            $table->enum('condition', ['new', 'used', 'refurbished'])->default('new');
            $table->enum('status', ['draft', 'pending_review', 'active', 'rejected', 'archived'])->default('draft');
            $table->boolean('ai_generated_content')->default(false);
            $table->string('meta_title')->nullable();
            $table->string('meta_description')->nullable();
            $table->json('structured_data')->nullable(); // cached JSON-LD
            $table->unsignedBigInteger('views_count')->default(0);
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['seller_id', 'slug']);

            // MySQL/Postgres get a DB fulltext index; SQLite (local) can't build one,
            // and storefront search runs through Meilisearch/Scout regardless.
            if (DB::connection()->getDriverName() !== 'sqlite') {
                $table->fullText(['title', 'description']);
            }
        });

        // Pivot: which vehicles a product fits (the core auto-parts feature)
        Schema::create('product_fitments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('vehicle_id')->constrained()->cascadeOnDelete();
            $table->string('position')->nullable(); // front, rear, left, right, etc.
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['product_id', 'vehicle_id', 'position'], 'unique_product_fitment');
        });

        // Product-attribute values (e.g. Material: Ceramic)
        Schema::create('product_attribute_value', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('attribute_value_id')->constrained()->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_attribute_value');
        Schema::dropIfExists('product_fitments');
        Schema::dropIfExists('products');
    }
};

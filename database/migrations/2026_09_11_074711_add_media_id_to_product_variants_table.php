<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Which of the product's own gallery photos represents this specific
        // variant (e.g. the Blue mirror photo for the "Blue" variant). Points at
        // Spatie's own `media` table rather than a separate per-variant media
        // collection, so a variant photo is never stored twice.
        Schema::table('product_variants', function (Blueprint $table) {
            $table->foreignId('media_id')->nullable()->after('options')
                ->constrained('media')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('product_variants', function (Blueprint $table) {
            $table->dropConstrainedForeignId('media_id');
        });
    }
};

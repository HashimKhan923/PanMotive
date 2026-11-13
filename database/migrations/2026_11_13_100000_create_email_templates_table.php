<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** The admin's changes to email wording. A blank field means "use the default". */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_templates', function (Blueprint $table) {
            $table->id();
            $table->string('key', 60)->unique();
            $table->string('subject', 200)->nullable();
            $table->string('preheader', 255)->nullable();
            $table->string('greeting', 120)->nullable();
            $table->text('intro')->nullable();
            $table->string('button', 60)->nullable();
            $table->text('outro')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_templates');
    }
};

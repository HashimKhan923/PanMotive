<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vehicle_makes', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('logo_path')->nullable();
            $table->timestamps();
        });

        Schema::create('vehicle_models', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vehicle_make_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->timestamps();
            $table->unique(['vehicle_make_id', 'slug']);
        });

        Schema::create('vehicle_engines', function (Blueprint $table) {
            $table->id();
            $table->string('name'); // e.g. "2.0L L4 Turbo"
            $table->string('fuel_type')->nullable(); // gas, diesel, hybrid, electric
            $table->timestamps();
        });

        // The concrete fitment record: one specific Year+Make+Model+Engine(+Trim)
        Schema::create('vehicles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vehicle_make_id')->constrained();
            $table->foreignId('vehicle_model_id')->constrained();
            $table->foreignId('vehicle_engine_id')->nullable()->constrained();
            $table->unsignedSmallInteger('year');
            $table->string('trim')->nullable();
            $table->timestamps();

            $table->unique(['vehicle_model_id', 'year', 'vehicle_engine_id', 'trim'], 'unique_vehicle_config');
            $table->index(['vehicle_make_id', 'vehicle_model_id', 'year']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicles');
        Schema::dropIfExists('vehicle_engines');
        Schema::dropIfExists('vehicle_models');
        Schema::dropIfExists('vehicle_makes');
    }
};

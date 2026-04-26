<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('spec_options', function (Blueprint $table) {
            $table->id();
            $table->string('field_key', 50)->index();   // processor, ram, storage, etc.
            $table->string('value', 255);                 // valor almacenado y mostrado (UPPERCASE)
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['field_key', 'value']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('spec_options');
    }
};

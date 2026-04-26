<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Indice para filtrado por estado activo (muy frecuente en scopes)
            $table->index('is_active');

            // Indice para ordenamiento por defecto en tablas
            $table->index('created_at');
        });

        Schema::table('activity_log', function (Blueprint $table) {
            // Indice compuesto para filtrado + ordenamiento en listados
            $table->index(['log_name', 'created_at']);

            // Indice para busquedas por tipo de modelo
            $table->index('subject_type');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['is_active']);
            $table->dropIndex(['created_at']);
        });

        Schema::table('activity_log', function (Blueprint $table) {
            $table->dropIndex(['log_name', 'created_at']);
            $table->dropIndex(['subject_type']);
        });
    }
};

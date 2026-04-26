<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_settings', function (Blueprint $table) {
            $table->id();

            // ─── Datos legales ────────────────────────────
            $table->string('legal_name');                      // Razón social
            $table->string('trade_name')->nullable();          // Nombre comercial
            $table->string('rtn', 20);                         // RTN de la empresa
            $table->string('business_type')->nullable();        // Giro del negocio

            // ─── Ubicación ────────────────────────────────
            $table->text('address');                            // Dirección completa
            $table->string('city')->nullable();                 // Ciudad
            $table->string('department')->nullable();           // Departamento (ej: Cortés, Francisco Morazán)
            $table->string('municipality')->nullable();         // Municipio

            // ─── Contacto ─────────────────────────────────
            $table->string('phone')->nullable();
            $table->string('phone_secondary')->nullable();
            $table->string('email')->nullable();
            $table->string('website')->nullable();

            // ─── Branding ─────────────────────────────────
            $table->string('logo_path')->nullable();           // Ruta al logo

            // ─── Configuración fiscal ─────────────────────
            // NOTA: establishment_code, emission_point y document_type viven en
            // la tabla `establishments` y `cai_ranges` respectivamente (SRP).
            // `company_settings` contiene solo datos únicos de la empresa (RTN, razón social).
            $table->string('tax_regime')->default('normal');    // Régimen tributario

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_settings');
    }
};

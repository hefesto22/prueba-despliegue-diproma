<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Períodos fiscales mensuales para control de anulabilidad de facturas.
 *
 * Regla de dominio (Acuerdo SAR 189-2014 + 481-2017):
 *   - Período abierto (declared_at IS NULL)  → se puede anular facturas con cascade.
 *   - Período cerrado (declared_at IS NOT NULL) → solo Nota de Crédito.
 *   - Reapertura permitida para declaraciones rectificativas SAR, con motivo escrito
 *     y permiso admin; queda registrada en reopened_at/reopened_by/reopen_reason.
 *
 * Diproma es single-tenant: no se agrega company_setting_id — hay una única empresa
 * por instalación. Si en el futuro se necesita multi-tenancy, se hace en otro proyecto.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('fiscal_periods', function (Blueprint $table) {
            $table->id();

            // Identificación del período (YYYY-MM).
            // Se usa año+mes separados (no DATE) para queries directas sin
            // EXTRACT() y para índice compuesto natural.
            $table->smallInteger('period_year')->unsigned()
                ->comment('Año del período fiscal, ej. 2026');
            $table->tinyInteger('period_month')->unsigned()
                ->comment('Mes del período fiscal (1-12). Validación en el Service.');

            // Declaración al SAR — marcar período como cerrado.
            // declared_at NULL = período abierto (admite anulación de facturas).
            // declared_at SET  = período cerrado (solo NC, sin excepciones).
            $table->timestamp('declared_at')->nullable()
                ->comment('Momento en que el contador marcó el período como declarado ante SAR');
            $table->foreignId('declared_by')
                ->nullable()
                ->constrained('users')
                ->restrictOnDelete()
                ->comment('Contador que presentó la declaración (no se permite borrar user con declaraciones)');
            $table->text('declaration_notes')->nullable()
                ->comment('Notas libres del contador al declarar (ej. número de acuse SAR)');

            // Reapertura — caso excepcional de declaración rectificativa SAR
            // (Acuerdo 189-2014). reopened_at marca el período como "vuelto a
            // abierto" temporalmente; al re-declarar se setea de nuevo declared_at.
            $table->timestamp('reopened_at')->nullable()
                ->comment('Momento de reapertura (para declaración rectificativa SAR)');
            $table->foreignId('reopened_by')
                ->nullable()
                ->constrained('users')
                ->restrictOnDelete()
                ->comment('Admin que reabrió el período');
            $table->text('reopen_reason')->nullable()
                ->comment('Motivo obligatorio de reapertura (rastro de auditoría)');

            $table->timestamps();

            // Un único período por año/mes — garantiza que FiscalPeriodService::current()
            // siempre devuelve un solo registro.
            $table->unique(['period_year', 'period_month'], 'fiscal_periods_year_month_unique');

            // Índice para la query "períodos pendientes de declarar" del job diario
            // de alertas. Selectividad alta porque declared_at es NULL en pocos registros
            // (solo el/los períodos abiertos).
            $table->index('declared_at', 'fiscal_periods_declared_at_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fiscal_periods');
    }
};

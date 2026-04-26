<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fecha de inicio del tracking de períodos fiscales para la empresa.
 *
 * Reglas:
 *   - DATE siempre al día 1 del mes (validado en el form de CompanySettings).
 *   - Facturas con invoice_date < fiscal_period_start NO se pueden anular —
 *     se asumen pertenecientes a períodos previos al tracking (ya declarados).
 *   - NULL en BD está permitido para no romper el registro existente, pero
 *     el form lo marca obligatorio y el guard en InvoiceService lanza
 *     PeriodoFiscalNoConfiguradoException si está vacío al emitir.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('company_settings', function (Blueprint $table) {
            $table->date('fiscal_period_start')
                ->nullable()
                ->after('tax_regime')
                ->comment('Primer período fiscal trackeado. Día 1 del mes. Facturas anteriores solo admiten NC.');
        });
    }

    public function down(): void
    {
        Schema::table('company_settings', function (Blueprint $table) {
            $table->dropColumn('fiscal_period_start');
        });
    }
};

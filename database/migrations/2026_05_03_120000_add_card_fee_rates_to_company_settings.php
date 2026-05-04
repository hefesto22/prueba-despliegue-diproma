<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tasas de comisión bancaria por tipo de tarjeta — para reportar la
 * utilidad real del negocio.
 *
 * Por qué dos columnas separadas (crédito vs débito):
 *   En Honduras los procesadores cobran tasas diferentes por tipo de tarjeta.
 *   Crédito típicamente 3.0–4.0% y débito 1.5–2.5%. Mantenemos campos
 *   separados aunque arranquen iguales (3.4%) — permitir al admin afinar
 *   sin migración cuando renegocie con su banco.
 *
 * Cómo se usan:
 *   Cuando una venta (POS) o entrega de reparación se cobra con tarjeta,
 *   el sistema crea automáticamente un Expense con categoría
 *   "comisiones_bancarias" por `total_venta * tasa`. Esto refleja la
 *   utilidad real (después del costo bancario) en los reportes — pero NO
 *   afecta el ISV declarado al SAR (la base gravada se mantiene íntegra).
 *
 * Defaults a 0.0340 (3.4%) que es la tasa estándar promedio en HN.
 * El admin la edita desde la página "Configuración Empresa" del panel.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_settings', function (Blueprint $table) {
            // decimal(5,4) permite tasas hasta 9.9999 (999.99%). Más que
            // suficiente para cualquier comisión bancaria realista.
            $table->decimal('card_fee_rate_credit', 5, 4)
                ->default(0.0340)
                ->after('cash_discrepancy_tolerance');

            $table->decimal('card_fee_rate_debit', 5, 4)
                ->default(0.0340)
                ->after('card_fee_rate_credit');
        });
    }

    public function down(): void
    {
        Schema::table('company_settings', function (Blueprint $table) {
            $table->dropColumn(['card_fee_rate_credit', 'card_fee_rate_debit']);
        });
    }
};

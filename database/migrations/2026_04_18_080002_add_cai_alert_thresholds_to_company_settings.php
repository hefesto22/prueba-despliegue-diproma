<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Umbrales configurables para las alertas tempranas de CAI.
 *
 * Por qué configurables y no constantes en código:
 *   - El criterio "30/15/7 días" o "10% restante o 100 facturas absolutas"
 *     es razonable como default pero depende del ritmo de facturación de la
 *     empresa. Una empresa que emite 50 facturas al día necesita alertas
 *     mucho antes que una que emite 5.
 *   - El admin (Mauricio o el contador) debe poder ajustar sin redeploy.
 *
 * Por qué JSON para los días de aviso de vencimiento y no tres columnas:
 *   - Permite ampliar/reducir la cantidad de avisos en el futuro sin nueva
 *     migración (ej: agregar aviso a 60 días o quitar el de 7).
 *   - Defaults concretos: [30, 15, 7] — tres niveles de severidad creciente
 *     que se mapean a "informativo / urgente / crítico" en el job de alertas.
 *
 * Por qué dos campos de exhaustion (porcentaje + absoluto):
 *   - Para empresas con rangos pequeños (ej: 500 facturas), 10% son solo 50
 *     facturas y la alerta puede llegar muy tarde.
 *   - Para rangos grandes (ej: 50,000 facturas), 10% son 5,000 y la alerta
 *     se dispara demasiado pronto.
 *   - Lo correcto es: alertar cuando ocurra LO QUE PRIMERO se cumpla entre
 *     "queda menos del X%" o "quedan menos de N facturas absolutas".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_settings', function (Blueprint $table) {
            // JSON: array de días enteros, ordenado descendentemente por convención.
            // Default [30, 15, 7] — tres ventanas de severidad.
            $table->json('cai_expiration_warning_days')->nullable()->after('fiscal_period_start');

            // Porcentaje del rango restante a partir del cual se alerta.
            // 5,2 → hasta 999.99% (sobra). Default 10.00.
            // Nullable: si el admin deja el campo en blanco, el modelo usa
            // DEFAULT_CAI_EXHAUSTION_WARNING_PERCENTAGE — semántica de "no
            // configurado, usa el default del sistema".
            $table->decimal('cai_exhaustion_warning_percentage', 5, 2)->nullable()->default(10.00)->after('cai_expiration_warning_days');

            // Cantidad absoluta de facturas restantes a partir de la cual se alerta.
            // Default 100. La alerta se dispara cuando se cumple cualquiera de
            // las dos condiciones (porcentaje o absoluto), lo que ocurra primero.
            // Nullable por la misma razón que la anterior.
            $table->unsignedInteger('cai_exhaustion_warning_absolute')->nullable()->default(100)->after('cai_exhaustion_warning_percentage');
        });

        // Backfill: poblar los defaults JSON en las filas existentes.
        // (Las columnas decimal/integer ya tienen su default aplicado.)
        DB::table('company_settings')
            ->whereNull('cai_expiration_warning_days')
            ->update([
                'cai_expiration_warning_days' => json_encode([30, 15, 7]),
            ]);
    }

    public function down(): void
    {
        Schema::table('company_settings', function (Blueprint $table) {
            $table->dropColumn([
                'cai_expiration_warning_days',
                'cai_exhaustion_warning_percentage',
                'cai_exhaustion_warning_absolute',
            ]);
        });
    }
};

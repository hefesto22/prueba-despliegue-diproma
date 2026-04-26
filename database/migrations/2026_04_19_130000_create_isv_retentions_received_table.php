<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Retenciones de ISV recibidas por Diproma (sujeto pasivo retenido).
 *
 * Tabla genérica para los 3 tipos de retención declarables en la sección C
 * del Formulario 201 ISV mensual (SIISAR):
 *   - Tarjetas de crédito/débito (Acuerdo 477-2013)
 *   - Ventas al Estado (PCM-051-2011)
 *   - Gran contribuyente (Acuerdo 215-2010)
 *
 * Un solo modelo + enum `retention_type` en vez de 3 modelos separados porque
 * la estructura de datos es idéntica y el único campo que diverge es la
 * interpretación legal/la casilla SIISAR destino. Centralizar permite listar
 * todas las retenciones del mes en una sola tabla Filament y cerrar la
 * sección C de la declaración con una sola suma.
 *
 * Indices:
 *   - (establishment_id, period_year, period_month, retention_type): cubre la
 *     query canónica "retenciones del mes X por tipo Y en la sucursal Z" que
 *     usa IsvMonthlyDeclarationService para cerrar la sección C.
 *   - agent_rtn: permite filtrar retenciones de un mismo retenedor a lo largo
 *     del tiempo (útil en auditoría cuando el SAR pide cruce con un NIT/RTN
 *     específico).
 *
 * FK establishment_id nullable: consistente con purchases/sales (columna
 * opcional mientras Diproma es single-establishment; F6b diferido). Cuando
 * aparezca una segunda sucursal, el backfill y el filtro quedan listos.
 *
 * Por qué SoftDeletes: una retención eliminada puede haberse declarado ya en
 * SIISAR; conservar el registro es crítico para auditoría SAR de 5 años.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('isv_retentions_received', function (Blueprint $table) {
            $table->id();

            // ─── Sucursal (nullable, consistente con purchases/sales) ────
            $table->foreignId('establishment_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();

            // ─── Período al que aplica la retención ───────────────────────
            // Se guarda año/mes explícito (no derivar de created_at) porque
            // el contador puede ingresar retenciones con desfase temporal.
            $table->smallInteger('period_year')->unsigned();
            $table->tinyInteger('period_month')->unsigned();

            // Tipo — determina la casilla SIISAR destino (ver IsvRetentionType).
            $table->string('retention_type', 40);

            // ─── Agente retenedor ────────────────────────────────────────
            // RTN del que retuvo (banco/procesador POS, órgano del Estado,
            // gran contribuyente). 14 chars = formato SAR sin guiones.
            $table->string('agent_rtn', 14);
            $table->string('agent_name', 200);

            // Número de constancia/documento emitido por el retenedor.
            // Nullable porque algunos procesadores de tarjeta entregan
            // solo reporte mensual sin numeración individual.
            $table->string('document_number', 50)->nullable();

            // Scan/PDF de la constancia. Path relativo al disk público/privado.
            $table->string('document_path', 500)->nullable();

            // Monto retenido de ISV (siempre positivo, en lempiras).
            $table->decimal('amount', 12, 2);

            $table->text('notes')->nullable();

            // ─── Auditoría ───────────────────────────────────────────────
            $table->foreignId('created_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->foreignId('updated_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->foreignId('deleted_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            // ─── Indices ─────────────────────────────────────────────────
            // Índice compuesto diseñado para el patrón de query dominante:
            //   "retenciones del mes X en sucursal Y agrupadas por tipo"
            // Orden de columnas: sucursal (alta selectividad multi-tenant) →
            // año → mes → tipo (baja selectividad).
            $table->index(
                ['establishment_id', 'period_year', 'period_month', 'retention_type'],
                'isv_retentions_period_type_idx'
            );

            // Auditoría SAR por RTN del agente retenedor.
            $table->index('agent_rtn', 'isv_retentions_agent_rtn_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('isv_retentions_received');
    }
};

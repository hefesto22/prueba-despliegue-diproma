<?php

namespace App\Services\Alerts\Contracts;

use App\Models\CaiRange;

/**
 * Contrato para resolver CAIs sucesores pre-registrados.
 *
 * Este contrato existe para que `CaiFailoverService` (y cualquier otro
 * consumidor futuro) dependa de una abstracción, no de una implementación
 * concreta — aplicando DIP (los módulos de alto nivel del dominio dependen
 * de contratos, no de clases concretas).
 *
 * La implementación de producción (`CaiSuccessorResolver`) es `final` y se
 * registra en el container via `AppServiceProvider`. Los tests pueden proveer
 * un fake que implemente este contrato sin necesitar mocks sobre la clase
 * final concreta — patrón consistente con `ResuelveCorrelativoFactura`.
 *
 * Nota de alcance: este contrato cubre SOLO la resolución por CAI individual
 * (`findSuccessorFor`) usada por el failover. El método batch `resolveFor()`
 * del resolver concreto queda fuera del contrato porque solo lo consumen los
 * checkers de alertas preventivas — si aparecen más consumidores del método
 * batch se extraerá un segundo contrato específico.
 */
interface ResuelveSucesoresDeCai
{
    /**
     * Encuentra el CaiRange que funcionaría como sucesor de `$cai`.
     *
     * Retorna null si no hay candidato válido — el caller debe tratarlo
     * como condición crítica (el POS quedaría sin CAI activo).
     */
    public function findSuccessorFor(CaiRange $cai): ?CaiRange;
}

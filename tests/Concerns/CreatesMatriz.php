<?php

namespace Tests\Concerns;

use App\Models\CompanySetting;
use App\Models\Establishment;
use Illuminate\Support\Facades\Cache;

/**
 * Garantiza que el test tenga una sucursal matriz disponible antes de ejecutar
 * operaciones que la resuelven por fallback (SaleService, InventoryMovement::record,
 * Filament de ajustes manuales).
 *
 * En producción la matriz siempre existe (seed inicial + validación en onboarding).
 * Los tests deben reflejar ese invariante — sin este trait, Services que hacen
 * `Establishment::main()->firstOrFail()` lanzan ModelNotFoundException en entornos
 * aislados con RefreshDatabase.
 *
 * Uso:
 *   use Tests\Concerns\CreatesMatriz;
 *
 *   class MyTest extends TestCase {
 *       use RefreshDatabase, CreatesMatriz;
 *   }
 *
 * El trait auto-engancha su setUp gracias al patrón `initializeTraits` de
 * `Illuminate\Foundation\Testing\TestCase`.
 */
trait CreatesMatriz
{
    protected ?CompanySetting $matrizCompany = null;

    protected ?Establishment $matriz = null;

    protected function setUpCreatesMatriz(): void
    {
        Cache::forget('company_settings');

        // Reusa el CompanySetting que el test haya creado en su setUp; si no
        // hay ninguno, crea el nuestro. Esto permite combinar con tests que
        // necesitan un RTN específico (ej: PurchaseBookServiceTest).
        $this->matrizCompany = CompanySetting::query()->first()
            ?? CompanySetting::factory()->create();

        // Cachea la company para que CompanySetting::current() la retorne
        // (evita race conditions con firstOrCreate en otros paths).
        Cache::put('company_settings', $this->matrizCompany, 60 * 60 * 24);

        $this->matriz = Establishment::factory()
            ->for($this->matrizCompany, 'companySetting')
            ->main()
            ->create();
    }
}

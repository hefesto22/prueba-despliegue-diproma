<?php

declare(strict_types=1);

namespace Tests\Feature\Services\Alerts;

use App\Models\CaiRange;
use App\Models\CompanySetting;
use App\Models\Establishment;
use App\Services\Alerts\CaiSuccessorResolver;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Cubre CaiSuccessorResolver::findSuccessorFor():
 *   - Retorna sucesor válido cuando existe (modelo CaiRange, no bool)
 *   - Respeta las 5 condiciones del sucesor (doc_type, establishment, inactivo, no vencido, no agotado)
 *   - Ordena por expiration_date DESC cuando hay múltiples candidatos válidos
 *   - Ordena por disponibilidad DESC como segundo criterio
 *   - Retorna null si no hay candidato válido
 *   - Isolation por establishment: CAI de matriz no se considera sucesor de sucursal2
 *
 * NO retesteamos resolveFor() — ya cubierto en CaiExpirationCheckerTest /
 * CaiRangeExhaustionCheckerTest indirectamente.
 */
class CaiSuccessorResolverTest extends TestCase
{
    use RefreshDatabase;

    private CompanySetting $company;

    private Establishment $matriz;

    private CaiSuccessorResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::forget('company_settings');

        $this->company = CompanySetting::factory()->create(['rtn' => '08011999123456']);
        Cache::put('company_settings', $this->company, 60 * 60 * 24);

        $this->matriz = Establishment::factory()
            ->for($this->company, 'companySetting')
            ->main()
            ->create();

        CarbonImmutable::setTestNow('2026-04-18');

        $this->resolver = app(CaiSuccessorResolver::class);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_retorna_sucesor_cuando_existe_uno_valido(): void
    {
        $activo = CaiRange::factory()->active()->create([
            'document_type' => '01',
            'establishment_id' => $this->matriz->id,
            'expiration_date' => now()->addDays(5)->toDateString(),
            'range_start' => 1,
            'range_end' => 500,
        ]);

        $sucesor = CaiRange::factory()->create([
            'is_active' => false,
            'document_type' => '01',
            'establishment_id' => $this->matriz->id,
            'expiration_date' => now()->addMonths(6)->toDateString(),
            'range_start' => 501,
            'range_end' => 1000,
            'current_number' => 500,
        ]);

        $resultado = $this->resolver->findSuccessorFor($activo);

        $this->assertNotNull($resultado);
        $this->assertSame($sucesor->id, $resultado->id);
    }

    public function test_retorna_null_cuando_no_hay_sucesor(): void
    {
        $activo = CaiRange::factory()->active()->create([
            'document_type' => '01',
            'establishment_id' => $this->matriz->id,
            'range_start' => 1,
            'range_end' => 500,
        ]);

        $resultado = $this->resolver->findSuccessorFor($activo);

        $this->assertNull($resultado);
    }

    public function test_ignora_sucesor_de_otro_document_type(): void
    {
        $activo = CaiRange::factory()->active()->create([
            'document_type' => '01',
            'establishment_id' => $this->matriz->id,
            'range_start' => 1,
            'range_end' => 500,
        ]);

        // Sucesor listo pero de tipo 03 (NC) — no debe matchear con 01.
        CaiRange::factory()->create([
            'is_active' => false,
            'document_type' => '03',
            'establishment_id' => $this->matriz->id,
            'expiration_date' => now()->addMonths(6)->toDateString(),
            'range_start' => 1,
            'range_end' => 1000,
            'current_number' => 500,
        ]);

        $this->assertNull($this->resolver->findSuccessorFor($activo));
    }

    public function test_ignora_sucesor_de_otra_sucursal(): void
    {
        $sucursal2 = Establishment::factory()
            ->for($this->company, 'companySetting')
            ->create(['code' => '002']);

        $activo = CaiRange::factory()->active()->create([
            'document_type' => '01',
            'establishment_id' => $this->matriz->id,
            'range_start' => 1,
            'range_end' => 500,
        ]);

        // Sucesor válido en estructura pero ligado a sucursal2 — no debe matchear.
        CaiRange::factory()->create([
            'is_active' => false,
            'document_type' => '01',
            'establishment_id' => $sucursal2->id,
            'expiration_date' => now()->addMonths(6)->toDateString(),
            'range_start' => 501,
            'range_end' => 1000,
            'current_number' => 500,
        ]);

        $this->assertNull($this->resolver->findSuccessorFor($activo));
    }

    public function test_ignora_sucesor_ya_vencido(): void
    {
        $activo = CaiRange::factory()->active()->create([
            'document_type' => '01',
            'establishment_id' => $this->matriz->id,
            'range_start' => 1,
            'range_end' => 500,
        ]);

        CaiRange::factory()->create([
            'is_active' => false,
            'document_type' => '01',
            'establishment_id' => $this->matriz->id,
            'expiration_date' => now()->subDay()->toDateString(), // vencido ayer
            'range_start' => 501,
            'range_end' => 1000,
            'current_number' => 500,
        ]);

        $this->assertNull($this->resolver->findSuccessorFor($activo));
    }

    public function test_ignora_sucesor_ya_agotado(): void
    {
        $activo = CaiRange::factory()->active()->create([
            'document_type' => '01',
            'establishment_id' => $this->matriz->id,
            'range_start' => 1,
            'range_end' => 500,
        ]);

        CaiRange::factory()->create([
            'is_active' => false,
            'document_type' => '01',
            'establishment_id' => $this->matriz->id,
            'expiration_date' => now()->addMonths(6)->toDateString(),
            'range_start' => 501,
            'range_end' => 1000,
            'current_number' => 1000, // agotado: current_number >= range_end
        ]);

        $this->assertNull($this->resolver->findSuccessorFor($activo));
    }

    public function test_cuando_hay_multiples_candidatos_prefiere_el_que_vence_mas_tarde(): void
    {
        $activo = CaiRange::factory()->active()->create([
            'document_type' => '01',
            'establishment_id' => $this->matriz->id,
            'range_start' => 1,
            'range_end' => 500,
        ]);

        $vence_pronto = CaiRange::factory()->create([
            'is_active' => false,
            'document_type' => '01',
            'establishment_id' => $this->matriz->id,
            'expiration_date' => now()->addMonth()->toDateString(),
            'range_start' => 501,
            'range_end' => 1000,
            'current_number' => 500,
        ]);

        $vence_tarde = CaiRange::factory()->create([
            'is_active' => false,
            'document_type' => '01',
            'establishment_id' => $this->matriz->id,
            'expiration_date' => now()->addYear()->toDateString(),
            'range_start' => 1001,
            'range_end' => 1500,
            'current_number' => 1000,
        ]);

        $resultado = $this->resolver->findSuccessorFor($activo);

        $this->assertSame($vence_tarde->id, $resultado->id);
    }

    public function test_con_misma_fecha_de_vencimiento_prefiere_el_de_mayor_disponibilidad(): void
    {
        $activo = CaiRange::factory()->active()->create([
            'document_type' => '01',
            'establishment_id' => $this->matriz->id,
            'range_start' => 1,
            'range_end' => 500,
        ]);

        $mismaFecha = now()->addYear()->toDateString();

        $poco_rango = CaiRange::factory()->create([
            'is_active' => false,
            'document_type' => '01',
            'establishment_id' => $this->matriz->id,
            'expiration_date' => $mismaFecha,
            'range_start' => 501,
            'range_end' => 700, // disponible: 200
            'current_number' => 500,
        ]);

        $mucho_rango = CaiRange::factory()->create([
            'is_active' => false,
            'document_type' => '01',
            'establishment_id' => $this->matriz->id,
            'expiration_date' => $mismaFecha,
            'range_start' => 701,
            'range_end' => 2700, // disponible: 2000
            'current_number' => 700,
        ]);

        $resultado = $this->resolver->findSuccessorFor($activo);

        $this->assertSame($mucho_rango->id, $resultado->id);
    }

    public function test_no_se_retorna_a_si_mismo_como_sucesor(): void
    {
        // Edge case defensivo: el activo tiene is_active=false en algún estado
        // transitorio — nunca debería ser su propio sucesor.
        $cai = CaiRange::factory()->create([
            'is_active' => false,
            'document_type' => '01',
            'establishment_id' => $this->matriz->id,
            'expiration_date' => now()->addMonths(6)->toDateString(),
            'range_start' => 1,
            'range_end' => 500,
            'current_number' => 0,
        ]);

        $this->assertNull($this->resolver->findSuccessorFor($cai));
    }
}

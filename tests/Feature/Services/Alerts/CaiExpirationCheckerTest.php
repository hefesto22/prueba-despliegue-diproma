<?php

namespace Tests\Feature\Services\Alerts;

use App\Models\CaiRange;
use App\Models\CompanySetting;
use App\Models\Establishment;
use App\Services\Alerts\CaiExpirationChecker;
use App\Services\Alerts\Enums\CaiAlertSeverity;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Cubre CaiExpirationChecker:
 *   - Solo alerta CAIs activos cuya fecha de vencimiento está dentro del
 *     umbral mayor configurado en CompanySetting.
 *   - Severidad por bucket: crítico (≤ umbral estrecho), urgente (intermedio),
 *     info (umbral amplio) — asumiendo que hay sucesor pre-registrado.
 *   - Sin sucesor pre-registrado fuerza severidad Critical ("NoSuccessor").
 *   - Respeta la configuración custom de umbrales en CompanySetting.
 *   - No alerta sobre CAIs ya vencidos (otro eje resuelve eso en Fase 2).
 *   - Respeta alcance para sucesor: mismo doc_type + mismo establishment_id.
 */
class CaiExpirationCheckerTest extends TestCase
{
    use RefreshDatabase;

    private CompanySetting $company;

    private Establishment $matriz;

    private CaiExpirationChecker $checker;

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

        // Fijar "hoy" para que los cálculos de días sean determinísticos.
        CarbonImmutable::setTestNow('2026-04-18');

        $this->checker = app(CaiExpirationChecker::class);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    private function setUmbrales(array $warningDays): void
    {
        $this->company->update(['cai_expiration_warning_days' => $warningDays]);
        // Refrescar cache con el company ya actualizado. NO usar Cache::forget
        // porque CompanySetting::current() usa firstOrCreate(['id' => 1]) y
        // si el factory creó la company con id != 1 se generaría un row fantasma.
        Cache::put('company_settings', $this->company->fresh(), 60 * 60 * 24);
    }

    public function test_no_dispara_cuando_no_hay_caios_dentro_del_umbral(): void
    {
        $this->setUmbrales([30, 15, 7]);

        // CAI activo que vence en 90 días — muy lejos del umbral.
        CaiRange::factory()->active()->create([
            'establishment_id' => $this->matriz->id,
            'expiration_date' => now()->addDays(90)->toDateString(),
        ]);

        $this->assertCount(0, $this->checker->check());
    }

    public function test_dispara_info_cuando_dias_en_umbral_amplio_con_sucesor(): void
    {
        $this->setUmbrales([30, 15, 7]);

        $activo = CaiRange::factory()->active()->create([
            'document_type' => '01',
            'establishment_id' => $this->matriz->id,
            'expiration_date' => now()->addDays(25)->toDateString(),
            'range_start' => 1,
            'range_end' => 500,
        ]);

        // Sucesor pre-registrado: mismo doc + mismo establishment, inactive, vigente.
        CaiRange::factory()->create([
            'document_type' => '01',
            'establishment_id' => $this->matriz->id,
            'expiration_date' => now()->addMonths(12)->toDateString(),
            'range_start' => 501,
            'range_end' => 1000,
            'is_active' => false,
        ]);

        $alerts = $this->checker->check();

        $this->assertCount(1, $alerts);
        $this->assertSame($activo->id, $alerts->first()->cai->id);
        $this->assertSame(CaiAlertSeverity::Info, $alerts->first()->severity);
        $this->assertTrue($alerts->first()->hasSuccessor);
    }

    public function test_dispara_urgent_cuando_dias_en_umbral_intermedio_con_sucesor(): void
    {
        $this->setUmbrales([30, 15, 7]);

        CaiRange::factory()->active()->create([
            'document_type' => '01',
            'establishment_id' => $this->matriz->id,
            'expiration_date' => now()->addDays(12)->toDateString(),
            'range_start' => 1,
            'range_end' => 500,
        ]);

        CaiRange::factory()->create([
            'document_type' => '01',
            'establishment_id' => $this->matriz->id,
            'expiration_date' => now()->addMonths(12)->toDateString(),
            'range_start' => 501,
            'range_end' => 1000,
            'is_active' => false,
        ]);

        $alerts = $this->checker->check();

        $this->assertCount(1, $alerts);
        $this->assertSame(CaiAlertSeverity::Urgent, $alerts->first()->severity);
    }

    public function test_dispara_critical_cuando_dias_en_umbral_estrecho_con_sucesor(): void
    {
        $this->setUmbrales([30, 15, 7]);

        CaiRange::factory()->active()->create([
            'document_type' => '01',
            'establishment_id' => $this->matriz->id,
            'expiration_date' => now()->addDays(5)->toDateString(),
            'range_start' => 1,
            'range_end' => 500,
        ]);

        CaiRange::factory()->create([
            'document_type' => '01',
            'establishment_id' => $this->matriz->id,
            'expiration_date' => now()->addMonths(12)->toDateString(),
            'range_start' => 501,
            'range_end' => 1000,
            'is_active' => false,
        ]);

        $alerts = $this->checker->check();

        $this->assertCount(1, $alerts);
        $this->assertSame(CaiAlertSeverity::Critical, $alerts->first()->severity);
    }

    public function test_dispara_critical_sin_importar_dias_cuando_no_hay_sucesor(): void
    {
        $this->setUmbrales([30, 15, 7]);

        // CAI activo a 25 días — normalmente sería Info. Sin sucesor → Critical.
        CaiRange::factory()->active()->create([
            'document_type' => '01',
            'establishment_id' => $this->matriz->id,
            'expiration_date' => now()->addDays(25)->toDateString(),
        ]);

        $alerts = $this->checker->check();

        $this->assertCount(1, $alerts);
        $this->assertSame(CaiAlertSeverity::Critical, $alerts->first()->severity);
        $this->assertFalse($alerts->first()->hasSuccessor);
    }

    public function test_respeta_umbrales_configurables_de_company_settings(): void
    {
        // Umbral único de 60 días. Un CAI a 45 días debe alertar.
        $this->setUmbrales([60]);

        CaiRange::factory()->active()->create([
            'establishment_id' => $this->matriz->id,
            'expiration_date' => now()->addDays(45)->toDateString(),
        ]);

        $alerts = $this->checker->check();

        $this->assertCount(1, $alerts);
    }

    public function test_no_alerta_sobre_caios_ya_vencidos(): void
    {
        $this->setUmbrales([30, 15, 7]);

        // Ya vencido ayer — el checker lo filtra (otro eje lo resuelve).
        CaiRange::factory()->active()->expired()->create([
            'establishment_id' => $this->matriz->id,
        ]);

        $this->assertCount(0, $this->checker->check());
    }

    public function test_no_alerta_cuando_umbrales_estan_vacios(): void
    {
        // Lista vacía NO cae a defaults — el accessor
        // `cai_expiration_warning_days_list` devuelve defaults SOLO si raw está
        // null o no-array. Un array con basura genera lista vacía → semántica
        // "alertas desactivadas".
        $this->setUmbrales([0, -1]);

        CaiRange::factory()->active()->create([
            'establishment_id' => $this->matriz->id,
            'expiration_date' => now()->addDays(5)->toDateString(),
        ]);

        $this->assertCount(0, $this->checker->check());
    }

    public function test_no_considera_sucesor_un_cai_de_otra_sucursal(): void
    {
        $this->setUmbrales([30, 15, 7]);

        $sucursal = Establishment::factory()
            ->for($this->company, 'companySetting')
            ->create(['code' => '002', 'is_main' => false]);

        // Activo en matriz a 10 días, sin sucesor en matriz.
        CaiRange::factory()->active()->create([
            'document_type' => '01',
            'establishment_id' => $this->matriz->id,
            'expiration_date' => now()->addDays(10)->toDateString(),
            'range_start' => 1,
            'range_end' => 500,
        ]);

        // "Sucesor" en OTRA sucursal — NO cuenta para la matriz.
        CaiRange::factory()->create([
            'document_type' => '01',
            'establishment_id' => $sucursal->id,
            'expiration_date' => now()->addMonths(12)->toDateString(),
            'range_start' => 501,
            'range_end' => 1000,
            'is_active' => false,
        ]);

        $alerts = $this->checker->check();

        $this->assertCount(1, $alerts);
        $this->assertFalse($alerts->first()->hasSuccessor);
        $this->assertSame(CaiAlertSeverity::Critical, $alerts->first()->severity);
    }

    public function test_no_considera_sucesor_un_cai_vencido_o_agotado(): void
    {
        $this->setUmbrales([30, 15, 7]);

        CaiRange::factory()->active()->create([
            'document_type' => '01',
            'establishment_id' => $this->matriz->id,
            'expiration_date' => now()->addDays(10)->toDateString(),
            'range_start' => 1,
            'range_end' => 500,
        ]);

        // "Sucesor" vencido → no cuenta.
        CaiRange::factory()->expired()->create([
            'document_type' => '01',
            'establishment_id' => $this->matriz->id,
            'range_start' => 501,
            'range_end' => 1000,
            'is_active' => false,
        ]);

        // "Sucesor" agotado → no cuenta.
        // NOTA: no uso el state ->exhausted() aquí porque su closure lee
        // $attrs['range_end'] ANTES de que create() aplique el override. Con
        // range_end custom el state setea current_number al default (500) y el
        // resultado final es un CAI que parece activo. Seteo current_number
        // explícitamente para forzar agotamiento real.
        CaiRange::factory()->create([
            'document_type' => '01',
            'establishment_id' => $this->matriz->id,
            'range_start' => 1001,
            'range_end' => 1500,
            'current_number' => 1500, // agotado explícito
            'is_active' => false,
            'expiration_date' => now()->addMonths(12)->toDateString(),
        ]);

        $alerts = $this->checker->check();

        $this->assertCount(1, $alerts);
        $this->assertFalse(
            $alerts->first()->hasSuccessor,
            'Ni un CAI vencido ni un agotado deben contar como sucesor válido'
        );
    }

    public function test_ordena_alertas_por_fecha_de_vencimiento_asc(): void
    {
        $this->setUmbrales([30, 15, 7]);

        $masLejos = CaiRange::factory()->active()->create([
            'document_type' => '01',
            'establishment_id' => $this->matriz->id,
            'expiration_date' => now()->addDays(25)->toDateString(),
            'range_start' => 1,
            'range_end' => 500,
        ]);

        // No puedo crear dos activos del mismo (doc, estab), así que uso '03' (NC).
        $masProximo = CaiRange::factory()->active()->create([
            'document_type' => '03',
            'establishment_id' => $this->matriz->id,
            'expiration_date' => now()->addDays(5)->toDateString(),
            'range_start' => 1,
            'range_end' => 500,
        ]);

        $alerts = $this->checker->check();

        $this->assertCount(2, $alerts);
        $this->assertSame($masProximo->id, $alerts->first()->cai->id);
        $this->assertSame($masLejos->id, $alerts->last()->cai->id);
    }
}

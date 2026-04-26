<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Models\CaiRange;
use App\Models\CompanySetting;
use App\Models\Establishment;
use App\Models\User;
use App\Notifications\CaiFailoverFailedNotification;
use App\Services\Invoicing\Exceptions\CaiSinSucesorException;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Cubre CaiFailoverFailedNotification:
 *   - via(): canal database SIEMPRE; canal mail SOLO si email_verified_at != null
 *   - toMail: subject [CRÍTICO], greeting al usuario, listado con un bullet
 *     por cada CAI bloqueado, action "Gestionar CAIs"
 *   - failureLabel respeta los dos motivos (vencido / agotado)
 *   - alcance centralizado se etiqueta distinto que sucursal
 *
 * Estos son tests de "contrato de la notificación" — no validan que el job
 * la haya enviado (eso lo cubre ExecuteCaiFailoverJobTest). Aquí solo
 * verificamos que dado un input la notificación produce la salida esperada.
 */
class CaiFailoverFailedNotificationTest extends TestCase
{
    use RefreshDatabase;

    private CompanySetting $company;

    private Establishment $matriz;

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
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    /**
     * Construye una colección con UN failure: CAI vencido en la matriz, sin sucesor.
     */
    private function singleFailure(string $reason = CaiSinSucesorException::REASON_EXPIRED, ?int $establishmentId = null): Collection
    {
        $cai = CaiRange::factory()->active()->create([
            'document_type' => '01',
            'establishment_id' => $establishmentId ?? $this->matriz->id,
            'expiration_date' => now()->subDay()->toDateString(),
            'range_start' => 1,
            'range_end' => 500,
        ]);

        return collect([
            [
                'cai' => $cai,
                'exception' => new CaiSinSucesorException(
                    caiRangeId: $cai->id,
                    cai: $cai->cai,
                    documentType: $cai->document_type,
                    establishmentId: $cai->establishment_id,
                    reason: $reason,
                ),
            ],
        ]);
    }

    public function test_via_solo_database_cuando_email_no_esta_verificado(): void
    {
        $user = User::factory()->create(['email_verified_at' => null]);

        $notification = new CaiFailoverFailedNotification($this->singleFailure());

        $this->assertSame(['database'], $notification->via($user));
    }

    public function test_via_incluye_mail_cuando_email_esta_verificado(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);

        $notification = new CaiFailoverFailedNotification($this->singleFailure());

        $this->assertSame(['database', 'mail'], $notification->via($user));
    }

    public function test_to_mail_subject_marca_critico_y_cuenta_los_cais(): void
    {
        $user = User::factory()->create(['name' => 'Mauricio', 'email_verified_at' => now()]);

        $failures = $this->singleFailure();
        $notification = new CaiFailoverFailedNotification($failures);

        $mail = $notification->toMail($user);

        $this->assertInstanceOf(MailMessage::class, $mail);
        $this->assertStringContainsString('[CRÍTICO]', $mail->subject);
        $this->assertStringContainsString('1 CAI(s) bloqueado(s)', $mail->subject);
        $this->assertStringContainsString('Hola Mauricio', $mail->greeting);
        $this->assertSame('Gestionar CAIs', $mail->actionText);
    }

    public function test_to_mail_lista_un_bullet_por_cada_failure_con_motivo_legible(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);

        // 2 failures: uno vencido, uno agotado — para validar ambos labels.
        $cai1 = CaiRange::factory()->active()->create([
            'document_type' => '01',
            'establishment_id' => $this->matriz->id,
            'expiration_date' => now()->subDay()->toDateString(),
            'range_start' => 1,
            'range_end' => 500,
        ]);

        $cai2 = CaiRange::factory()->active()->exhausted()->create([
            'document_type' => '03',
            'establishment_id' => $this->matriz->id,
            'expiration_date' => now()->addMonths(6)->toDateString(),
            'range_start' => 1,
            'range_end' => 500,
        ]);

        $failures = collect([
            ['cai' => $cai1, 'exception' => new CaiSinSucesorException(
                caiRangeId: $cai1->id,
                cai: $cai1->cai,
                documentType: $cai1->document_type,
                establishmentId: $cai1->establishment_id,
                reason: CaiSinSucesorException::REASON_EXPIRED,
            )],
            ['cai' => $cai2, 'exception' => new CaiSinSucesorException(
                caiRangeId: $cai2->id,
                cai: $cai2->cai,
                documentType: $cai2->document_type,
                establishmentId: $cai2->establishment_id,
                reason: CaiSinSucesorException::REASON_EXHAUSTED,
            )],
        ]);

        $mail = (new CaiFailoverFailedNotification($failures))->toMail($user);

        // Las introLines incluyen el cuerpo principal; busco que los CAIs y
        // los motivos legibles aparezcan listados.
        $intro = collect($mail->introLines)->implode("\n");

        $this->assertStringContainsString('2 CAI(s) bloqueado(s)', $mail->subject); // el subject refleja el conteo
        $this->assertStringContainsString($cai1->cai, $intro);
        $this->assertStringContainsString($cai2->cai, $intro);
        $this->assertStringContainsString('vencido', $intro);
        $this->assertStringContainsString('agotado', $intro);
        $this->assertStringContainsString("establecimiento #{$this->matriz->id}", $intro);
    }

    public function test_to_mail_etiqueta_alcance_centralizado_distinto_a_sucursal(): void
    {
        $user = User::factory()->create(['email_verified_at' => now()]);

        // CAI con establishment_id=null → centralizado.
        $cai = CaiRange::factory()->active()->create([
            'document_type' => '01',
            'establishment_id' => null,
            'expiration_date' => now()->subDay()->toDateString(),
            'range_start' => 1,
            'range_end' => 500,
        ]);

        $failures = collect([
            ['cai' => $cai, 'exception' => new CaiSinSucesorException(
                caiRangeId: $cai->id,
                cai: $cai->cai,
                documentType: $cai->document_type,
                establishmentId: null,
                reason: CaiSinSucesorException::REASON_EXPIRED,
            )],
        ]);

        $mail = (new CaiFailoverFailedNotification($failures))->toMail($user);
        $intro = collect($mail->introLines)->implode("\n");

        $this->assertStringContainsString('empresa (centralizado)', $intro);
        $this->assertStringNotContainsString('establecimiento #', $intro);
    }
}

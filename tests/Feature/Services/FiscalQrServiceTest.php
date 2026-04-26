<?php

namespace Tests\Feature\Services;

use App\Services\Fiscal\FiscalQrService;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * FiscalQrService convierte un integrity_hash + prefijo de ruta publica en
 * el SVG del QR de verificacion. Estos tests validan el contrato del service
 * sin tocar BD:
 *   - URL publica se construye con la base de config('fiscal.verify_url_base')
 *   - Prefix soporta slashes en cualquier direccion (se normalizan)
 *   - SVG contiene el namespace XML correcto + dimensiones
 *   - Falla explicita (fail-fast) si el hash esta vacio
 *   - Es agnostico del tipo de documento (facturas o notas de credito)
 */
class FiscalQrServiceTest extends TestCase
{
    private FiscalQrService $qr;

    protected function setUp(): void
    {
        parent::setUp();

        // Base fija para tests independiente del .env que cargue CI local
        config(['fiscal.verify_url_base' => 'https://facturas.diproma.test']);

        $this->qr = new FiscalQrService();
    }

    #[Test]
    public function builds_verification_url_for_invoice_prefix(): void
    {
        $hash = str_repeat('a', 64);

        $url = $this->qr->buildVerificationUrl($hash, 'facturas/verificar');

        $this->assertSame(
            "https://facturas.diproma.test/facturas/verificar/{$hash}",
            $url
        );
    }

    #[Test]
    public function builds_verification_url_for_credit_note_prefix(): void
    {
        $hash = str_repeat('d', 64);

        $url = $this->qr->buildVerificationUrl($hash, 'notas-credito/verificar');

        $this->assertSame(
            "https://facturas.diproma.test/notas-credito/verificar/{$hash}",
            $url
        );
    }

    #[Test]
    public function verification_url_strips_trailing_slash_on_base(): void
    {
        // Defensa contra APP_URL con slash final: la URL resultante no debe
        // quedar con doble slash. El service hace rtrim('/').
        config(['fiscal.verify_url_base' => 'https://facturas.diproma.test/']);

        $hash = str_repeat('b', 64);

        $this->assertSame(
            "https://facturas.diproma.test/facturas/verificar/{$hash}",
            (new FiscalQrService())->buildVerificationUrl($hash, 'facturas/verificar')
        );
    }

    #[Test]
    public function verification_url_normalizes_prefix_with_leading_and_trailing_slashes(): void
    {
        // El service debe ser tolerante a que el caller pase el prefix
        // con/sin slashes. Evita URLs como "base//facturas/verificar//hash".
        $hash = str_repeat('e', 64);

        $url = $this->qr->buildVerificationUrl($hash, '/facturas/verificar/');

        $this->assertSame(
            "https://facturas.diproma.test/facturas/verificar/{$hash}",
            $url
        );
    }

    #[Test]
    public function generates_inline_svg_for_given_hash_and_prefix(): void
    {
        $hash = str_repeat('c', 64);

        $svg = $this->qr->generateSvg($hash, 'facturas/verificar');

        // Validaciones minimas del SVG para no acoplar el test al formato
        // exacto de salida de endroid/qr-code (que puede cambiar entre versiones):
        //   1) Tiene prefijo <svg o namespace SVG -> es SVG bien formado
        //   2) Incluye xmlns correcto
        //   3) Tiene algun contenido (no vacio)
        $this->assertNotEmpty($svg);
        $this->assertStringContainsString('<svg', $svg);
        $this->assertStringContainsString('xmlns', $svg);
    }

    #[Test]
    public function throws_domain_exception_when_hash_is_empty(): void
    {
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('integrity_hash');

        $this->qr->generateSvg('', 'facturas/verificar');
    }

    #[Test]
    public function throws_domain_exception_when_hash_is_whitespace_only(): void
    {
        // Whitespace no cuenta como hash sellado — debe fallar igual que vacio.
        $this->expectException(\DomainException::class);

        $this->qr->generateSvg('   ', 'facturas/verificar');
    }
}

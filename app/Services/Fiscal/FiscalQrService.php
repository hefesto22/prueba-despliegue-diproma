<?php

namespace App\Services\Fiscal;

use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\RoundBlockSizeMode;
use Endroid\QrCode\Writer\SvgWriter;

/**
 * Genera el codigo QR de verificacion publica para documentos fiscales.
 *
 * Responsabilidad unica (SRP): convertir un integrity_hash + path de verificacion
 * en un SVG inline del QR. Agnostico del tipo de documento — lo usan facturas,
 * notas de credito, y en el futuro cualquier documento fiscal con hash + ruta
 * publica (recibos, notas de debito, etc.).
 *
 * Se retorna SVG vectorial (no PNG) por tres razones:
 *   1) Sin archivos temporales en disco: el resultado se inyecta directo en
 *      la Blade con {!! $qr !!}.
 *   2) Nitidez infinita en impresion (incluso en papel termico).
 *   3) Compatible con "Guardar como PDF" del dialogo de impresion del navegador
 *      sin degradacion de resolucion.
 */
class FiscalQrService
{
    /**
     * Tamano por defecto del QR en pixeles (aprox 3.5 cm impreso a 96dpi).
     * Suficiente para que una camara de celular lo escanee sin esfuerzo.
     */
    private const DEFAULT_SIZE_PX = 140;

    /**
     * Genera el SVG inline del QR de verificacion publica.
     *
     * @param  string  $hash         integrity_hash del documento (SHA-256, 64 hex chars).
     * @param  string  $pathPrefix   Prefijo de la ruta publica de verificacion.
     *                               Ej: 'facturas/verificar', 'notas-credito/verificar'.
     *                               Se normaliza: se eliminan slashes iniciales/finales.
     * @param  int     $sizePixels   Tamano del SVG en pixeles.
     *
     * @throws \DomainException si el hash esta vacio (documento no sellado).
     */
    public function generateSvg(
        string $hash,
        string $pathPrefix,
        int $sizePixels = self::DEFAULT_SIZE_PX,
    ): string {
        $this->assertHashIsSealed($hash);

        $url = $this->buildVerificationUrl($hash, $pathPrefix);

        return $this->renderSvg($url, $sizePixels);
    }

    /**
     * Construye la URL publica de verificacion para un integrity_hash.
     *
     * Expuesto como metodo publico porque la vista de impresion muestra la URL
     * en texto legible debajo del QR como respaldo (para casos donde el QR no
     * escanea o el usuario quiere teclear la URL).
     *
     * @param  string  $hash         integrity_hash del documento.
     * @param  string  $pathPrefix   Prefijo de la ruta publica (sin slashes).
     */
    public function buildVerificationUrl(string $hash, string $pathPrefix): string
    {
        $base   = rtrim((string) config('fiscal.verify_url_base'), '/');
        $prefix = trim($pathPrefix, '/');

        return "{$base}/{$prefix}/{$hash}";
    }

    /**
     * Fail-fast: un documento sin integrity_hash no puede tener QR de verificacion.
     * Esto indica que no fue sellado (no paso por su *Service::generateFromX()),
     * y generar un QR a un hash vacio produciria una URL invalida de verificacion.
     */
    private function assertHashIsSealed(string $hash): void
    {
        if (trim($hash) === '') {
            throw new \DomainException(
                'No se puede generar QR: el integrity_hash esta vacio. '
                .'Esto indica que el documento fiscal no ha sido sellado.'
            );
        }
    }

    /**
     * Renderizado via endroid/qr-code v6:
     *   - ErrorCorrectionLevel Medium (~15%): balance entre densidad y robustez;
     *     suficiente para impresion en papel termico sin sacrificar legibilidad.
     *   - Margin 0: el espaciado lo controla el CSS de la vista, no el QR.
     *   - RoundBlockSizeMode Margin: evita desalineacion sub-pixel en impresion.
     *
     * Nota sobre la API: en v6 Builder es `final readonly class` con constructor
     * por argumentos nombrados. No hay factory estatico `create()`.
     */
    private function renderSvg(string $data, int $sizePixels): string
    {
        $result = (new Builder(
            writer: new SvgWriter(),
            data: $data,
            encoding: new Encoding('UTF-8'),
            errorCorrectionLevel: ErrorCorrectionLevel::Medium,
            size: $sizePixels,
            margin: 0,
            roundBlockSizeMode: RoundBlockSizeMode::Margin,
        ))->build();

        return $result->getString();
    }
}

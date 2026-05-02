<?php

namespace App\Services\Repairs;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Convierte fotos subidas a formato WebP optimizado.
 *
 * Por qué WebP:
 *   - 25-35% más pequeño que JPG con calidad visual equivalente.
 *   - Soportado por todos los navegadores modernos (Chrome, Safari, Firefox, Edge).
 *   - En hosting compartido cada MB cuenta — fotos de reparaciones tomadas
 *     con teléfono pesan típicamente 4-12 MB. Con WebP + resize a 1920px,
 *     bajan a ~200-500 KB sin pérdida visual relevante.
 *
 * Por qué GD nativa (no Intervention Image):
 *   - PHP 8.4 trae GD con soporte WebP, JPG, PNG, GIF out-of-the-box.
 *   - Cero dependencias adicionales en composer.json.
 *   - Si el negocio pide HEIC en el futuro (poco común desde web upload
 *     porque el navegador convierte), agregamos `intervention/image` con
 *     `imagick` y este service crece sin afectar el caller.
 *
 * Estrategia de calidad: 80 sobre 100 (sweet spot probado de WebP).
 * Por debajo de 70 se nota artefactos en zonas de gradiente; por encima
 * de 85 el peso sube sin ganancia visual perceptible.
 */
class RepairPhotoConverter
{
    private const QUALITY = 80;
    private const MAX_DIMENSION = 1920;
    private const SUPPORTED_MIMES = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];

    /**
     * Convierte un archivo de imagen subido a WebP optimizado.
     *
     * Acepta el path RELATIVO al disk 'public' (lo que devuelve Filament's
     * FileUpload). Lee el archivo, lo convierte/resize, escribe el WebP en
     * el directorio destino, y borra el original.
     *
     * @param  string  $sourcePath  Path relativo dentro del disk 'public'.
     *                              Ej: "tmp/abc123.jpg"
     * @param  string  $destDirectory  Directorio relativo donde guardar el WebP.
     *                                 Ej: "repairs/42"
     *
     * @return string  Path relativo del archivo WebP final dentro del disk.
     *                 Ej: "repairs/42/photo-9f8e7d6c.webp"
     *
     * @throws \RuntimeException Si el archivo no existe, no es imagen válida,
     *                          o GD no puede procesarlo.
     */
    public function convertToWebp(string $sourcePath, string $destDirectory): string
    {
        $disk = Storage::disk('public');

        if (! $disk->exists($sourcePath)) {
            throw new \RuntimeException("RepairPhotoConverter: archivo no encontrado en disk public: {$sourcePath}");
        }

        $absoluteSource = $disk->path($sourcePath);
        $mime = mime_content_type($absoluteSource);

        if (! in_array($mime, self::SUPPORTED_MIMES, true)) {
            throw new \RuntimeException(
                "RepairPhotoConverter: formato no soportado ({$mime}). "
                . "Soportados: " . implode(', ', self::SUPPORTED_MIMES)
            );
        }

        // Asegurar destDirectory dentro del disk
        if (! $disk->exists($destDirectory)) {
            $disk->makeDirectory($destDirectory);
        }

        $finalRelativePath = trim($destDirectory, '/') . '/photo-' . Str::random(12) . '.webp';
        $finalAbsolutePath = $disk->path($finalRelativePath);

        // Cargar la imagen al recurso GD según mime
        $image = $this->loadImage($absoluteSource, $mime);

        try {
            // Resize si excede MAX_DIMENSION
            $image = $this->resizeIfNeeded($image);

            // Persistir como WebP
            if (! imagewebp($image, $finalAbsolutePath, self::QUALITY)) {
                throw new \RuntimeException("RepairPhotoConverter: imagewebp falló al escribir: {$finalAbsolutePath}");
            }
        } finally {
            imagedestroy($image);
        }

        // Borrar el archivo original (ya no lo necesitamos — sería duplicación)
        $disk->delete($sourcePath);

        return $finalRelativePath;
    }

    /**
     * Cargar archivo de imagen al recurso GD según su mime.
     *
     * @return \GdImage
     */
    private function loadImage(string $absolutePath, string $mime): \GdImage
    {
        $image = match ($mime) {
            'image/jpeg' => @imagecreatefromjpeg($absolutePath),
            'image/png' => @imagecreatefrompng($absolutePath),
            'image/webp' => @imagecreatefromwebp($absolutePath),
            'image/gif' => @imagecreatefromgif($absolutePath),
            default => false,
        };

        if ($image === false) {
            throw new \RuntimeException(
                "RepairPhotoConverter: GD no pudo leer la imagen ({$mime}). "
                . "Posible archivo corrupto o no soportado."
            );
        }

        return $image;
    }

    /**
     * Reescala manteniendo aspect ratio si alguna dimensión excede el máximo.
     *
     * Si la imagen ya está bajo el límite la retorna sin cambios para
     * evitar trabajo innecesario y conservar nitidez (cada resample
     * introduce micro-pérdida).
     */
    private function resizeIfNeeded(\GdImage $source): \GdImage
    {
        $srcW = imagesx($source);
        $srcH = imagesy($source);
        $max = max($srcW, $srcH);

        if ($max <= self::MAX_DIMENSION) {
            return $source;
        }

        $scale = self::MAX_DIMENSION / $max;
        $dstW = (int) round($srcW * $scale);
        $dstH = (int) round($srcH * $scale);

        $resized = imagecreatetruecolor($dstW, $dstH);
        // Preservar transparencia (PNG → WebP)
        imagealphablending($resized, false);
        imagesavealpha($resized, true);

        imagecopyresampled($resized, $source, 0, 0, 0, 0, $dstW, $dstH, $srcW, $srcH);
        imagedestroy($source);

        return $resized;
    }
}

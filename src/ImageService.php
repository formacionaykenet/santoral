<?php

declare(strict_types=1);

namespace Santoral;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;

class ImageService
{
    private Client $http;
    private Logger $logger;
    private string $imagesDir;

    // Colores en RGB
    private const COLOR_WHITE  = [255, 255, 255];
    private const COLOR_GOLD   = [212, 175,  55];
    private const COLOR_DARK   = [ 20,  20,  40];
    private const COLOR_BLUE   = [ 30,  60, 120];
    private const COLOR_CREAM  = [255, 248, 220];

    public function __construct(Logger $logger)
    {
        $this->logger    = $logger;
        $this->imagesDir = STORAGE_PATH . '/images';

        if (!is_dir($this->imagesDir)) {
            mkdir($this->imagesDir, 0755, true);
        }

        $this->http = new Client(['timeout' => 60]);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // DALL-E 3: generar retrato del santo
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Llama a DALL-E 3 y descarga la imagen generada.
     * Devuelve la ruta local del archivo descargado.
     */
    public function generateDallePortrait(string $saintName): string
    {
        $this->logger->info("Generando imagen DALL-E 3 para: {$saintName}");

        $prompt = "Artistic portrait of {$saintName}, Christian iconography style inspired by Italian Renaissance paintings. "
            . "Warm golden and celestial blue tones, divine light halo around the head, ornamental golden border frame, "
            . "rich jewel-like colors, detailed religious art style. "
            . "High quality, no text, no watermarks.";

        try {
            $response = $this->http->post(OPENAI_API_BASE . '/images/generations', [
                'headers' => [
                    'Authorization' => 'Bearer ' . OPENAI_API_KEY,
                    'Content-Type'  => 'application/json',
                ],
                'json' => [
                    'model'   => 'dall-e-3',
                    'prompt'  => $prompt,
                    'n'       => 1,
                    'size'    => '1024x1024',
                    'quality' => 'standard',
                    'style'   => 'vivid',
                ],
            ]);

            $body    = json_decode((string) $response->getBody(), true);
            $url     = $body['data'][0]['url'] ?? null;

            if (!$url) {
                throw new \RuntimeException('DALL-E no devolvió URL de imagen');
            }

            return $this->downloadImage($url, 'dalle_' . $this->slug($saintName));

        } catch (RequestException $e) {
            throw new \RuntimeException('Error en DALL-E 3: ' . $e->getMessage());
        }
    }

    private function downloadImage(string $url, string $baseName): string
    {
        $dest = $this->imagesDir . '/' . $baseName . '_' . date('Ymd') . '.jpg';
        $imageData = file_get_contents($url);

        if ($imageData === false) {
            throw new \RuntimeException("No se pudo descargar la imagen desde: {$url}");
        }

        file_put_contents($dest, $imageData);
        $this->logger->debug("Imagen descargada: {$dest}");
        return $dest;
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Instagram: imagen 1080x1080
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Compone la imagen final para Instagram (1080x1080 JPEG).
     * Capas: imagen DALL-E → gradiente oscuro → marco dorado → nombre del santo → fecha.
     */
    public function composeInstagramImage(
        string $dalleImagePath,
        string $saintName,
        string $dateLabel,
        string $biography
    ): string {
        $this->logger->info("Componiendo imagen Instagram para: {$saintName}");

        $w = 1080;
        $h = 1080;

        $canvas = imagecreatetruecolor($w, $h);
        imagealphablending($canvas, true);
        imagesavealpha($canvas, true);

        // 1. Fondo: escalar imagen DALL-E a 1080x1080
        $source = $this->loadAndResize($dalleImagePath, $w, $h);
        imagecopy($canvas, $source, 0, 0, 0, 0, $w, $h);
        imagedestroy($source);

        // 2. Gradiente oscuro en la mitad inferior (para legibilidad del texto)
        $this->applyBottomGradient($canvas, $w, $h, 0.55, 180);

        // 3. Franja superior semitransparente (pequeña)
        $this->applyTopBar($canvas, $w);

        // 4. Marco decorativo dorado
        $this->drawGoldenBorder($canvas, $w, $h, 18);

        // 5. Texto: "Santo del día" en la parte superior
        $this->drawText(
            $canvas,
            '✝  SANTO DEL DÍA  ✝',
            FONT_BOLD,
            32,
            self::COLOR_GOLD,
            $w / 2,
            52,
            'center'
        );

        // 6. Nombre del santo (grande, blanco, parte inferior)
        $nameFontSize = $this->adaptFontSize($saintName, 72, 20);
        $this->drawText(
            $canvas,
            strtoupper($saintName),
            FONT_BOLD,
            $nameFontSize,
            self::COLOR_WHITE,
            $w / 2,
            $h - 200,
            'center'
        );

        // 7. Fecha
        $this->drawText(
            $canvas,
            $dateLabel,
            FONT_BOLD,
            38,
            self::COLOR_GOLD,
            $w / 2,
            $h - 130,
            'center'
        );

        // 8. Handle social
        $this->drawText(
            $canvas,
            SOCIAL_HANDLE,
            FONT_BOLD,
            30,
            [200, 200, 200],
            $w / 2,
            $h - 50,
            'center'
        );

        // Guardar
        $destPath = $this->imagesDir . '/ig_' . $this->slug($saintName) . '_' . date('Ymd') . '.jpg';
        imagejpeg($canvas, $destPath, 92);
        imagedestroy($canvas);

        $this->logger->debug("Imagen Instagram guardada: {$destPath}");
        return $destPath;
    }

    // ──────────────────────────────────────────────────────────────────────────
    // TikTok: 4 slides 1080x1920
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Crea los 4 slides para el vídeo TikTok.
     * Devuelve array con rutas de los 4 archivos PNG.
     */
    public function composeTikTokSlides(
        string $dalleImagePath,
        string $saintName,
        string $dateLabel,
        string $biography,
        string $hook,
        string $story,
        string $cta
    ): array {
        $this->logger->info("Componiendo 4 slides TikTok para: {$saintName}");

        return [
            $this->makeTikTokSlide1($dalleImagePath, $saintName, $dateLabel, $hook),
            $this->makeTikTokSlide2($dalleImagePath, $saintName, $biography),
            $this->makeTikTokSlide3($saintName, $story),
            $this->makeTikTokSlide4($saintName, $cta),
        ];
    }

    /** Slide 1: Portada — imagen del santo + título */
    private function makeTikTokSlide1(string $dalleImagePath, string $saintName, string $dateLabel, string $hook): string
    {
        $w = 1080; $h = 1920;
        $canvas = imagecreatetruecolor($w, $h);

        // Fondo degradado azul celestial
        $this->fillGradientVertical($canvas, $w, $h, [10, 30, 80], [60, 100, 180]);

        // Imagen del santo centrada en la mitad superior
        $source  = $this->loadAndResize($dalleImagePath, 900, 900);
        $imgX    = ($w - 900) / 2;
        imagecopy($canvas, $source, (int)$imgX, 60, 0, 0, 900, 900);
        imagedestroy($source);

        // Gradiente sobre la imagen para suavizar
        $this->applyBottomGradient($canvas, $w, $h, 0.52, 210);

        // Marco dorado
        $this->drawGoldenBorder($canvas, $w, $h, 20);

        // "SANTO DEL DÍA" arriba
        $this->drawText($canvas, '✝  SANTO DEL DÍA  ✝', FONT_BOLD, 36, self::COLOR_GOLD, $w / 2, 40, 'center');

        // Nombre del santo
        $fontSize = $this->adaptFontSize($saintName, 70, 24);
        $this->drawText($canvas, strtoupper($saintName), FONT_BOLD, $fontSize, self::COLOR_WHITE, $w / 2, 1020, 'center');

        // Fecha
        $this->drawText($canvas, $dateLabel, FONT_BOLD, 42, self::COLOR_GOLD, $w / 2, 1110, 'center');

        // Hook (texto pequeño)
        $this->drawWrappedText($canvas, '"' . $hook . '"', FONT_BOLD, 32, self::COLOR_CREAM, $w / 2, 1220, $w - 120, 'center');

        // Handle
        $this->drawText($canvas, SOCIAL_HANDLE, FONT_BOLD, 34, [180, 180, 180], $w / 2, $h - 40, 'center');

        $path = $this->imagesDir . '/tiktok_slide1_' . $this->slug($saintName) . '_' . date('Ymd') . '.png';
        imagepng($canvas, $path);
        imagedestroy($canvas);
        return $path;
    }

    /** Slide 2: Primeros 3 párrafos de la biografía */
    private function makeTikTokSlide2(string $dalleImagePath, string $saintName, string $biography): string
    {
        $w = 1080; $h = 1920;
        $canvas = imagecreatetruecolor($w, $h);

        // Fondo oscuro con imagen difuminada de fondo
        $bg = $this->loadAndResize($dalleImagePath, $w, $h);
        imagecopy($canvas, $bg, 0, 0, 0, 0, $w, $h);
        imagedestroy($bg);

        // Overlay oscuro 80%
        $this->applyDarkOverlay($canvas, $w, $h, 200);

        $this->drawGoldenBorder($canvas, $w, $h, 20);

        $this->drawText($canvas, '📖  SU HISTORIA', FONT_BOLD, 48, self::COLOR_GOLD, $w / 2, 80, 'center');
        $this->drawText($canvas, strtoupper($saintName), FONT_BOLD, 42, self::COLOR_WHITE, $w / 2, 160, 'center');

        // Texto de biografía (primera mitad)
        $half = mb_substr($biography, 0, (int)(mb_strlen($biography) / 2));
        $this->drawWrappedText($canvas, $half, FONT_REGULAR ?? FONT_BOLD, 36, self::COLOR_CREAM, $w / 2, 280, $w - 100, 'center');

        $this->drawText($canvas, SOCIAL_HANDLE, FONT_BOLD, 30, [150, 150, 150], $w / 2, $h - 40, 'center');

        $path = $this->imagesDir . '/tiktok_slide2_' . $this->slug($saintName) . '_' . date('Ymd') . '.png';
        imagepng($canvas, $path);
        imagedestroy($canvas);
        return $path;
    }

    /** Slide 3: Script TikTok (historia) */
    private function makeTikTokSlide3(string $saintName, string $story): string
    {
        $w = 1080; $h = 1920;
        $canvas = imagecreatetruecolor($w, $h);

        // Fondo degradado dorado-oscuro
        $this->fillGradientVertical($canvas, $w, $h, [40, 20, 5], [100, 60, 10]);
        $this->applyDarkOverlay($canvas, $w, $h, 120);
        $this->drawGoldenBorder($canvas, $w, $h, 20);

        $this->drawText($canvas, '🕊️  VIRTUD Y FE', FONT_BOLD, 48, self::COLOR_GOLD, $w / 2, 80, 'center');
        $this->drawText($canvas, strtoupper($saintName), FONT_BOLD, 42, self::COLOR_WHITE, $w / 2, 160, 'center');

        $this->drawWrappedText($canvas, $story, FONT_BOLD, 40, self::COLOR_CREAM, $w / 2, 320, $w - 100, 'center');

        // Cruz decorativa central inferior
        $this->drawText($canvas, '✝', FONT_BOLD, 100, self::COLOR_GOLD, $w / 2, $h - 200, 'center');
        $this->drawText($canvas, SOCIAL_HANDLE, FONT_BOLD, 30, [180, 180, 180], $w / 2, $h - 40, 'center');

        $path = $this->imagesDir . '/tiktok_slide3_' . $this->slug($saintName) . '_' . date('Ymd') . '.png';
        imagepng($canvas, $path);
        imagedestroy($canvas);
        return $path;
    }

    /** Slide 4: Call-to-action */
    private function makeTikTokSlide4(string $saintName, string $cta): string
    {
        $w = 1080; $h = 1920;
        $canvas = imagecreatetruecolor($w, $h);

        // Fondo azul real + dorado
        $this->fillGradientVertical($canvas, $w, $h, [10, 20, 70], [30, 80, 160]);
        $this->drawGoldenBorder($canvas, $w, $h, 24);

        // Cruz grande decorativa
        $this->drawText($canvas, '✝', FONT_BOLD, 200, self::COLOR_GOLD, $w / 2, 420, 'center');

        $this->drawText($canvas, '¡SÍGUENOS!', FONT_BOLD, 72, self::COLOR_WHITE, $w / 2, 700, 'center');
        $this->drawText($canvas, SOCIAL_HANDLE, FONT_BOLD, 64, self::COLOR_GOLD, $w / 2, 790, 'center');

        $this->drawWrappedText($canvas, $cta, FONT_BOLD, 40, self::COLOR_CREAM, $w / 2, 920, $w - 120, 'center');

        $this->drawText($canvas, '👍 Like  💬 Comenta  🔔 Suscríbete', FONT_BOLD, 36, self::COLOR_WHITE, $w / 2, 1350, 'center');

        $path = $this->imagesDir . '/tiktok_slide4_' . $this->slug($saintName) . '_' . date('Ymd') . '.png';
        imagepng($canvas, $path);
        imagedestroy($canvas);
        return $path;
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Utilidades GD
    // ──────────────────────────────────────────────────────────────────────────

    private function loadAndResize(string $path, int $w, int $h): \GdImage
    {
        $info = getimagesize($path);
        $type = $info[2] ?? IMAGETYPE_JPEG;

        $src = match ($type) {
            IMAGETYPE_PNG  => imagecreatefrompng($path),
            IMAGETYPE_WEBP => imagecreatefromwebp($path),
            default        => imagecreatefromjpeg($path),
        };

        $canvas = imagecreatetruecolor($w, $h);
        imagealphablending($canvas, true);
        imagecopyresampled($canvas, $src, 0, 0, 0, 0, $w, $h, imagesx($src), imagesy($src));
        imagedestroy($src);

        return $canvas;
    }

    private function applyBottomGradient(\GdImage $canvas, int $w, int $h, float $startAt, int $maxAlpha): void
    {
        $startY = (int)($h * $startAt);
        for ($y = $startY; $y < $h; $y++) {
            $progress = ($y - $startY) / ($h - $startY);
            $alpha    = (int)($maxAlpha * $progress);
            $color    = imagecolorallocatealpha($canvas, 0, 0, 0, 127 - (int)($alpha * 127 / 255));
            imageline($canvas, 0, $y, $w, $y, $color);
        }
    }

    private function applyTopBar(\GdImage $canvas, int $w): void
    {
        for ($y = 0; $y < 80; $y++) {
            $alpha = (int)(80 * (1 - $y / 80));
            $color = imagecolorallocatealpha($canvas, 0, 0, 0, 127 - (int)($alpha * 127 / 255));
            imageline($canvas, 0, $y, $w, $y, $color);
        }
    }

    private function applyDarkOverlay(\GdImage $canvas, int $w, int $h, int $alpha): void
    {
        $overlay = imagecolorallocatealpha($canvas, 0, 0, 0, 127 - (int)($alpha * 127 / 255));
        imagefilledrectangle($canvas, 0, 0, $w, $h, $overlay);
    }

    private function drawGoldenBorder(\GdImage $canvas, int $w, int $h, int $thickness): void
    {
        $gold = imagecolorallocate($canvas, ...self::COLOR_GOLD);
        for ($i = 0; $i < $thickness; $i++) {
            imagerectangle($canvas, $i, $i, $w - 1 - $i, $h - 1 - $i, $gold);
        }
    }

    private function fillGradientVertical(\GdImage $canvas, int $w, int $h, array $from, array $to): void
    {
        for ($y = 0; $y < $h; $y++) {
            $t   = $y / $h;
            $r   = (int)($from[0] + ($to[0] - $from[0]) * $t);
            $g   = (int)($from[1] + ($to[1] - $from[1]) * $t);
            $b   = (int)($from[2] + ($to[2] - $from[2]) * $t);
            $col = imagecolorallocate($canvas, $r, $g, $b);
            imageline($canvas, 0, $y, $w, $y, $col);
        }
    }

    private function drawText(
        \GdImage $canvas,
        string $text,
        string $font,
        int $size,
        array $color,
        float $x,
        float $y,
        string $align = 'left'
    ): void {
        if (!file_exists($font)) {
            return;
        }

        $col = imagecolorallocate($canvas, ...$color);

        if ($align === 'center') {
            $bbox = imagettfbbox($size, 0, $font, $text);
            $textW = $bbox[2] - $bbox[0];
            $x     = $x - $textW / 2;
        }

        imagettftext($canvas, $size, 0, (int)$x, (int)$y, $col, $font, $text);
    }

    private function drawWrappedText(
        \GdImage $canvas,
        string $text,
        string $font,
        int $size,
        array $color,
        float $x,
        float $startY,
        int $maxWidth,
        string $align = 'left'
    ): void {
        if (!file_exists($font)) {
            return;
        }

        $words    = explode(' ', $text);
        $lines    = [];
        $current  = '';

        foreach ($words as $word) {
            $test = $current === '' ? $word : "{$current} {$word}";
            $bbox = imagettfbbox($size, 0, $font, $test);
            $testW = $bbox[2] - $bbox[0];

            if ($testW > $maxWidth && $current !== '') {
                $lines[]  = $current;
                $current  = $word;
            } else {
                $current = $test;
            }
        }
        if ($current !== '') {
            $lines[] = $current;
        }

        $lineHeight = (int)($size * 1.5);
        $col        = imagecolorallocate($canvas, ...$color);

        foreach ($lines as $i => $line) {
            $drawX = $x;
            if ($align === 'center') {
                $bbox  = imagettfbbox($size, 0, $font, $line);
                $lineW = $bbox[2] - $bbox[0];
                $drawX = $x - $lineW / 2;
            }
            imagettftext($canvas, $size, 0, (int)$drawX, (int)($startY + $i * $lineHeight), $col, $font, $line);
        }
    }

    private function adaptFontSize(string $text, int $maxSize, int $minSize): int
    {
        $len = mb_strlen($text);
        if ($len <= 20) return $maxSize;
        if ($len <= 35) return (int)($maxSize * 0.8);
        if ($len <= 50) return (int)($maxSize * 0.65);
        return $minSize;
    }

    private function slug(string $text): string
    {
        $text = mb_strtolower($text);
        $text = preg_replace('/[áàäâã]/u', 'a', $text);
        $text = preg_replace('/[éèëê]/u', 'e', $text);
        $text = preg_replace('/[íìïî]/u', 'i', $text);
        $text = preg_replace('/[óòöôõ]/u', 'o', $text);
        $text = preg_replace('/[úùüû]/u', 'u', $text);
        $text = preg_replace('/ñ/u', 'n', $text);
        $text = preg_replace('/[^a-z0-9]+/', '_', $text);
        return trim($text, '_');
    }
}

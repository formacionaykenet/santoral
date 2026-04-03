#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Santoral Bot — Orquestador principal (modo Hosting Compartido)
 *
 * Adaptado para funcionar sin FFmpeg en hosting compartido (Hostinger).
 * TikTok usa Photo Carousel en lugar de vídeo.
 *
 * Cron en Hostinger hPanel (9:00 AM cada día):
 *   0 9 * * * /usr/local/bin/php /home/uXXXXXXX/santoral/run.php >> /home/uXXXXXXX/santoral/storage/logs/cron.log 2>&1
 *
 * Prueba con fecha concreta:
 *   php run.php --date=04-03
 *
 * Modo sin publicar (solo genera archivos locales):
 *   php run.php --dry-run
 */

require_once __DIR__ . '/config/config.php';

use Santoral\GeminiService;
use Santoral\ImageService;
use Santoral\InstagramService;
use Santoral\Logger;
use Santoral\SantoralService;
use Santoral\TikTokService;

// ── Argumentos de línea de comandos ───────────────────────────────────────────
$overrideDate = null;
$dryRun       = false;

foreach ($argv ?? [] as $arg) {
    if (str_starts_with($arg, '--date=')) {
        $overrideDate = substr($arg, 7);
    }
    if ($arg === '--dry-run') {
        $dryRun = true;
    }
}

// ── Idempotencia: no publicar dos veces el mismo día ─────────────────────────
$logger   = new Logger();
$dateKey  = $overrideDate ?? date('m-d');
$lockFile = STORAGE_PATH . '/logs/' . date('Y-m-d') . '.lock';

if (!$dryRun && file_exists($lockFile)) {
    $logger->warning("Ya se ejecutó hoy ({$dateKey}). Saliendo (borra el .lock para re-ejecutar).");
    exit(0);
}

$logger->info('═══════════════════════════════════════════════════════');
$logger->info('  Santoral Bot — Hosting Compartido');
$logger->info('  Fecha: ' . ($overrideDate ? "manual ({$overrideDate})" : date('Y-m-d H:i:s')));
$logger->info('  Modo: ' . ($dryRun ? 'DRY-RUN (sin publicar)' : 'PRODUCCIÓN'));
$logger->info('═══════════════════════════════════════════════════════');

$exitCode = 0;

try {
    // ── PASO 1: Santo del día ────────────────────────────────────────────────
    $santoral  = new SantoralService();
    $saints    = $santoral->getToday($overrideDate);
    $saintName = $saints[0];
    $allSaints = implode(' y ', $saints);

    // Fecha en español sin dependencia de locale
    $months = [
        1  => 'enero', 2  => 'febrero', 3  => 'marzo',    4  => 'abril',
        5  => 'mayo',  6  => 'junio',   7  => 'julio',    8  => 'agosto',
        9  => 'septiembre', 10 => 'octubre', 11 => 'noviembre', 12 => 'diciembre',
    ];
    $dateLabel = date('j') . ' de ' . $months[(int)date('m')];
    $dateStr   = $dateLabel . ' de ' . date('Y');

    $logger->info("Santo del día: {$allSaints}");

    // ── PASO 2: Contenido con Gemini ─────────────────────────────────────────
    $gemini  = new GeminiService($logger);
    $content = $gemini->generateAll($saintName, $dateStr);
    $logger->info('Contenido generado por Gemini OK');

    // ── PASO 3: Imagen DALL-E 3 ──────────────────────────────────────────────
    $imageService   = new ImageService($logger);
    $dalleImagePath = $imageService->generateDallePortrait($saintName);
    $logger->info("Imagen DALL-E generada: {$dalleImagePath}");

    // ── PASO 4: Imagen Instagram 1080x1080 ───────────────────────────────────
    $igImagePath = $imageService->composeInstagramImage(
        $dalleImagePath,
        $saintName,
        $dateLabel,
        $content['biography']
    );
    $logger->info("Imagen Instagram compuesta: {$igImagePath}");

    // ── PASO 5: Slides TikTok 1080x1920 (×4 para carrusel) ───────────────────
    $tikTokSlides = $imageService->composeTikTokSlides(
        $dalleImagePath,
        $saintName,
        $dateLabel,
        $content['biography'],
        $content['tiktok_hook'],
        $content['tiktok_story'],
        $content['tiktok_cta']
    );
    $logger->info('Slides TikTok compuestos: ' . count($tikTokSlides));

    // ── PASO 6: Exponer imágenes en URL pública ───────────────────────────────
    // Las imágenes deben estar accesibles vía HTTPS para que Meta y TikTok
    // puedan descargarlas. En Hostinger se sirven desde public_html/media/
    $publicUrls = $imageService->publishToPublicDir(
        array_merge([$igImagePath], $tikTokSlides)
    );
    $igPublicUrl     = $publicUrls[0];
    $slidesPublicUrls = array_slice($publicUrls, 1);
    $logger->info("Imágenes publicadas en URL pública", ['ig' => $igPublicUrl]);

    if (!$dryRun) {
        // ── PASO 7: Publicar en Instagram ─────────────────────────────────────
        $instagram = new InstagramService($logger);
        $igPostId  = $instagram->post($igPublicUrl, $content['ig_caption']);
        $logger->info("✅ Instagram publicado. Post ID: {$igPostId}");

        // ── PASO 8: Publicar en TikTok (Photo Carousel) ───────────────────────
        $tiktok   = new TikTokService($logger);
        $ttPostId = $tiktok->postPhotoCarousel(
            $slidesPublicUrls,
            $saintName,
            $content['tiktok_hook'] . "\n\n" . $content['tiktok_story'] . "\n\n" . $content['tiktok_cta']
        );
        $logger->info("✅ TikTok publicado. Publish ID: {$ttPostId}");

        // ── PASO 9: Limpiar imágenes públicas tras publicación ────────────────
        $imageService->cleanPublicDir();
    } else {
        $logger->info('DRY-RUN: publicación omitida. Archivos generados:');
        $logger->info("  IG image: {$igImagePath}");
        foreach ($tikTokSlides as $i => $slide) {
            $logger->info("  Slide " . ($i + 1) . ": {$slide}");
        }
    }

    // ── PASO 10: Limpiar archivos de storage >7 días ──────────────────────────
    $santoral->cleanupStorage(7);

    // ── Crear lock file ───────────────────────────────────────────────────────
    if (!$dryRun) {
        file_put_contents($lockFile, date('Y-m-d H:i:s'));
    }

    $logger->info('═══════════════════════════════════════════════════════');
    $logger->info('  Santoral Bot completado con éxito ✅');
    $logger->info('═══════════════════════════════════════════════════════');

} catch (\Throwable $e) {
    $logger->error('❌ ERROR FATAL: ' . $e->getMessage(), [
        'file'  => $e->getFile(),
        'line'  => $e->getLine(),
        'trace' => $e->getTraceAsString(),
    ]);
    $exitCode = 1;
}

exit($exitCode);

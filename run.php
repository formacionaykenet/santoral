#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Santoral Bot — Orquestador principal
 *
 * Se ejecuta diariamente a las 9:00 AM mediante cron:
 *   0 9 * * * www-data /usr/bin/php /home/user/santoral/run.php >> /home/user/santoral/storage/logs/cron.log 2>&1
 *
 * Permite sobrescribir la fecha para pruebas:
 *   php run.php --date=04-03
 */

require_once __DIR__ . '/config/config.php';

use Santoral\GeminiService;
use Santoral\ImageService;
use Santoral\InstagramService;
use Santoral\Logger;
use Santoral\SantoralService;
use Santoral\TikTokService;
use Santoral\TtsService;
use Santoral\VideoService;

// ── Argumento opcional --date=MM-DD para pruebas ──────────────────────────────
$overrideDate = null;
foreach ($argv ?? [] as $arg) {
    if (str_starts_with($arg, '--date=')) {
        $overrideDate = substr($arg, 7);
    }
}

// ── Inicializar logger ────────────────────────────────────────────────────────
$logger = new Logger();
$logger->info('═══════════════════════════════════════════════');
$logger->info('  Santoral Bot iniciado');
$logger->info('  Fecha: ' . ($overrideDate ? "manual ({$overrideDate})" : date('Y-m-d H:i:s')));
$logger->info('═══════════════════════════════════════════════');

$exitCode = 0;

try {
    // ── PASO 1: Obtener el santo del día ─────────────────────────────────────
    $santoral   = new SantoralService();
    $saints     = $santoral->getToday($overrideDate);
    $saintName  = $saints[0]; // santo principal
    $allSaints  = implode(' y ', $saints);
    $dateLabel  = strftime('%d de %B', mktime(0, 0, 0, (int)date('m'), (int)date('d'), (int)date('Y')));

    // Fallback para sistemas sin strftime localizado
    if (empty($dateLabel) || $dateLabel === false) {
        $months = [
            1  => 'enero', 2  => 'febrero', 3  => 'marzo',    4  => 'abril',
            5  => 'mayo',  6  => 'junio',   7  => 'julio',    8  => 'agosto',
            9  => 'septiembre', 10 => 'octubre', 11 => 'noviembre', 12 => 'diciembre',
        ];
        $dateLabel = date('j') . ' de ' . $months[(int)date('m')];
    }

    $dateStr = $dateLabel . ' de ' . date('Y');
    $logger->info("Santo del día: {$allSaints}");

    // ── PASO 2: Generar contenido con Gemini ─────────────────────────────────
    $gemini  = new GeminiService($logger);
    $content = $gemini->generateAll($saintName, $dateStr);
    $logger->info('Contenido generado por Gemini OK');

    // ── PASO 3: Generar imagen DALL-E 3 ──────────────────────────────────────
    $imageService   = new ImageService($logger);
    $dalleImagePath = $imageService->generateDallePortrait($saintName);
    $logger->info("Imagen DALL-E generada: {$dalleImagePath}");

    // ── PASO 4: Componer imagen de Instagram (1080x1080) ──────────────────────
    $igImagePath = $imageService->composeInstagramImage(
        $dalleImagePath,
        $saintName,
        $dateLabel,
        $content['biography']
    );
    $logger->info("Imagen Instagram compuesta: {$igImagePath}");

    // ── PASO 5: Componer slides TikTok (1080x1920 × 4) ───────────────────────
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

    // ── PASO 6: Sintetizar narración TTS ─────────────────────────────────────
    $tts     = new TtsService($logger);
    $mp3Path = $tts->synthesize($content['tiktok_script'], $saintName);
    $logger->info("Audio TTS generado: {$mp3Path}");

    // ── PASO 7: Ensamblar vídeo con FFmpeg ────────────────────────────────────
    $video     = new VideoService($logger);
    $videoPath = $video->assemble($tikTokSlides, $mp3Path, $saintName);
    $logger->info("Vídeo TikTok generado: {$videoPath}");

    // ── PASO 8: Publicar en Instagram ────────────────────────────────────────
    $instagram = new InstagramService($logger);
    $igPostId  = $instagram->post($igImagePath, $content['ig_caption']);
    $logger->info("✅ Instagram publicado. Post ID: {$igPostId}");

    // ── PASO 9: Publicar en TikTok ───────────────────────────────────────────
    $tiktok   = new TikTokService($logger);
    $ttPostId = $tiktok->post($videoPath, $saintName, $content['tiktok_cta']);
    $logger->info("✅ TikTok publicado. Publish ID: {$ttPostId}");

    // ── PASO 10: Limpiar archivos antiguos (>7 días) ──────────────────────────
    $santoral->cleanupStorage(7);
    $logger->info('Storage limpiado (archivos >7 días eliminados)');

} catch (\Throwable $e) {
    $logger->error('❌ ERROR FATAL: ' . $e->getMessage(), [
        'file'  => $e->getFile(),
        'line'  => $e->getLine(),
        'trace' => $e->getTraceAsString(),
    ]);
    $exitCode = 1;
}

$logger->info('═══════════════════════════════════════════════');
$logger->info('  Santoral Bot finalizado' . ($exitCode === 0 ? ' con éxito ✅' : ' con ERRORES ❌'));
$logger->info('═══════════════════════════════════════════════');

exit($exitCode);

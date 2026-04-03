<?php

declare(strict_types=1);

use Dotenv\Dotenv;

require_once dirname(__DIR__) . '/vendor/autoload.php';

// ── Cargar variables de entorno ───────────────────────────────────────────────
$dotenv = Dotenv::createImmutable(dirname(__DIR__));
$dotenv->load();

$dotenv->required([
    'GEMINI_API_KEY',
    'OPENAI_API_KEY',
    'GOOGLE_TTS_API_KEY',
    'META_ACCESS_TOKEN',
    'META_INSTAGRAM_USER_ID',
    'TIKTOK_ACCESS_TOKEN',
    'TIKTOK_OPEN_ID',
    'PUBLIC_BASE_URL',
    'PUBLIC_MEDIA_PATH',
]);

// ── Zona horaria ──────────────────────────────────────────────────────────────
date_default_timezone_set($_ENV['TIMEZONE'] ?? 'Europe/Madrid');

// ── Gemini 1.5 Pro ────────────────────────────────────────────────────────────
define('GEMINI_API_KEY',     $_ENV['GEMINI_API_KEY']);
define('GEMINI_MODEL',       'gemini-1.5-pro');
define('GEMINI_API_BASE',    'https://generativelanguage.googleapis.com/v1beta/models');

// ── OpenAI DALL-E 3 ───────────────────────────────────────────────────────────
define('OPENAI_API_KEY',     $_ENV['OPENAI_API_KEY']);
define('OPENAI_API_BASE',    'https://api.openai.com/v1');

// ── Google Cloud TTS (opcional, no se usa en hosting compartido sin vídeo) ────
define('GOOGLE_TTS_API_KEY',  $_ENV['GOOGLE_TTS_API_KEY']);
define('GOOGLE_TTS_API_BASE', 'https://texttospeech.googleapis.com/v1');
define('GOOGLE_TTS_VOICE',    $_ENV['GOOGLE_TTS_VOICE'] ?? 'es-ES-Wavenet-B');
define('GOOGLE_TTS_LANGUAGE', $_ENV['GOOGLE_TTS_LANGUAGE'] ?? 'es-ES');

// ── Meta / Instagram Graph API ────────────────────────────────────────────────
define('META_ACCESS_TOKEN',   $_ENV['META_ACCESS_TOKEN']);
define('META_IG_USER_ID',     $_ENV['META_INSTAGRAM_USER_ID']);
define('META_GRAPH_API_BASE', 'https://graph.facebook.com/v20.0');

// ── TikTok Content Posting API ────────────────────────────────────────────────
define('TIKTOK_ACCESS_TOKEN', $_ENV['TIKTOK_ACCESS_TOKEN']);
define('TIKTOK_OPEN_ID',      $_ENV['TIKTOK_OPEN_ID']);
define('TIKTOK_API_BASE',     'https://open.tiktokapis.com');

// ── Rutas de almacenamiento ───────────────────────────────────────────────────
//
// STORAGE_PATH    → directorio privado del proyecto (imágenes generadas, logs)
//                   Ejemplo Hostinger: /home/uXXXXXXX/santoral/storage
//
// PUBLIC_MEDIA_PATH → directorio dentro de public_html para servir imágenes vía HTTPS
//                   Ejemplo Hostinger: /home/uXXXXXXX/domains/tudominio.com/public_html/media
//
// PUBLIC_BASE_URL → URL pública de PUBLIC_MEDIA_PATH
//                   Ejemplo: https://tudominio.com/media
//
define('STORAGE_PATH',      rtrim($_ENV['STORAGE_BASE_PATH'] ?? dirname(__DIR__) . '/storage', '/'));
define('PUBLIC_MEDIA_PATH', rtrim($_ENV['PUBLIC_MEDIA_PATH'], '/'));
define('PUBLIC_BASE_URL',   rtrim($_ENV['PUBLIC_BASE_URL'], '/'));

// ── Assets ────────────────────────────────────────────────────────────────────
$_assetsPath = rtrim($_ENV['ASSETS_BASE_PATH'] ?? dirname(__DIR__) . '/assets', '/');
define('ASSETS_PATH', $_assetsPath);

// ── Fuentes tipográficas ──────────────────────────────────────────────────────
$_cinzelPath = ASSETS_PATH . '/fonts/Cinzel-Bold.ttf';
define('FONT_CINZEL',  $_cinzelPath);
define('FONT_BOLD',    file_exists($_cinzelPath)
    ? $_cinzelPath
    : '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf');
define('FONT_REGULAR', file_exists('/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf')
    ? '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf'
    : FONT_BOLD);

// ── FFmpeg (solo VPS — no disponible en hosting compartido) ───────────────────
define('FFMPEG_BIN', $_ENV['FFMPEG_BIN'] ?? '/usr/bin/ffmpeg');

// ── Configuración social ──────────────────────────────────────────────────────
define('SOCIAL_HANDLE', $_ENV['SOCIAL_HANDLE'] ?? '@tusantoral');

// ── App ───────────────────────────────────────────────────────────────────────
define('APP_ENV',   $_ENV['APP_ENV']   ?? 'production');
define('LOG_LEVEL', $_ENV['LOG_LEVEL'] ?? 'info');

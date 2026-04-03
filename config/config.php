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

// ── Google Cloud TTS ──────────────────────────────────────────────────────────
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

// ── Rutas ─────────────────────────────────────────────────────────────────────
define('STORAGE_PATH',    rtrim($_ENV['STORAGE_BASE_PATH'] ?? dirname(__DIR__) . '/storage', '/'));
define('ASSETS_PATH',     rtrim($_ENV['ASSETS_BASE_PATH']  ?? dirname(__DIR__) . '/assets', '/'));
define('PUBLIC_BASE_URL', rtrim($_ENV['PUBLIC_BASE_URL'], '/'));
define('FFMPEG_BIN',      $_ENV['FFMPEG_BIN'] ?? '/usr/bin/ffmpeg');

// ── Fuentes tipográficas ──────────────────────────────────────────────────────
// Cinzel-Bold: fuente elegante para nombres de santos (descargar de Google Fonts)
define('FONT_CINZEL',  ASSETS_PATH . '/fonts/Cinzel-Bold.ttf');
// Fallback si Cinzel no está instalada
define('FONT_BOLD',    file_exists(FONT_CINZEL)
    ? FONT_CINZEL
    : '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf');
define('FONT_REGULAR', '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf');

// ── Configuración social ──────────────────────────────────────────────────────
define('SOCIAL_HANDLE', $_ENV['SOCIAL_HANDLE'] ?? '@tusantoral');

// ── App ───────────────────────────────────────────────────────────────────────
define('APP_ENV',   $_ENV['APP_ENV']   ?? 'production');
define('LOG_LEVEL', $_ENV['LOG_LEVEL'] ?? 'info');

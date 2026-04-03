<?php

declare(strict_types=1);

namespace Santoral;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;

class TtsService
{
    private Client $http;
    private Logger $logger;
    private string $audioDir;

    public function __construct(Logger $logger)
    {
        $this->logger   = $logger;
        $this->audioDir = STORAGE_PATH . '/audio';

        if (!is_dir($this->audioDir)) {
            mkdir($this->audioDir, 0755, true);
        }

        $this->http = new Client(['timeout' => 60]);
    }

    /**
     * Convierte el script de TikTok a audio MP3 mediante Google Cloud TTS.
     * Devuelve la ruta local del archivo MP3 generado.
     */
    public function synthesize(string $script, string $saintName): string
    {
        $this->logger->info("Sintetizando voz TTS para: {$saintName}");

        $url = GOOGLE_TTS_API_BASE . '/text:synthesize?key=' . GOOGLE_TTS_API_KEY;

        try {
            $response = $this->http->post($url, [
                'json' => [
                    'input' => [
                        'text' => $script,
                    ],
                    'voice' => [
                        'languageCode' => GOOGLE_TTS_LANGUAGE,
                        'name'         => GOOGLE_TTS_VOICE,
                        'ssmlGender'   => 'MALE',
                    ],
                    'audioConfig' => [
                        'audioEncoding'   => 'MP3',
                        'speakingRate'    => 1.0,
                        'pitch'           => 0.0,
                        'volumeGainDb'    => 0.0,
                        'effectsProfileId' => ['headphone-class-device'],
                    ],
                ],
            ]);

            $body        = json_decode((string) $response->getBody(), true);
            $audioBase64 = $body['audioContent'] ?? null;

            if (!$audioBase64) {
                throw new \RuntimeException('Google TTS no devolvió audio');
            }

            $mp3Path = $this->audioDir . '/narration_' . $this->slug($saintName) . '_' . date('Ymd') . '.mp3';
            file_put_contents($mp3Path, base64_decode($audioBase64));

            $this->logger->debug("Audio TTS guardado: {$mp3Path}");
            return $mp3Path;

        } catch (RequestException $e) {
            throw new \RuntimeException('Error en Google TTS: ' . $e->getMessage());
        }
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

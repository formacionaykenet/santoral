<?php

declare(strict_types=1);

namespace Santoral;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;

class TikTokService
{
    private Client $http;
    private Logger $logger;

    // Tamaño de cada chunk de upload (10 MB)
    private const CHUNK_SIZE = 10 * 1024 * 1024;

    // Polling para estado de publicación
    private const STATUS_POLL_INTERVAL = 10;
    private const STATUS_MAX_POLLS     = 18; // 180 segundos

    public function __construct(Logger $logger)
    {
        $this->logger = $logger;
        $this->http   = new Client(['timeout' => 120]);
    }

    /**
     * Publica el vídeo en TikTok mediante Direct Post (Content Posting API v2).
     *
     * IMPORTANTE: esta API requiere aprobación previa de TikTok.
     * Mientras se aprueba, cambia $privacyLevel a 'SELF_ONLY' para pruebas.
     *
     * @return string publish_id del vídeo publicado
     */
    public function post(string $videoPath, string $saintName, string $description): string
    {
        $this->logger->info('Publicando en TikTok...');

        $fileSize = filesize($videoPath);

        if ($fileSize === false || $fileSize === 0) {
            throw new \RuntimeException("El archivo de vídeo no existe o está vacío: {$videoPath}");
        }

        // 1. Inicializar el upload
        ['upload_url' => $uploadUrl, 'publish_id' => $publishId] = $this->initUpload($fileSize, $saintName, $description);

        // 2. Subir el vídeo en chunks
        $this->uploadChunked($videoPath, $uploadUrl, $fileSize);

        // 3. Esperar confirmación de publicación
        $this->waitForPublish($publishId);

        $this->logger->info("TikTok publicado OK. Publish ID: {$publishId}");
        return $publishId;
    }

    /**
     * Paso 1: Inicializa el proceso de upload y obtiene la URL de subida.
     */
    private function initUpload(int $fileSize, string $saintName, string $description): array
    {
        $chunkCount = (int) ceil($fileSize / self::CHUNK_SIZE);
        $title      = "🙏 {$saintName} — Santo del Día ✝️";

        // Truncar título a 150 chars (límite TikTok)
        if (mb_strlen($title) > 150) {
            $title = mb_substr($title, 0, 147) . '...';
        }

        try {
            $response = $this->http->post(
                TIKTOK_API_BASE . '/v2/post/publish/video/init/',
                [
                    'headers' => [
                        'Authorization' => 'Bearer ' . TIKTOK_ACCESS_TOKEN,
                        'Content-Type'  => 'application/json; charset=UTF-8',
                    ],
                    'json' => [
                        'post_info' => [
                            'title'         => $title,
                            'description'   => mb_substr($description, 0, 2200),
                            'privacy_level' => APP_ENV === 'production' ? 'PUBLIC_TO_EVERYONE' : 'SELF_ONLY',
                            'disable_duet'  => false,
                            'disable_stitch' => false,
                            'disable_comment' => false,
                        ],
                        'source_info' => [
                            'source'      => 'FILE_UPLOAD',
                            'video_size'  => $fileSize,
                            'chunk_size'  => self::CHUNK_SIZE,
                            'total_chunk_count' => $chunkCount,
                        ],
                    ],
                ]
            );

            $body = json_decode((string) $response->getBody(), true);

            if (!isset($body['data']['upload_url'], $body['data']['publish_id'])) {
                throw new \RuntimeException(
                    'TikTok init falló. Respuesta: ' . json_encode($body)
                );
            }

            return [
                'upload_url' => $body['data']['upload_url'],
                'publish_id' => $body['data']['publish_id'],
            ];

        } catch (RequestException $e) {
            $responseBody = $e->hasResponse()
                ? (string) $e->getResponse()->getBody()
                : $e->getMessage();
            throw new \RuntimeException("Error inicializando upload TikTok: {$responseBody}");
        }
    }

    /**
     * Paso 2: sube el vídeo en chunks al upload_url devuelto por TikTok.
     */
    private function uploadChunked(string $videoPath, string $uploadUrl, int $fileSize): void
    {
        $handle     = fopen($videoPath, 'rb');
        $chunkIndex = 0;
        $offset     = 0;

        if (!$handle) {
            throw new \RuntimeException("No se puede abrir el vídeo: {$videoPath}");
        }

        try {
            while (!feof($handle)) {
                $chunk     = fread($handle, self::CHUNK_SIZE);
                $chunkSize = strlen($chunk);
                $end       = $offset + $chunkSize - 1;

                $this->logger->debug("TikTok upload chunk {$chunkIndex}: bytes {$offset}-{$end}/{$fileSize}");

                $this->http->put($uploadUrl, [
                    'headers' => [
                        'Content-Type'   => 'video/mp4',
                        'Content-Range'  => "bytes {$offset}-{$end}/{$fileSize}",
                        'Content-Length' => (string) $chunkSize,
                    ],
                    'body' => $chunk,
                ]);

                $offset += $chunkSize;
                $chunkIndex++;
            }
        } finally {
            fclose($handle);
        }

        $this->logger->debug("TikTok upload completado: {$chunkIndex} chunks");
    }

    /**
     * Paso 3: espera a que TikTok procese y publique el vídeo.
     */
    private function waitForPublish(string $publishId): void
    {
        for ($i = 0; $i < self::STATUS_MAX_POLLS; $i++) {
            sleep(self::STATUS_POLL_INTERVAL);

            try {
                $response = $this->http->post(
                    TIKTOK_API_BASE . '/v2/post/publish/status/fetch/',
                    [
                        'headers' => [
                            'Authorization' => 'Bearer ' . TIKTOK_ACCESS_TOKEN,
                            'Content-Type'  => 'application/json; charset=UTF-8',
                        ],
                        'json' => ['publish_id' => $publishId],
                    ]
                );

                $body   = json_decode((string) $response->getBody(), true);
                $status = $body['data']['status'] ?? 'UNKNOWN';

                $this->logger->debug("TikTok publish status: {$status}");

                if ($status === 'PUBLISH_COMPLETE') {
                    return;
                }

                if (in_array($status, ['FAILED', 'CANCELLED'], true)) {
                    throw new \RuntimeException("TikTok publicación falló con estado: {$status}. " . json_encode($body));
                }

            } catch (RequestException $e) {
                $this->logger->warning('Error consultando estado TikTok: ' . $e->getMessage());
            }
        }

        throw new \RuntimeException('TikTok no confirmó la publicación tras ' . (self::STATUS_POLL_INTERVAL * self::STATUS_MAX_POLLS) . 's');
    }
}

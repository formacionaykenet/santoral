<?php

declare(strict_types=1);

namespace Santoral;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;

/**
 * TikTok Content Posting API v2 — Photo Carousel
 *
 * Usa Photo Carousel (PULL_FROM_URL) en lugar de vídeo, lo que permite
 * funcionar en hosting compartido sin FFmpeg.
 *
 * Las imágenes deben estar accesibles via HTTPS cuando se llama a esta clase.
 *
 * NOTA: Cuando tengas VPS puedes cambiar a postVideo() que sí usa el MP4.
 */
class TikTokService
{
    private Client $http;
    private Logger $logger;

    // Polling para estado de publicación
    private const STATUS_POLL_INTERVAL = 8;
    private const STATUS_MAX_POLLS     = 20; // 160 segundos máximo

    public function __construct(Logger $logger)
    {
        $this->logger = $logger;
        $this->http   = new Client(['timeout' => 60]);
    }

    /**
     * Publica un carrusel de fotos en TikTok.
     *
     * @param array  $imageUrls   URLs públicas HTTPS de las imágenes (máx 35)
     * @param string $saintName   Nombre del santo (para el título)
     * @param string $description Texto de la publicación (máx 2200 chars)
     * @return string publish_id
     */
    public function postPhotoCarousel(array $imageUrls, string $saintName, string $description): string
    {
        $this->logger->info('Publicando carrusel de fotos en TikTok...');

        if (empty($imageUrls)) {
            throw new \InvalidArgumentException('Se necesita al menos una imagen para el carrusel');
        }

        $title = '🙏 ' . $saintName . ' — Santo del Día ✝️';
        if (mb_strlen($title) > 150) {
            $title = mb_substr($title, 0, 147) . '...';
        }

        try {
            $response = $this->http->post(
                TIKTOK_API_BASE . '/v2/post/publish/content/init/',
                [
                    'headers' => [
                        'Authorization' => 'Bearer ' . TIKTOK_ACCESS_TOKEN,
                        'Content-Type'  => 'application/json; charset=UTF-8',
                    ],
                    'json' => [
                        'post_info' => [
                            'title'           => $title,
                            'description'     => mb_substr($description, 0, 2200),
                            'privacy_level'   => APP_ENV === 'production' ? 'PUBLIC_TO_EVERYONE' : 'SELF_ONLY',
                            'disable_duet'    => false,
                            'disable_stitch'  => false,
                            'disable_comment' => false,
                            'photo_cover_index' => 0,
                        ],
                        'source_info' => [
                            'source'            => 'PULL_FROM_URL',
                            'media_type'        => 'PHOTO',
                            'photo_images'      => array_values($imageUrls),
                            'photo_cover_index' => 0,
                        ],
                    ],
                ]
            );

            $body = json_decode((string) $response->getBody(), true);

            if (!isset($body['data']['publish_id'])) {
                throw new \RuntimeException(
                    'TikTok no devolvió publish_id. Respuesta: ' . json_encode($body)
                );
            }

            $publishId = $body['data']['publish_id'];
            $this->logger->debug("TikTok photo carousel iniciado: {$publishId}");

            // Esperar confirmación de procesamiento
            $this->waitForPublish($publishId);

            $this->logger->info("TikTok Photo Carousel publicado OK. Publish ID: {$publishId}");
            return $publishId;

        } catch (RequestException $e) {
            $responseBody = $e->hasResponse()
                ? (string) $e->getResponse()->getBody()
                : $e->getMessage();
            throw new \RuntimeException("Error publicando carrusel en TikTok: {$responseBody}");
        }
    }

    /**
     * Espera a que TikTok procese y confirme la publicación.
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

                if (in_array($status, ['FAILED', 'PUBLISH_FAILED', 'CANCELLED'], true)) {
                    throw new \RuntimeException(
                        "TikTok publicación falló con estado '{$status}': " . json_encode($body)
                    );
                }

                // Estados de espera: PROCESSING_UPLOAD, PROCESSING_DOWNLOAD, SENDING_TO_USER_INBOX
                $this->logger->debug("TikTok esperando... estado: {$status}");

            } catch (RequestException $e) {
                $this->logger->warning('Error consultando estado TikTok: ' . $e->getMessage());
            }
        }

        throw new \RuntimeException(
            'TikTok no confirmó la publicación tras '
            . (self::STATUS_POLL_INTERVAL * self::STATUS_MAX_POLLS) . 's'
        );
    }
}

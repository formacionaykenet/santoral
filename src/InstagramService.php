<?php

declare(strict_types=1);

namespace Santoral;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;

class InstagramService
{
    private Client $http;
    private Logger $logger;

    // Tiempo máximo de espera para que Instagram procese el container (segundos)
    private const PUBLISH_POLL_INTERVAL = 5;
    private const PUBLISH_MAX_POLLS     = 12; // 60 segundos en total

    public function __construct(Logger $logger)
    {
        $this->logger = $logger;
        $this->http   = new Client(['timeout' => 60]);
    }

    /**
     * Publica una imagen en Instagram.
     *
     * La imagen debe estar accesible en una URL pública (servida desde tu VPS).
     * El flujo es: copiar imagen a directorio web público → crear container → publicar.
     *
     * @return string ID del post publicado
     */
    public function post(string $imagePath, string $caption): string
    {
        $this->logger->info('Publicando en Instagram...');

        // Exponer imagen en URL pública
        $publicUrl = $this->makePublicUrl($imagePath);

        // 1. Crear container de media
        $containerId = $this->createMediaContainer($publicUrl, $caption);
        $this->logger->debug("Instagram container creado: {$containerId}");

        // 2. Esperar a que el container esté listo
        $this->waitForContainer($containerId);

        // 3. Publicar
        $postId = $this->publishContainer($containerId);
        $this->logger->info("Instagram publicado OK. Post ID: {$postId}");

        return $postId;
    }

    /**
     * Copia la imagen al directorio web público del VPS y devuelve su URL.
     * Configura nginx/apache para servir PUBLIC_BASE_URL desde ese directorio.
     */
    private function makePublicUrl(string $imagePath): string
    {
        $publicDir = STORAGE_PATH . '/public';
        if (!is_dir($publicDir)) {
            mkdir($publicDir, 0755, true);
        }

        $filename  = basename($imagePath);
        $publicDst = $publicDir . '/' . $filename;
        copy($imagePath, $publicDst);

        $url = PUBLIC_BASE_URL . '/' . $filename;
        $this->logger->debug("URL pública de imagen: {$url}");

        return $url;
    }

    /**
     * Paso 1 de la Graph API: crear el media container.
     */
    private function createMediaContainer(string $imageUrl, string $caption): string
    {
        try {
            $response = $this->http->post(
                META_GRAPH_API_BASE . '/' . META_IG_USER_ID . '/media',
                [
                    'form_params' => [
                        'image_url'    => $imageUrl,
                        'caption'      => $caption,
                        'access_token' => META_ACCESS_TOKEN,
                    ],
                ]
            );

            $body = json_decode((string) $response->getBody(), true);

            if (!isset($body['id'])) {
                throw new \RuntimeException(
                    'Instagram no devolvió container ID. Respuesta: ' . json_encode($body)
                );
            }

            return (string) $body['id'];

        } catch (RequestException $e) {
            $responseBody = $e->hasResponse()
                ? (string) $e->getResponse()->getBody()
                : $e->getMessage();
            throw new \RuntimeException("Error creando container Instagram: {$responseBody}");
        }
    }

    /**
     * Espera a que el container pase al estado FINISHED.
     */
    private function waitForContainer(string $containerId): void
    {
        for ($i = 0; $i < self::PUBLISH_MAX_POLLS; $i++) {
            sleep(self::PUBLISH_POLL_INTERVAL);

            $response = $this->http->get(
                META_GRAPH_API_BASE . '/' . $containerId,
                [
                    'query' => [
                        'fields'       => 'status_code',
                        'access_token' => META_ACCESS_TOKEN,
                    ],
                ]
            );

            $body   = json_decode((string) $response->getBody(), true);
            $status = $body['status_code'] ?? 'UNKNOWN';

            $this->logger->debug("Instagram container status: {$status}");

            if ($status === 'FINISHED') {
                return;
            }

            if ($status === 'ERROR') {
                throw new \RuntimeException("Instagram container en estado ERROR: " . json_encode($body));
            }
        }

        throw new \RuntimeException('Instagram container no llegó a FINISHED tras ' . (self::PUBLISH_POLL_INTERVAL * self::PUBLISH_MAX_POLLS) . 's');
    }

    /**
     * Paso 2: publicar el container.
     */
    private function publishContainer(string $containerId): string
    {
        try {
            $response = $this->http->post(
                META_GRAPH_API_BASE . '/' . META_IG_USER_ID . '/media_publish',
                [
                    'form_params' => [
                        'creation_id'  => $containerId,
                        'access_token' => META_ACCESS_TOKEN,
                    ],
                ]
            );

            $body = json_decode((string) $response->getBody(), true);

            if (!isset($body['id'])) {
                throw new \RuntimeException(
                    'Instagram no devolvió post ID. Respuesta: ' . json_encode($body)
                );
            }

            return (string) $body['id'];

        } catch (RequestException $e) {
            $responseBody = $e->hasResponse()
                ? (string) $e->getResponse()->getBody()
                : $e->getMessage();
            throw new \RuntimeException("Error publicando en Instagram: {$responseBody}");
        }
    }
}

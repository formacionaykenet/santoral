<?php

declare(strict_types=1);

namespace Santoral;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;

class GeminiService
{
    private Client $http;
    private Logger $logger;
    private string $endpoint;

    private const MAX_RETRIES = 3;
    private const RETRY_BASE  = 2; // segundos

    public function __construct(Logger $logger)
    {
        $this->logger   = $logger;
        $this->http     = new Client(['timeout' => 90]);
        $this->endpoint = GEMINI_API_BASE . '/' . GEMINI_MODEL
            . ':generateContent?key=' . GEMINI_API_KEY;
    }

    /**
     * Genera todo el contenido necesario en una sola llamada a la API.
     *
     * @return array{
     *   biography: string,
     *   ig_caption: string,
     *   tiktok_hook: string,
     *   tiktok_story: string,
     *   tiktok_cta: string,
     *   tiktok_script: string
     * }
     */
    public function generateAll(string $saintName, string $dateStr): array
    {
        $this->logger->info("Generando contenido con Gemini para: {$saintName}");
        $prompt = $this->buildPrompt($saintName, $dateStr);
        $raw    = $this->callGemini($prompt);
        return $this->parseResponse($raw, $saintName);
    }

    private function buildPrompt(string $saintName, string $dateStr): string
    {
        return <<<PROMPT
Eres un experto en hagiografía católica y marketing digital en español.
Hoy es {$dateStr} y el santo del día es {$saintName}.

Genera el siguiente contenido en JSON válido con exactamente estas claves:

1. "biography": Biografía inspiracional de 200 palabras exactas en español.
   Tono cálido, devoto y emotivo. Destaca virtudes, milagros y legado.
   Sin listas, solo párrafos fluidos. Apto para lectores modernos.

2. "ig_caption": Caption para Instagram. Máximo 2000 caracteres.
   Estructura: 2-3 líneas de apertura impactantes + emojis (✝️🌟🙏✨💫),
   reflexión espiritual breve, llamada a la acción ("Comenta tu intención 🙏"),
   y al final estos hashtags exactos en línea separada:
   #santoral #santodeldia #catolicos #fe #santos #iglesiacatolica
   #catholics #saintoftheday #oracion #espiritualidad #vidadesantos
   #santos365 #devociones #rezar #diocesis

3. "tiktok_hook": Los primeros 10 segundos de narración.
   Pregunta o dato sorprendente que engancha. Máximo 45 palabras.
   Ejemplo de estilo: "¿Sabías que este santo logró lo imposible siendo apenas un niño?"

4. "tiktok_story": Narración principal de 35 segundos.
   Cuenta la historia de forma vívida y cinematográfica. Máximo 110 palabras.
   Frases cortas. Ritmo natural para voz. Sin listas ni puntos.

5. "tiktok_cta": Cierre de 10 segundos.
   Invita a seguir la cuenta, dar like y comentar su santo favorito.
   Menciona {SOCIAL_HANDLE}. Máximo 40 palabras.

Responde ÚNICAMENTE con el JSON válido. Sin markdown, sin texto adicional, sin ``` fences.
PROMPT;
    }

    private function callGemini(string $prompt, int $attempt = 1): string
    {
        try {
            $response = $this->http->post($this->endpoint, [
                'json' => [
                    'contents' => [
                        ['parts' => [['text' => $prompt]]]
                    ],
                    'generationConfig' => [
                        'temperature'     => 0.85,
                        'maxOutputTokens' => 2048,
                        'topP'            => 0.95,
                    ],
                    'safetySettings' => [
                        ['category' => 'HARM_CATEGORY_HARASSMENT',        'threshold' => 'BLOCK_NONE'],
                        ['category' => 'HARM_CATEGORY_HATE_SPEECH',       'threshold' => 'BLOCK_NONE'],
                        ['category' => 'HARM_CATEGORY_SEXUALLY_EXPLICIT', 'threshold' => 'BLOCK_NONE'],
                        ['category' => 'HARM_CATEGORY_DANGEROUS_CONTENT', 'threshold' => 'BLOCK_NONE'],
                    ],
                ],
            ]);

            $body = json_decode((string) $response->getBody(), true);
            $text = $body['candidates'][0]['content']['parts'][0]['text'] ?? '';

            if (empty($text)) {
                throw new \RuntimeException('Gemini devolvió respuesta vacía');
            }

            return $text;

        } catch (RequestException $e) {
            if ($attempt < self::MAX_RETRIES) {
                $delay = self::RETRY_BASE ** $attempt;
                $this->logger->warning("Gemini error (intento {$attempt}), reintentando en {$delay}s", [
                    'error' => $e->getMessage(),
                ]);
                sleep($delay);
                return $this->callGemini($prompt, $attempt + 1);
            }
            throw new \RuntimeException(
                'Gemini falló tras ' . self::MAX_RETRIES . ' intentos: ' . $e->getMessage()
            );
        }
    }

    private function parseResponse(string $raw, string $saintName): array
    {
        // Eliminar posibles fences de markdown que Gemini añade a veces
        $json = preg_replace('/^```(?:json)?\s*\n?|\n?\s*```\s*$/m', '', trim($raw));
        $data = json_decode($json, true);

        if (json_last_error() !== JSON_ERROR_NONE || !isset($data['biography'])) {
            $this->logger->warning('JSON de Gemini inválido, usando contenido de fallback', [
                'raw_snippet' => substr($raw, 0, 200),
            ]);
            return $this->fallbackContent($saintName);
        }

        // Reemplazar placeholder del handle social
        array_walk_recursive($data, function (&$value) {
            if (is_string($value)) {
                $value = str_replace('{SOCIAL_HANDLE}', SOCIAL_HANDLE, $value);
            }
        });

        // Construir script completo de TikTok
        $data['tiktok_script'] = implode(' ', array_filter([
            $data['tiktok_hook']  ?? '',
            $data['tiktok_story'] ?? '',
            $data['tiktok_cta']   ?? '',
        ]));

        return $data;
    }

    private function fallbackContent(string $saintName): array
    {
        $handle = SOCIAL_HANDLE;
        return [
            'biography'     => "Hoy la Iglesia celebra a {$saintName}, un ejemplo luminoso de fe y entrega total a Dios. Su vida estuvo marcada por la oración, la caridad y el servicio a los más necesitados. Siguiendo el Evangelio con radicalidad, {$saintName} supo encontrar a Cristo en el rostro de los pobres y los enfermos. Sus virtudes heroicas y su amor a la Virgen María lo convirtieron en un faro de esperanza para quienes lo rodeaban. Hoy su intercesión sigue siendo poderosa ante Dios.",
            'ig_caption'    => "✝️ Hoy celebramos a {$saintName} ✝️\n\nUn ejemplo de fe viva que nos inspira cada día. 🙏\n\n¿Conocías la historia de este santo? Cuéntanos en los comentarios 👇\n\n#santoral #santodeldia #catolicos #fe #santos #iglesiacatolica #catholics #saintoftheday #oracion #espiritualidad #vidadesantos #santos365 #devociones #rezar #diocesis",
            'tiktok_hook'   => "¿Conoces la historia de {$saintName}? Este santo cambió el mundo con su fe. Quédate porque te va a sorprender.",
            'tiktok_story'  => "Hoy la Iglesia celebra a {$saintName}. Su vida fue un testimonio vivo de que con Dios todo es posible. Desde joven consagró su vida al servicio de los demás, y su ejemplo sigue iluminando nuestros días.",
            'tiktok_cta'    => "Síguenos en {$handle} para el santoral de cada día. Dale like si te inspiró y comenta el nombre de tu santo favorito. ¡Hasta mañana!",
            'tiktok_script' => "¿Conoces la historia de {$saintName}? Este santo cambió el mundo con su fe. Hoy la Iglesia celebra a {$saintName}. Su vida fue un testimonio vivo de que con Dios todo es posible. Síguenos en {$handle} para el santoral de cada día.",
        ];
    }
}

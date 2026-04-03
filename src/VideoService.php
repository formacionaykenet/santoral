<?php

declare(strict_types=1);

namespace Santoral;

class VideoService
{
    private Logger $logger;
    private string $videosDir;
    private string $ffmpeg;

    // Duración de cada slide en segundos
    private const SLIDE_DURATION = 12;
    // Duración total objetivo
    private const VIDEO_DURATION = 48;

    public function __construct(Logger $logger)
    {
        $this->logger    = $logger;
        $this->videosDir = STORAGE_PATH . '/videos';
        $this->ffmpeg    = FFMPEG_BIN;

        if (!is_dir($this->videosDir)) {
            mkdir($this->videosDir, 0755, true);
        }

        if (!file_exists($this->ffmpeg)) {
            throw new \RuntimeException(
                "FFmpeg no encontrado en: {$this->ffmpeg}. Instala con: apt install ffmpeg"
            );
        }
    }

    /**
     * Monta el vídeo TikTok a partir de 4 slides PNG + narración MP3 + música de fondo.
     * Devuelve la ruta del MP4 generado (1080x1920, H.264, AAC).
     */
    public function assemble(array $slidePaths, string $narrationMp3, string $saintName): string
    {
        $this->logger->info("Ensamblando vídeo TikTok para: {$saintName}");

        if (count($slidePaths) !== 4) {
            throw new \InvalidArgumentException('Se necesitan exactamente 4 slides PNG');
        }

        $outputPath = $this->videosDir . '/tiktok_' . $this->slug($saintName) . '_' . date('Ymd') . '.mp4';
        $musicPath  = ASSETS_PATH . '/music/gregorian_bg.mp3';
        $hasMusicBg = file_exists($musicPath);

        if ($hasMusicBg) {
            $this->assembleWithMusic($slidePaths, $narrationMp3, $musicPath, $outputPath);
        } else {
            $this->logger->warning('Música de fondo no encontrada, vídeo solo con narración');
            $this->assembleNarrationOnly($slidePaths, $narrationMp3, $outputPath);
        }

        if (!file_exists($outputPath)) {
            throw new \RuntimeException("FFmpeg no generó el vídeo: {$outputPath}");
        }

        $sizeMb = round(filesize($outputPath) / 1024 / 1024, 1);
        $this->logger->info("Vídeo generado: {$outputPath} ({$sizeMb} MB)");

        return $outputPath;
    }

    /**
     * Slideshow con Ken Burns + narración + música de fondo mezclada.
     */
    private function assembleWithMusic(array $slides, string $narration, string $music, string $output): void
    {
        $d  = self::SLIDE_DURATION;
        $td = self::VIDEO_DURATION;

        // Inputs: 4 slides + narración + música
        $inputArgs = '';
        foreach ($slides as $slide) {
            $inputArgs .= " -loop 1 -t {$d} -i " . escapeshellarg($slide);
        }
        $inputArgs .= ' -i ' . escapeshellarg($narration);
        $inputArgs .= ' -i ' . escapeshellarg($music);

        // Filter complex:
        // - zoompan para efecto Ken Burns en cada slide
        // - concat para unirlos
        // - mezcla de audio narración (vol 1.0) + música (vol 0.15)
        $filterComplex = implode(';', [
            "[0:v]zoompan=z='min(zoom+0.0012,1.4)':d={$d}:s=1080x1920:fps=25[v0]",
            "[1:v]zoompan=z='if(lte(zoom,1),1.3,max(1,zoom-0.0012))':d={$d}:s=1080x1920:fps=25[v1]",
            "[2:v]zoompan=z='min(zoom+0.0012,1.4)':d={$d}:s=1080x1920:fps=25[v2]",
            "[3:v]zoompan=z='1.3':d={$d}:s=1080x1920:fps=25[v3]",
            "[v0][v1][v2][v3]concat=n=4:v=1:a=0[vout]",
            "[4:a]volume=1.0[narr]",
            "[5:a]volume=0.15,aloop=loop=-1:size=2e+09[bgloop]",
            "[narr][bgloop]amix=inputs=2:duration=first[aout]",
        ]);

        $cmd = sprintf(
            '%s -y%s -filter_complex %s -map "[vout]" -map "[aout]" '
            . '-c:v libx264 -preset fast -crf 23 -pix_fmt yuv420p '
            . '-c:a aac -b:a 128k -ar 44100 '
            . '-t %d -r 25 %s 2>&1',
            escapeshellcmd($this->ffmpeg),
            $inputArgs,
            escapeshellarg($filterComplex),
            $td,
            escapeshellarg($output)
        );

        $this->executeFFmpeg($cmd);
    }

    /**
     * Slideshow sin música de fondo (solo narración).
     */
    private function assembleNarrationOnly(array $slides, string $narration, string $output): void
    {
        $d  = self::SLIDE_DURATION;
        $td = self::VIDEO_DURATION;

        $inputArgs = '';
        foreach ($slides as $slide) {
            $inputArgs .= " -loop 1 -t {$d} -i " . escapeshellarg($slide);
        }
        $inputArgs .= ' -i ' . escapeshellarg($narration);

        $filterComplex = implode(';', [
            "[0:v]zoompan=z='min(zoom+0.0012,1.4)':d={$d}:s=1080x1920:fps=25[v0]",
            "[1:v]zoompan=z='if(lte(zoom,1),1.3,max(1,zoom-0.0012))':d={$d}:s=1080x1920:fps=25[v1]",
            "[2:v]zoompan=z='min(zoom+0.0012,1.4)':d={$d}:s=1080x1920:fps=25[v2]",
            "[3:v]zoompan=z='1.3':d={$d}:s=1080x1920:fps=25[v3]",
            "[v0][v1][v2][v3]concat=n=4:v=1:a=0[vout]",
        ]);

        $cmd = sprintf(
            '%s -y%s -filter_complex %s -map "[vout]" -map "4:a" '
            . '-c:v libx264 -preset fast -crf 23 -pix_fmt yuv420p '
            . '-c:a aac -b:a 128k -ar 44100 '
            . '-t %d -r 25 %s 2>&1',
            escapeshellcmd($this->ffmpeg),
            $inputArgs,
            escapeshellarg($filterComplex),
            $td,
            escapeshellarg($output)
        );

        $this->executeFFmpeg($cmd);
    }

    private function executeFFmpeg(string $cmd): void
    {
        $this->logger->debug('Ejecutando FFmpeg', ['cmd' => $cmd]);
        exec($cmd, $output, $exitCode);

        if ($exitCode !== 0) {
            $errorLog = implode("\n", array_slice($output, -20));
            $this->logger->error("FFmpeg falló (código {$exitCode})", ['tail' => $errorLog]);
            throw new \RuntimeException("FFmpeg falló con código {$exitCode}:\n{$errorLog}");
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

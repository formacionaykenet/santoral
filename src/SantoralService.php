<?php

declare(strict_types=1);

namespace Santoral;

class SantoralService
{
    private array $santoral;

    public function __construct()
    {
        $this->santoral = require dirname(__DIR__) . '/data/santoral.php';
    }

    /**
     * Devuelve el array de santos para hoy (o para la fecha indicada como MM-DD).
     *
     * @throws \RuntimeException si la fecha no está en el santoral
     */
    public function getToday(?string $mmdd = null): array
    {
        $key = $mmdd ?? date('m-d');

        if (!isset($this->santoral[$key])) {
            throw new \RuntimeException("No hay santo definido para la fecha: {$key}");
        }

        return $this->santoral[$key];
    }

    /**
     * Devuelve el nombre principal del santo del día como cadena.
     */
    public function getPrimaryName(?string $mmdd = null): string
    {
        return $this->getToday($mmdd)[0];
    }

    /**
     * Elimina de los directorios de storage los archivos más antiguos que $days días.
     */
    public function cleanupStorage(int $days = 7): void
    {
        $dirs   = ['images', 'audio', 'videos'];
        $cutoff = time() - ($days * 86400);

        foreach ($dirs as $dir) {
            $path = STORAGE_PATH . '/' . $dir;
            if (!is_dir($path)) {
                continue;
            }
            foreach (glob("{$path}/*") ?: [] as $file) {
                if (is_file($file) && filemtime($file) < $cutoff) {
                    unlink($file);
                }
            }
        }
    }
}

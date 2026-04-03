<?php

declare(strict_types=1);

namespace Santoral;

use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger as MonologLogger;

class Logger
{
    private MonologLogger $monolog;

    public function __construct()
    {
        $logDir  = STORAGE_PATH . '/logs';
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }

        $logFile = $logDir . '/' . date('Y-m-d') . '.log';
        $level   = match (strtolower(LOG_LEVEL)) {
            'debug'   => Level::Debug,
            'warning' => Level::Warning,
            'error'   => Level::Error,
            default   => Level::Info,
        };

        $this->monolog = new MonologLogger('santoral');
        $this->monolog->pushHandler(new StreamHandler($logFile, $level));
        $this->monolog->pushHandler(new StreamHandler('php://stdout', $level));
    }

    public function info(string $message, array $context = []): void
    {
        $this->monolog->info($message, $context);
    }

    public function debug(string $message, array $context = []): void
    {
        $this->monolog->debug($message, $context);
    }

    public function warning(string $message, array $context = []): void
    {
        $this->monolog->warning($message, $context);
    }

    public function error(string $message, array $context = []): void
    {
        $this->monolog->error($message, $context);
    }
}

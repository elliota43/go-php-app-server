<?php

declare(strict_types=1);

namespace BareMetalPHP\Support;

class Log
{
    protected static array $channels = [];

    protected static string $defaultChannel = 'default';

    protected static string $logPath;

    public static function setLogPath(string $path): void
    {
        static::$logPath = rtrim($path, '/');
    }

    public static function emergency(string $message, array $context = []): void
    {
        static::log('emergency', $message, $context);
    }

    public static function alert(string $message, array $context = []): void
    {
        static::log('alert', $message, $context);
    }

    public static function critical(string $message, array $context = []): void
    {
        static::log('critical', $message, $context);
    }

    public static function error(string $message, array $context = []): void
    {
        static::log('error', $message, $context);
    }

    public static function warning(string $message, array $context = []): void
    {
        static::log('warning', $message, $context);
    }

    public static function notice(string $message, array $context = []): void
    {
        static::log('notice', $message, $context);
    }

    public static function info(string $message, array $context = []): void
    {
        static::log('info', $message, $context);
    }

    public static function debug(string $message, array $context = []): void
    {
        static::log('debug', $message, $context);
    }

    public static function log(string $level, string $message, array $context = []): void
    {
        if (!isset(static::$logPath)) {
            static::$logPath = dirname(__DIR__, 2) . '/storage/logs';
        }

        // Ensure log directory exists
        if (!is_dir(static::$logPath)) {
            mkdir(static::$logPath, 0755, true);
        }

        $timestamp = date('Y-m-d H:i:s');
        $contextStr = !empty($context) ? ' ' . json_encode($context) : '';
        $logMessage = "[{$timestamp}] {$level}: {$message}{$contextStr}" . PHP_EOL;

        // Write to daily log file
        $logFile = static::$logPath . '/log-' . date('Y-m-d') . '.log';
        file_put_contents($logFile, $logMessage, FILE_APPEND | LOCK_EX);

        // Also log to error_log if in debug mode
        if (getenv('APP_DEBUG') === 'true' || in_array($level, ['error', 'critical', 'alert', 'emergency'], true)) {
            error_log($logMessage);
        }
    }
}


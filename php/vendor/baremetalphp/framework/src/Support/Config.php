<?php

declare(strict_types=1);

namespace BareMetalPHP\Support;

class Config
{
    protected static array $config = [];

    protected static bool $loaded = false;

    protected static string $configPath;

    public static function setConfigPath(string $path): void
    {
        static::$configPath = rtrim($path, '/');
    }

    public static function load(): void
    {
        if (static::$loaded) {
            return;
        }

        if (!isset(static::$configPath)) {
            static::$configPath = dirname(__DIR__, 2) . '/config';
        }

        // Load all PHP files from config directory
        if (is_dir(static::$configPath)) {
            $files = glob(static::$configPath . '/*.php');
            foreach ($files as $file) {
                $key = pathinfo($file, PATHINFO_FILENAME);
                static::$config[$key] = require $file;
            }
        }

        static::$loaded = true;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        static::load();

        // Support dot notation: 'database.dsn'
        $keys = explode('.', $key);
        $value = static::$config;

        foreach ($keys as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }

        return $value;
    }

    public static function set(string $key, mixed $value): void
    {
        static::load();

        $keys = explode('.', $key);
        $config = &static::$config;

        foreach ($keys as $segment) {
            if (!isset($config[$segment]) || !is_array($config[$segment])) {
                $config[$segment] = [];
            }
            $config = &$config[$segment];
        }

        $config = $value;
    }

    public static function has(string $key): bool
    {
        static::load();

        $keys = explode('.', $key);
        $value = static::$config;

        foreach ($keys as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return false;
            }
            $value = $value[$segment];
        }

        return true;
    }

    public static function all(): array
    {
        static::load();
        return static::$config;
    }
}


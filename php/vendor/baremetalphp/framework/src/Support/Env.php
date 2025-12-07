<?php

declare(strict_types=1);

namespace BareMetalPHP\Support;

class Env
{
    protected static bool $loaded = false;

    /**
     * Load environment variables from .env file
     */
    public static function load(string $filePath, bool $force = false): void
    {
        if (!file_exists($filePath)) {
            return;
        }

        // Allow multiple loads if force is true (for .env.local overrides)
        if (static::$loaded && !$force) {
            return;
        }

        $lines = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        foreach ($lines as $line) {
            // Skip comments
            $line = trim($line);
            if (str_starts_with($line, '#')) {
                continue;
            }

            // Parse KEY=VALUE format
            if (str_contains($line, '=')) {
                [$key, $value] = explode('=', $line, 2);
                
                $key = trim($key);
                $value = trim($value);
                
                // Skip empty keys
                if (empty($key)) {
                    continue;
                }
                
                // Remove quotes if present
                if ((str_starts_with($value, '"') && str_ends_with($value, '"')) ||
                    (str_starts_with($value, "'") && str_ends_with($value, "'"))) {
                    $value = substr($value, 1, -1);
                }
                
                // Don't override existing environment variables unless force is true
                if ($force || !static::has($key)) {
                    putenv("{$key}={$value}");
                    $_ENV[$key] = $value;
                    $_SERVER[$key] = $value;
                }
            }
        }

        if (!$force) {
            static::$loaded = true;
        }
    }

    /**
     * Get environment variable with type casting
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        // Check $_ENV first (most reliable)
        if (isset($_ENV[$key])) {
            return static::castValue($_ENV[$key]);
        }
        
        // Check $_SERVER (also set by load())
        if (isset($_SERVER[$key])) {
            return static::castValue($_SERVER[$key]);
        }
        
        // Fall back to getenv()
        $value = getenv($key);
        
        if ($value === false) {
            return $default;
        }
        
        // Type casting based on value
        return static::castValue($value);
    }

    /**
     * Cast string value to appropriate type
     */
    protected static function castValue(string $value): mixed
    {
        // Boolean true
        if (in_array(strtolower($value), ['true', '1', 'yes', 'on'], true)) {
            return true;
        }
        
        // Boolean false
        if (in_array(strtolower($value), ['false', '0', 'no', 'off', ''], true)) {
            return false;
        }
        
        // Null
        if (strtolower($value) === 'null') {
            return null;
        }
        
        // Try to cast to integer if numeric and no decimal point
        if (is_numeric($value) && !str_contains($value, '.')) {
            return (int) $value;
        }
        
        // Try to cast to float if numeric with decimal point
        if (is_numeric($value) && str_contains($value, '.')) {
            return (float) $value;
        }
        
        // Array syntax: [item1,item2] or ["item1","item2"]
        if (str_starts_with($value, '[') && str_ends_with($value, ']')) {
            $content = trim($value, '[]');
            if (empty($content)) {
                return [];
            }
            
            $items = array_map('trim', explode(',', $content));
            return array_map(function ($item) {
                // Remove quotes if present
                if ((str_starts_with($item, '"') && str_ends_with($item, '"')) ||
                    (str_starts_with($item, "'") && str_ends_with($item, "'"))) {
                    return substr($item, 1, -1);
                }
                return static::castValue($item);
            }, $items);
        }
        
        return $value;
    }

    /**
     * Check if environment variable exists
     */
    public static function has(string $key): bool
    {
        return isset($_ENV[$key]) || isset($_SERVER[$key]) || getenv($key) !== false;
    }
}


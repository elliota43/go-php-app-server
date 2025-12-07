<?php

declare(strict_types=1);

namespace BareMetalPHP\View;

use BareMetalPHP\Http\Response;

class View
{
    protected static string $basePath;
    protected static string $cachePath;

    public static function setBasePath(string $path): void
    {
        static::$basePath = rtrim($path, '/');
    }

    public static function setCachePath(string $path): void
    {
        static::$cachePath = rtrim($path, '/');
    }

    public static function make(string $name, array $data = []): Response
    {
        if (!isset(static::$basePath)) {
            throw new \RuntimeException('View base path not set. Call View::setBasePath() in bootstrap.');
        }
        if (!isset(static::$cachePath)) {
            throw new \RuntimeException('View cache path not set. Call View::setCachePath() in bootstrap.');
        }

        // "users.show" -> "users/show.php"
        $relativePath = str_replace('.', '/', $name) . '.php';
        $sourcePath   = static::$basePath . '/' . $relativePath;

        if (!file_exists($sourcePath)) {
            throw new \RuntimeException("View [{$name}] not found at [{$sourcePath}].");
        }

        // ensure cache dir exists
        if (!is_dir(static::$cachePath)) {
            if (!mkdir(static::$cachePath, 0777, true) && !is_dir(static::$cachePath)) {
                throw new \RuntimeException('Unable to create view cache directory: ' . static::$cachePath);
            }
        }

        // Simple cache key based on full path + mtime
        $cacheKey   = md5($sourcePath . '|' . filemtime($sourcePath));
        $cachePath  = static::$cachePath . '/' . $cacheKey . '.php';

        // compile if missing
        if (!file_exists($cachePath)) {
            $contents  = file_get_contents($sourcePath);
            $compiled  = TemplateEngine::compile($contents);
            $wrapped   = "<?php\n// compiled view: {$sourcePath}\n?>\n" . $compiled;

            if (file_put_contents($cachePath, $wrapped) === false) {
                throw new \RuntimeException('Failed to write compiled view to ' . $cachePath);
            }
        }

        // make $data available as variables
        // Use EXTR_OVERWRITE to ensure data variables take precedence over function parameters
        extract($data, EXTR_OVERWRITE);

        ob_start();
        require $cachePath;
        $content = ob_get_clean();

        return new Response($content);
    }
}

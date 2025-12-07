<?php

declare(strict_types=1);

namespace BareMetalPHP\Frontend;

use BareMetalPHP\Http\Response;
use BareMetalPHP\Support\Config;

/**
 * Helper for Single Page Application (SPA) responses
 */
class SPAHelper
{
    protected AssetManager $assetManager;

    public function __construct(AssetManager $assetManager)
    {
        $this->assetManager = $assetManager;
    }

    /**
     * Create an SPA response with initial data
     */
    public function render(string $component, array $props = [], ?string $layout = null): Response
    {
        $html = $this->buildSPAHTML($component, $props, $layout);
        return new Response($html, 200, ['Content-Type' => 'text/html']);
    }

    /**
     * Build the HTML for SPA
     */
    protected function buildSPAHTML(string $component, array $props, ?string $layout): string
    {
        $layout = $layout ?? config('frontend.layout', 'default');
        $layoutPath = base_path('resources/views/layouts/' . $layout . '.php');
        
        if (!file_exists($layoutPath)) {
            return $this->buildDefaultLayout($component, $props);
        }

        ob_start();
        extract([
            'component' => $component,
            'props' => $props,
            'assetManager' => $this->assetManager,
        ]);
        require $layoutPath;
        return ob_get_clean();
    }

    /**
     * Build default SPA layout
     */
    protected function buildDefaultLayout(string $component, array $props): string
    {
        $entry = config('frontend.entry', 'resources/js/app.jsx');
        $propsJson = json_encode($props, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
        $componentJson = json_encode($component, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{$component}</title>
    {$this->assetManager->viteClient()}
    {$this->assetManager->styles($entry)}
</head>
<body>
    <div id="app" data-component="{$componentJson}" data-props='{$propsJson}'></div>
    {$this->assetManager->script($entry)}
</body>
</html>
HTML;
    }

    /**
     * Create JSON response for API endpoints
     */
    public function json(array $data, int $status = 200): Response
    {
        return json_response($data, $status);
    }

    /**
     * Get CSRF token for API calls
     */
    public function csrfToken(): string
    {
        // TODO: Implement CSRF token generation when session is available
        // For now, return empty string
        return '';
    }
}


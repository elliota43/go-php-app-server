<?php

declare(strict_types=1);

namespace BareMetalPHP\Console\Commands;

class InstallFrontendCommand
{
    public function handle(array $args = []): void
    {
        $framework = strtolower($args[0] ?? '');

        if (!in_array($framework, ['react', 'vue'], true)) {
            echo "Error: Framework must be 'react' or 'vue'\n";
            echo "Usage: php mini frontend:install react|vue\n";
            return;
        }

        echo "Installing {$framework} frontend integration...\n\n";

        $this->installDependencies($framework);
        $this->createFrontendFiles($framework);
        $this->createViteConfig($framework);
        $this->updatePackageJson($framework);
        $this->createLayoutFile();

        echo "\n✅ Frontend integration installed successfully!\n\n";
        echo "Next steps:\n";
        echo "1. Run: npm install\n";
        echo "2. Run: npm run dev\n";
        echo "3. Start your PHP server: php mini serve\n\n";
    }

    protected function installDependencies(string $framework): void
    {
        echo "📦 Creating package.json...\n";
        
        $packageJsonPath = base_path('package.json');
        if (file_exists($packageJsonPath)) {
            echo "   package.json already exists, skipping...\n";
            return;
        }

        $dependencies = [
            'react' => [
                'react' => '^18.2.0',
                'react-dom' => '^18.2.0',
            ],
            'vue' => [
                'vue' => '^3.3.0',
            ],
        ];

        $devDependencies = [
            'vite' => '^5.0.0',
            '@vitejs/plugin-react' => '^4.2.0',
        ];

        if ($framework === 'vue') {
            $devDependencies['@vitejs/plugin-vue'] = '^5.0.0';
            unset($devDependencies['@vitejs/plugin-react']);
        }

        $packageJson = [
            'name' => basename(base_path()),
            'type' => 'module',
            'scripts' => [
                'dev' => 'vite',
                'build' => 'vite build',
                'preview' => 'vite preview',
            ],
            'dependencies' => $dependencies[$framework] ?? [],
            'devDependencies' => $devDependencies,
        ];

        file_put_contents($packageJsonPath, json_encode($packageJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    protected function createFrontendFiles(string $framework): void
    {
        echo "📁 Creating frontend files...\n";

        $jsDir = base_path('resources/js');
        if (!is_dir($jsDir)) {
            mkdir($jsDir, 0777, true);
        }

        if ($framework === 'react') {
            $this->createReactFiles($jsDir);
        } else {
            $this->createVueFiles($jsDir);
        }
    }

    protected function createReactFiles(string $jsDir): void
    {
        // Create main app file
        $appJsx = <<<'JSX'
import React from 'react';
import { createRoot } from 'react-dom/client';
import App from './App';

const container = document.getElementById('app');
if (container) {
    const component = container.dataset.component || 'App';
    const props = container.dataset.props ? JSON.parse(container.dataset.props) : {};
    
    const root = createRoot(container);
    root.render(<App {...props} />);
}
JSX;
        file_put_contents($jsDir . '/app.jsx', $appJsx);

        // Create App component
        $appComponent = <<<'JSX'
import React from 'react';

function App(props = {}) {
    return (
        <div>
            <h1>Welcome to React + Framework</h1>
            <p>Start building your application here!</p>
        </div>
    );
}

export default App;
JSX;
        file_put_contents($jsDir . '/App.jsx', $appComponent);
    }

    protected function createVueFiles(string $jsDir): void
    {
        // Create main app file
        $appJs = <<<'JS'
import { createApp } from 'vue';
import App from './App.vue';

const container = document.getElementById('app');
if (container) {
    const component = container.dataset.component || 'App';
    const props = container.dataset.props ? JSON.parse(container.dataset.props) : {};
    
    createApp(App, props).mount(container);
}
JS;
        file_put_contents($jsDir . '/app.js', $appJs);

        // Create App component
        $appVue = <<<'VUE'
<template>
    <div>
        <h1>Welcome to Vue.js + Framework</h1>
        <p>Start building your application here!</p>
    </div>
</template>

<script>
export default {
    name: 'App',
};
</script>
VUE;
        file_put_contents($jsDir . '/App.vue', $appVue);
    }

    protected function createViteConfig(string $framework): void
    {
        echo "⚙️  Creating Vite configuration...\n";

        $viteConfigPath = base_path('vite.config.js');
        if (file_exists($viteConfigPath)) {
            echo "   vite.config.js already exists, skipping...\n";
            return;
        }

        if ($framework === 'react') {
            $plugin = '@vitejs/plugin-react';
            $pluginVar = 'react';
            $entry = 'resources/js/app.jsx';
            $pluginCall = 'react()';
        } else {
            $plugin = '@vitejs/plugin-vue';
            $pluginVar = 'vue';
            $entry = 'resources/js/app.js';
            $pluginCall = 'vue()';
        }

        $viteConfig = <<<JS
import { defineConfig } from 'vite';
import {$pluginVar} from '{$plugin}';

export default defineConfig({
    plugins: [{$pluginVar}({$pluginCall})],
    build: {
        outDir: 'public/build',
        manifest: true,
        rollupOptions: {
            input: '{$entry}',
        },
    },
    server: {
        host: '0.0.0.0',
        port: 5173,
        strictPort: true,
        hmr: {
            host: 'localhost',
        },
    },
});
JS;
        file_put_contents($viteConfigPath, $viteConfig);
    }

    protected function updatePackageJson(string $framework): void
    {
        // Package.json is created in installDependencies
        // This is just a placeholder for future enhancements
    }

    protected function createLayoutFile(): void
    {
        echo "📄 Creating default layout...\n";

        $layoutsDir = base_path('resources/views/layouts');
        if (!is_dir($layoutsDir)) {
            mkdir($layoutsDir, 0777, true);
        }

        $layoutPath = $layoutsDir . '/app.php';
        if (file_exists($layoutPath)) {
            echo "   Layout already exists, skipping...\n";
            return;
        }

        $layout = <<<'PHP'
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($component ?? 'App') ?></title>
    <?= $assetManager->viteClient() ?>
    <?= $assetManager->styles(config('frontend.entry', 'resources/js/app.jsx')) ?>
</head>
<body>
    <div id="app" 
         data-component="<?= htmlspecialchars(json_encode($component ?? 'App', JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP)) ?>"
         data-props='<?= json_encode($props ?? [], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>'>
    </div>
    <?= $assetManager->script(config('frontend.entry', 'resources/js/app.jsx')) ?>
</body>
</html>
PHP;
        file_put_contents($layoutPath, $layout);
    }
}


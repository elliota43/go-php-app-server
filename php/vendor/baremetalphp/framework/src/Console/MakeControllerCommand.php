<?php

namespace BareMetalPHP\Console;

class MakeControllerCommand
{
    public function handle(array $args = []): void
    {
        $name = $args[0] ?? null;

        if (!$name) {
            echo "Usage: php mini make:controller ControllerName\n";
            return;
        }

        $path = "app/Http/Controllers/{$name}.php";

        if (file_exists($path)) {
            echo "Controller already exists: {$path}\n";
            return;
        }

        $template = <<<PHP
<?php

namespace App\Http\Controllers;

use BareMetalPHP\Http\Request;
use BareMetalPHP\Http\Response;

class {$name}
{
    public function index(Request \$request): Response
    {
        return new Response("{$name} controller");
    }
}
PHP;

        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }

        file_put_contents($path, $template);

        echo "Controller created: {$path}\n";
    }
}

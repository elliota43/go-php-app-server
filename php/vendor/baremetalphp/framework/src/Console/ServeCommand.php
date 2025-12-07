<?php

namespace BareMetalPHP\Console;

class ServeCommand
{
    public function handle(array $args = []): void
    {
        $host = '127.0.0.1';
        $port = 9010;

        // Find project root - the command is run from project root where mini script is located
        $projectRoot = getcwd();
        
        // Verify we're in the right place by checking for vendor/autoload.php or composer.json
        if (!file_exists($projectRoot . '/vendor/autoload.php') && !file_exists($projectRoot . '/composer.json')) {
            // Fallback: try to find project root by going up from framework location
            // Go up from Console/ (__DIR__) -> framework/src/ -> framework/ -> vendor/ -> project root
            $possibleRoot = dirname(__DIR__, 4);
            if (file_exists($possibleRoot . '/vendor/autoload.php') || file_exists($possibleRoot . '/composer.json')) {
                $projectRoot = $possibleRoot;
            } else {
                throw new \RuntimeException('Could not locate project root. Make sure you are running this command from the project root.');
            }
        }
        
        $docRoot = realpath($projectRoot . '/public');
        
        if (!$docRoot || !is_dir($docRoot)) {
            throw new \RuntimeException("Public directory not found at: {$projectRoot}/public");
        }
        
        echo "Mini framework development server started:\n";
        echo "http://$host:$port\n";

        // equivalent to laravel's internal behavior
        $command = sprintf(
            'php -S %s:%d -t %s',
            $host,
            $port,
            $docRoot
        );
        

        passthru($command);
    }
}
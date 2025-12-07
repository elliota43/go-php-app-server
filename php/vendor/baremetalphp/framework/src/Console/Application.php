<?php
namespace BareMetalPHP\Console;

use BareMetalPHP\Application as Container;

/**
 * Console application responsible for locating and executing commands.
 * 
 * This implementation resolves command classes through the Container,
 * enabling full dependency injection via the constructor. 
 */
class Application
{


    /**
     * The Server Container instance.
     * 
     * @var Container
     */
    protected Container $app;
    
    /**
     * Map of command names to command class strings.
     * 
     * @var array<string, class-string>
     */
    protected array $commands = [];

    /**
     * Create a new console application instance.
     * 
     * @param Container $app
     */
    public function __construct(Container $app)
    {

        $this->app = $app;
        // register all core command mappings
        $this->commands = [
            'serve' => ServeCommand::class,
            'make:controller' => MakeControllerCommand::class,
            'migrate' => MigrateCommand::class,
            'migrate:rollback' => MigrateRollbackCommand::class,
            'make:migration' => MakeMigrationCommand::class,
            'frontend:install' => Commands\InstallFrontendCommand::class,
            'app:install-go-server' => Commands\InstallGoAppServerCommand::class,
        ];

    }

    /**
     * Run the console application using the provided CLI arguments.
     * 
     * @param array $argv
     * @return void
     */
    public function run(array $argv): void
    {
        $name = $argv[1] ?? null;

        if (!$name || !isset($this->commands[$name])) {
            if ($name !== null) {
                echo "Command `{$name}` not found.\n\n";
            }
            
            $this->displayAvailableCommands();
            return;
        }

        $class = $this->commands[$name];

        $command = $this->app->make($class);

        $args = array_slice($argv, 2);

        // Support both handle($args) and handle() + setArguments($args)
        if (method_exists($command, 'setArguments')) {
            $command->setArguments($args);
            $command->handle();
        } else {
            $command->handle($args);
        }
}

    /**
     * Display a list of all available command names.
     * 
     * @return void
     */
    public function displayAvailableCommands(): void
    {
        echo "Available commands:\n";
        foreach (array_keys($this->commands) as $name)  {
            echo " $name\n";
        }
    }
}
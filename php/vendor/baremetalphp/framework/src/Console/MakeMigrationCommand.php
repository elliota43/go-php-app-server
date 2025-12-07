<?php

namespace BareMetalPHP\Console;

class MakeMigrationCommand
{
    public function handle(array $args = []): void
    {
        $name = $args[0] ?? null;

        if (!$name) {
            echo "Usage: php mini make:migration MigrationName\n";
            echo "Example: php mini make:migration create_users_table\n";
            return;
        }

        // ensure migrations directory exists
        $migrationsDir = getcwd() . '/database/migrations';
        if (!is_dir($migrationsDir)) {
            mkdir($migrationsDir, 0777, true);
        }

        // timestamped filename like 20241204194500_create_users_table.php
        $timestamp = date('YmdHis');
        $fileName = $timestamp . '_'.$name.'.php';
        $path = $migrationsDir. '/' .$fileName;

        if (file_exists($path)) {
            echo "Migration already exists: {$path}\n";
            return;
        }

        $template = <<<PHP
<?php

use BareMetalPHP\Database\Migration;
use BareMetalPHP\Database\Connection;

return new class extends Migration
{
    public function up(Connection \$connection): void
    {
        // Example 1: Create a table using the schema builder
        // \$this->createTable(\$connection, 'table_name', function (\$table) {
        //     \$table->id();                                    // Auto-incrementing primary key
        //     \$table->string('name');                          // VARCHAR(255)
        //     \$table->string('email')->unique();               // Unique constraint
        //     \$table->text('description')->nullable();         // Nullable TEXT
        //     \$table->integer('age')->nullable();              // Nullable INTEGER
        //     \$table->boolean('is_active')->default(false);    // Boolean with default
        //     \$table->decimal('price', 10, 2);                 // DECIMAL(10, 2)
        //     \$table->timestamp('created_at')->nullable();     // TIMESTAMP
        //     \$table->timestamps();                            // created_at and updated_at
        //     
        //     // Indexes
        //     \$table->index('email');
        //     \$table->unique(['email', 'username']);
        //     
        //     // Foreign keys
        //     \$table->integer('user_id');
        //     \$table->foreign('user_id')
        //           ->references('users', 'id')
        //           ->onDelete('cascade');
        // });

        // Example 2: Using the Schema helper (alternative syntax)
        // \$this->schema(\$connection, function (\$schema) {
        //     \$schema->create('posts', function (\$table) {
        //         \$table->id();
        //         \$table->integer('user_id');
        //         \$table->foreign('user_id')
        //               ->references('users', 'id')
        //               ->cascade();
        //         \$table->string('title');
        //         \$table->text('body');
        //         \$table->timestamps();
        //     });
        // });

        // Example 3: Modify an existing table
        // \$this->table(\$connection, 'users', function (\$table) {
        //     \$table->string('phone')->nullable();
        //     \$table->dropColumn('old_column');
        //     \$table->renameColumn('old_name', 'new_name');
        //     \$table->index('phone');
        // });

        // Example 4: Drop a table
        // \$this->dropTable(\$connection, 'table_name');

        // Example 5: Common column types
        // \$table->bigId();                      // BIGINT primary key
        // \$table->string('name', 100);          // VARCHAR(100)
        // \$table->text('content');              // TEXT
        // \$table->mediumText('content');        // MEDIUMTEXT (MySQL)
        // \$table->longText('content');          // LONGTEXT (MySQL)
        // \$table->integer('count');             // INTEGER
        // \$table->bigInteger('count');          // BIGINT
        // \$table->smallInteger('count');        // SMALLINT
        // \$table->tinyInteger('count');         // TINYINT (MySQL)
        // \$table->boolean('active');            // BOOLEAN/INTEGER
        // \$table->decimal('amount', 8, 2);      // DECIMAL(8, 2)
        // \$table->float('rate');                // FLOAT
        // \$table->double('rate');               // DOUBLE
        // \$table->date('birthday');             // DATE
        // \$table->dateTime('published_at');     // DATETIME/TIMESTAMP
        // \$table->time('start_time');           // TIME
        // \$table->timestamp('deleted_at');      // TIMESTAMP
        // \$table->json('metadata');             // JSON
        // \$table->jsonb('metadata');            // JSONB (PostgreSQL)
        // \$table->uuid('uuid');                 // UUID
        // \$table->ipAddress('ip');              // VARCHAR(45) for IPv6
        // \$table->macAddress('mac');            // VARCHAR(17)
        // \$table->binary('data');               // BLOB
    }

    public function down(Connection \$connection): void
    {
        // Rollback logic - typically the reverse of up()
        // Example:
        // \$this->dropTable(\$connection, 'table_name');
        
        // Or using Schema helper:
        // \$this->schema(\$connection, function (\$schema) {
        //     \$schema->dropIfExists('table_name');
        // });
    }
};

PHP;

        file_put_contents($path, $template);

        echo "Migration created: {$path}\n";
    }
}
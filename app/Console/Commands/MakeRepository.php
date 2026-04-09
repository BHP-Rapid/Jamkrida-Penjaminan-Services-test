<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class MakeRepository extends Command
{
    protected $signature = 'make:repository {name}';
    protected $description = 'Create a new repository class';

    public function handle()
    {
        $name = $this->argument('name');

        $directory = app_path('Repositories');
        $path = $directory . "/{$name}.php";

        // cek folder
        if (!File::exists($directory)) {
            File::makeDirectory($directory, 0755, true);
        }

        // cek file
        if (File::exists($path)) {
            $this->error("Repository {$name} already exists!");
            return;
        }

        // template isi file
        $content = "<?php

namespace App\Repositories;

class {$name}
{
    //
}
";

        File::put($path, $content);

        $this->info("Repository {$name} created successfully.");
    }
}
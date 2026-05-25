<?php

namespace TelescopeMongoDB\Driver\Console;

use Illuminate\Console\Command;

class InstallCommand extends Command
{
    protected $signature = 'telescope-mongodb:install
                            {--force : Overwrite existing config files}
                            {--skip-telescope : Do not publish Telescope assets}
                            {--skip-indexes : Do not create MongoDB indexes}';

    protected $description = 'Install the MongoDB driver for Laravel Telescope.';

    public function handle(): int
    {
        $this->components->info('Installing the MongoDB driver for Laravel Telescope.');

        $this->publishConfig();

        if (! $this->option('skip-telescope')) {
            $this->publishTelescopeAssets();
        }

        if (! $this->option('skip-indexes')) {
            $this->call('telescope-mongodb:sync-indexes');
        }

        $this->newLine();
        $this->components->info('Done. Set TELESCOPE_DRIVER=mongodb is not required — the driver replaces the Eloquent storage automatically.');
        $this->line('  Review <fg=cyan>config/telescope-mongodb.php</> to adjust the connection or collection names.');

        return self::SUCCESS;
    }

    protected function publishConfig(): void
    {
        $params = ['--tag' => 'telescope-mongodb-config'];

        if ($this->option('force')) {
            $params['--force'] = true;
        }

        $this->call('vendor:publish', $params);
    }

    protected function publishTelescopeAssets(): void
    {
        $params = ['--provider' => 'Laravel\\Telescope\\TelescopeServiceProvider'];

        if ($this->option('force')) {
            $params['--force'] = true;
        }

        $this->call('telescope:install');
        $this->call('vendor:publish', $params);
    }
}

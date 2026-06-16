<?php

namespace TelescopeMongoDB\Driver\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class InstallCommand extends Command
{
    protected $signature = 'telescope-mongodb:install
                            {--force : Overwrite existing config files}
                            {--skip-telescope : Do not publish Telescope assets}
                            {--skip-indexes : Do not create MongoDB indexes}
                            {--keep-sql-migrations : Do not delete the relational migration files published by Telescope}';

    protected $description = 'Install the MongoDB driver for Laravel Telescope.';

    public function handle(): int
    {
        $this->components->info('Installing the MongoDB driver for Laravel Telescope.');

        $this->publishConfig();

        if (! $this->option('skip-telescope')) {
            $this->publishTelescopeAssets();

            if (! $this->option('keep-sql-migrations')) {
                $this->removeSqlMigrations();
            }
        }

        if (! $this->option('skip-indexes')) {
            $this->call('telescope-mongodb:sync-indexes');
        }

        $this->newLine();
        $this->components->info('Done. The MongoDB driver has replaced the default Eloquent storage.');
        $this->line('  Review <fg=cyan>config/telescope-mongodb.php</> to adjust the connection or collection names.');

        $this->suggestTtl();

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

    protected function suggestTtl(): void
    {
        $ttl = config('telescope-mongodb.indexes.ttl_seconds');

        if (is_numeric($ttl) && (int) $ttl > 0) {
            return;
        }

        $this->newLine();
        $this->components->warn('Automatic pruning is off. Telescope entries will accumulate until removed manually.');
        $this->line('  Enable it by adding a retention window (in seconds) to your <fg=cyan>.env</>, for example 7 days:');
        $this->line('      <fg=cyan>TELESCOPE_MONGODB_TTL_SECONDS=604800</>');
        $this->line('  Then run <fg=cyan>php artisan telescope-mongodb:sync-indexes</> and MongoDB will purge old entries for you.');
    }

    protected function removeSqlMigrations(): void
    {
        $directory = database_path('migrations');

        if (! File::isDirectory($directory)) {
            return;
        }

        $removed = 0;

        foreach (File::files($directory) as $file) {
            if (preg_match('/_(create|update)_telescope_(entries|monitoring)/', $file->getFilename()) === 1) {
                File::delete($file->getPathname());
                $removed++;
            }
        }

        if ($removed > 0) {
            $this->components->task(sprintf('Removed %d unused Telescope SQL migration(s)', $removed), fn () => true);
        }
    }
}

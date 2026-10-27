<?php

namespace Lantern\Console;

use Illuminate\Console\Command;

class InstallCommand extends Command
{
    protected $signature = 'lantern:install {--force : Overwrite published files}';

    protected $description = 'Publish Lantern config and migrations';

    public function handle(): int
    {
        $force = (bool) $this->option('force');

        $this->call('vendor:publish', ['--tag' => 'lantern-config', '--force' => $force]);
        $this->call('vendor:publish', ['--tag' => 'lantern-migrations', '--force' => $force]);

        $this->info('Lantern installed. Set LANTERN_ENABLED=true to enable it.');

        return self::SUCCESS;
    }
}

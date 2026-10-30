<?php

declare(strict_types=1);

namespace Lantern\Console;

use Illuminate\Console\Command;
use Lantern\Agent\Findings;
use Lantern\Agent\Output;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

final class InitCommand extends Command
{
    protected $signature = 'lantern:init
        {--write : Write the surfaces to config/lantern-access.php (otherwise they are only printed)}
        {--force : With --write, replace an existing file}
        {--pretty : Pretty-print json}';

    protected $description = 'Propose lantern.access surfaces for this app from its auth guards and installed packages';

    public function handle(): int
    {
        $surfaces = [];
        $notes = [];

        foreach ((array) config('auth.guards', []) as $name => $guard) {
            $driver = (string) ($guard['driver'] ?? '');
            if ($driver === 'session' && ! isset($surfaces['panel'])) {
                $surfaces['panel'] = $this->sessionSurface((string) $name, (string) ($guard['provider'] ?? ''), $notes);
            } elseif (! in_array($driver, ['session', 'token', 'sanctum'], true)) {
                $label = (string) $name;
                $surfaces[$label] = ['type' => 'custom', 'guard' => $label, 'credentials' => 'App\\Lantern\\Local'.str_replace(' ', '', ucwords(str_replace(['-', '_'], ' ', $label))).'Credentials', 'subject' => []];
                $notes[] = 'Guard "'.$label.'" uses driver "'.$driver.'", which Lantern cannot log into: create the credentials class (Lantern\\Access\\ProvidesCredentials) and fill in "subject"; nothing is guessed.';
            }
        }

        if (class_exists(\Laravel\Sanctum\Sanctum::class) && $this->usesApiTokens($surfaces['panel']['guard'] ?? 'web')) {
            $surfaces['api'] = ['type' => 'sanctum', 'guard' => $surfaces['panel']['guard'] ?? 'web', 'user' => $surfaces['panel']['user'] ?? null, 'abilities' => ['*'], 'ttl_minutes' => 30, 'headers' => []];
        }
        if (isset($surfaces['panel'])) {
            $surfaces['panel']['session'] = [];
            $notes[] = 'App-specific session keys set after login (an active tenant, for example) are app knowledge: fill in panel.session if your middleware needs them.';
        }
        if ($surfaces === []) {
            $notes[] = 'No auth guards found in config/auth.php.';
        }

        $php = "<?php\n\nreturn ".$this->export($surfaces).";\n";
        $file = $this->laravel->configPath('lantern-access.php');
        $written = false;
        if ($this->option('write')) {
            if (is_file($file) && ! $this->option('force')) {
                $notes[] = 'config/lantern-access.php exists; pass --force to replace it.';
            } else {
                file_put_contents($file, $php);
                $written = true;
            }
        }

        $payload = [
            'schema_version' => Findings::VERSION,
            'schema' => 'lantern.init',
            'surfaces' => $surfaces,
            'written' => $written,
            'file' => $this->option('write') ? 'config/lantern-access.php' : null,
            'php' => $php,
            'notes' => $notes,
            'next' => $written ? 'php artisan lantern:login --list' : 'Review, then: php artisan lantern:init --write',
        ];
        $text = Output::render('json', array_filter($payload, fn ($v) => $v !== null), null, null, (bool) $this->option('pretty'));
        $this->output->writeln($text, OutputInterface::OUTPUT_RAW);

        return self::SUCCESS;
    }

    /**
     * @param list<string> $notes
     * @return array<string, mixed>
     */
    private function sessionSurface(string $guard, string $provider, array &$notes): array
    {
        $surface = ['type' => 'session', 'guard' => $guard, 'user' => null, 'home' => '/'];

        $filament = $this->filamentPanel();
        if ($filament !== null) {
            $surface['home'] = '/'.ltrim($filament['path'], '/');
        }

        $model = (string) config('auth.providers.'.$provider.'.model', '');
        if ($model === '' || ! class_exists($model)) {
            $notes[] = 'Could not find the user model for guard "'.$guard.'"; set panel.user to an id or email.';

            return $surface;
        }

        try {
            $query = $model::query()->orderBy((new $model)->getKeyName())->limit(50)->get();
            $user = $query->first(fn ($u) => $filament === null || ! method_exists($u, 'canAccessPanel') || $u->canAccessPanel($filament['panel']))
                ?? $query->first();
            $surface['user'] = $user?->getAuthIdentifier();
            if ($user === null) {
                $notes[] = 'The users table is empty; set panel.user once an account exists.';
            }
        } catch (Throwable) {
            $notes[] = 'Could not read users to pick a default; set panel.user to an id or email.';
        }

        return $surface;
    }

    /** @return array{panel: object, path: string}|null */
    private function filamentPanel(): ?array
    {
        if (! class_exists(\Filament\Facades\Filament::class)) {
            return null;
        }
        try {
            $panel = \Filament\Facades\Filament::getDefaultPanel();

            return ['panel' => $panel, 'path' => (string) $panel->getPath()];
        } catch (Throwable) {
            return null;
        }
    }

    private function usesApiTokens(string $guard): bool
    {
        $provider = (string) config('auth.guards.'.$guard.'.provider', '');
        $model = (string) config('auth.providers.'.$provider.'.model', '');

        return $model !== '' && class_exists($model) && in_array(\Laravel\Sanctum\HasApiTokens::class, class_uses_recursive($model), true);
    }

    /** @param array<array-key, mixed> $value */
    private function export(array $value, int $depth = 0): string
    {
        if ($value === []) {
            return '[]';
        }
        $pad = str_repeat('    ', $depth + 1);
        $list = array_is_list($value);
        $lines = [];
        foreach ($value as $key => $item) {
            $lines[] = $pad.($list ? '' : var_export($key, true).' => ').(is_array($item) ? $this->export($item, $depth + 1) : var_export($item, true)).',';
        }

        return "[\n".implode("\n", $lines)."\n".str_repeat('    ', $depth).']';
    }
}

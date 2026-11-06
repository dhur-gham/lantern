<?php

declare(strict_types=1);

namespace Lantern\Scenario;

use Lantern\Agent\AgentException;

/** A scenario file, read and checked: plain data that names steps, never code. */
final class Scenario
{
    private const TOP_KEYS = ['name', 'surface', 'user', 'session', 'fake', 'throttle', 'steps'];

    private const LIVEWIRE_KEYS = ['livewire', 'mount', 'session', 'do'];

    private const REQUEST_KEYS = ['request', 'path', 'json', 'form', 'files', 'query', 'headers', 'as', 'session', 'expect'];

    private const AS_KEYS = ['surface', 'user', 'via'];

    private const ACTION_KEYS = ['set', 'value', 'call', 'args', 'upload', 'file', 'files'];

    private const FILE_KEYS = ['name', 'kind', 'kb', 'width', 'height', 'mime'];

    public const MAX_FILE_BYTES = 2_000_000;

    public const MAX_STEPS = 500;

    public const MAX_ACTIONS = 200;

    public const MAX_FILES = 10;

    public const MAX_FILE_KB = 10_240;

    public const MAX_IMAGE_SIDE = 4_000;

    public const FAKES = ['queue', 'bus', 'mail', 'notification', 'http', 'storage'];

    /** The fakes that matter for what leaves the machine; `bus` only stops synchronously dispatched jobs from running, `storage` matters once files are uploaded. */
    public const EXTERNAL_EFFECTS = ['queue', 'mail', 'notification', 'http'];

    /** Header names that carry a credential; a scenario names a surface (`as`) instead. */
    private const CREDENTIAL_HEADER = '/auth|token|secret|api[-_]?key|passw|cookie|csrf|xsrf|session|credential/i';

    private const METHODS = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'];

    /**
     * @param  list<array<string, mixed>>  $steps
     * @param  array<string, mixed>  $session
     */
    public function __construct(
        public readonly string $name,
        public readonly ?string $surface,
        public readonly ?string $user,
        public readonly array $session,
        public readonly array $steps,
        /** @var list<string> */
        public readonly array $fake = [],
        public readonly ?bool $throttle = null,
    ) {}

    /**
     * What to fake for a run: the config default plus the scenario's own list.
     *
     * @param  list<string>  $configDefault
     * @return list<string>
     */
    public function effectiveFakes(array $configDefault): array
    {
        return array_values(array_unique([...$configDefault, ...$this->fake]));
    }

    /** A write request, or a Livewire step with an action: a `set` or `call` can write, queue, mail or call out. */
    public function hasWrites(): bool
    {
        foreach ($this->steps as $step) {
            if ($step['kind'] === 'request' && $step['method'] !== 'GET') {
                return true;
            }
            if ($step['kind'] === 'livewire' && $step['do'] !== []) {
                return true;
            }
        }

        return false;
    }

    /** An upload step or a request with files: the file ends up on a storage disk, which may be a remote bucket. */
    public function hasUploads(): bool
    {
        foreach ($this->steps as $step) {
            if ($step['kind'] === 'request' && $step['files'] !== []) {
                return true;
            }
            if ($step['kind'] === 'livewire') {
                foreach ($step['do'] as $action) {
                    if (isset($action['upload'])) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $fakes
     * @return list<string> the channels that leave the machine and are not faked; `storage` counts when files are uploaded
     */
    public static function unfakedEffects(array $fakes, bool $uploads = false): array
    {
        return array_values(array_diff([...self::EXTERNAL_EFFECTS, ...($uploads ? ['storage'] : [])], $fakes));
    }

    public static function fromFile(string $file): self
    {
        if (is_file($file) && filesize($file) > self::MAX_FILE_BYTES) {
            throw AgentException::usage("Scenario {$file} is larger than ".self::MAX_FILE_BYTES.' bytes.', 'Split it into several scenarios.');
        }

        $raw = is_file($file) ? @file_get_contents($file) : false;
        if ($raw === false) {
            throw AgentException::notFound('Scenario not found: '.$file, 'Run: php artisan lantern:scenario list');
        }

        $data = json_decode($raw, true);
        if (! is_array($data) || array_is_list($data)) {
            throw AgentException::usage("Scenario {$file} is not a JSON object.", 'See docs/CLI.md, "lantern:scenario".');
        }

        return self::fromArray($data, pathinfo($file, PATHINFO_FILENAME));
    }

    /** @param  array<string, mixed>  $data */
    public static function fromArray(array $data, string $fallbackName = 'scenario'): self
    {
        self::onlyKeys($data, self::TOP_KEYS, 'scenario');

        $name = $data['name'] ?? $fallbackName;
        if (! is_string($name) || preg_match('/^[A-Za-z0-9._-]{1,80}$/', $name) !== 1) {
            throw AgentException::usage('"name" must be 1-80 letters, digits, dots, dashes or underscores.');
        }

        $surface = self::optionalString($data, 'surface', 'scenario');
        $user = self::optionalString($data, 'user', 'scenario');
        $session = self::optionalObject($data, 'session', 'scenario');
        $fake = self::fakes($data['fake'] ?? []);
        $throttle = $data['throttle'] ?? null;
        if ($throttle !== null && ! is_bool($throttle)) {
            throw AgentException::usage('"throttle" must be true or false.');
        }

        $steps = $data['steps'] ?? null;
        if (! is_array($steps) || $steps === [] || ! array_is_list($steps)) {
            throw AgentException::usage('"steps" must be a non-empty list.');
        }

        if (count($steps) > self::MAX_STEPS) {
            throw AgentException::usage('A scenario can have at most '.self::MAX_STEPS.' steps.', 'Split it into several scenarios.');
        }

        $checked = [];
        foreach ($steps as $i => $step) {
            $checked[] = self::step($step, $i + 1);
        }

        return new self($name, $surface, $user, $session, $checked, $fake, $throttle);
    }

    /** @return list<string> */
    public static function fakes(mixed $fake): array
    {
        if (! is_array($fake) || ! array_is_list($fake) || count(array_filter($fake, 'is_string')) !== count($fake) || array_diff($fake, self::FAKES) !== []) {
            throw AgentException::usage('"fake" must be a list of: '.implode(', ', self::FAKES).'.');
        }

        return array_values(array_unique($fake));
    }

    /** @return array<string, mixed> */
    private static function step(mixed $step, int $number): array
    {
        $where = "step {$number}";
        if (! is_array($step) || array_is_list($step)) {
            throw AgentException::usage("{$where} must be an object.");
        }

        if (isset($step['livewire']) === isset($step['request'])) {
            throw AgentException::usage("{$where} needs exactly one of \"livewire\" or \"request\".");
        }

        if (isset($step['livewire'])) {
            self::onlyKeys($step, self::LIVEWIRE_KEYS, $where);

            return self::livewireStep($step, $where);
        }

        self::onlyKeys($step, self::REQUEST_KEYS, $where);

        return self::requestStep($step, $where);
    }

    /**
     * @param  array<string, mixed>  $step
     * @return array<string, mixed>
     */
    private static function livewireStep(array $step, string $where): array
    {
        $class = $step['livewire'];
        if (! is_string($class) || preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\\\\[A-Za-z_][A-Za-z0-9_]*)*$/', $class) !== 1) {
            throw AgentException::usage("{$where}: \"livewire\" must be a component class name.");
        }

        $do = $step['do'] ?? [];
        if (! is_array($do) || ! array_is_list($do)) {
            throw AgentException::usage("{$where}: \"do\" must be a list.");
        }

        if (count($do) > self::MAX_ACTIONS) {
            throw AgentException::usage("{$where}: \"do\" can have at most ".self::MAX_ACTIONS.' items.', 'Split it into several steps.');
        }

        $actions = [];
        foreach ($do as $j => $action) {
            $actions[] = self::action($action, "{$where}, do ".($j + 1));
        }

        return [
            'kind' => 'livewire',
            'class' => $class,
            'mount' => self::optionalObject($step, 'mount', $where),
            'session' => self::optionalObject($step, 'session', $where),
            'do' => $actions,
        ];
    }

    /** @return array<string, mixed> */
    private static function action(mixed $action, string $where): array
    {
        if (! is_array($action) || array_is_list($action)) {
            throw AgentException::usage("{$where} must be an object.");
        }
        self::onlyKeys($action, self::ACTION_KEYS, $where);

        $kinds = count(array_filter([isset($action['set']), isset($action['call']), isset($action['upload'])]));
        if ($kinds !== 1) {
            throw AgentException::usage("{$where} needs exactly one of \"set\", \"call\" or \"upload\".");
        }

        if (isset($action['upload'])) {
            return self::uploadAction($action, $where);
        }
        if (isset($action['file']) || isset($action['files'])) {
            throw AgentException::usage("{$where}: \"file\" and \"files\" go with \"upload\".");
        }

        if (isset($action['set'])) {
            if (! is_string($action['set']) || $action['set'] === '' || ! array_key_exists('value', $action) || isset($action['args'])) {
                throw AgentException::usage("{$where}: \"set\" takes a property name and a \"value\".");
            }

            return ['set' => $action['set'], 'value' => $action['value']];
        }

        if (! is_string($action['call']) || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $action['call']) !== 1 || array_key_exists('value', $action)) {
            throw AgentException::usage("{$where}: \"call\" takes a method name and optional \"args\".");
        }
        $args = $action['args'] ?? [];
        if (! is_array($args) || ! array_is_list($args)) {
            throw AgentException::usage("{$where}: \"args\" must be a list.");
        }

        return ['call' => $action['call'], 'args' => $args];
    }

    /**
     * @param  array<string, mixed>  $action
     * @return array<string, mixed>
     */
    private static function uploadAction(array $action, string $where): array
    {
        if (! is_string($action['upload']) || $action['upload'] === '' || array_key_exists('value', $action) || isset($action['args'])) {
            throw AgentException::usage("{$where}: \"upload\" takes a property name and a \"file\" or \"files\".");
        }
        if (isset($action['file']) === isset($action['files'])) {
            throw AgentException::usage("{$where}: \"upload\" needs exactly one of \"file\" (one file) or \"files\" (a list, for an array property).");
        }

        $multiple = isset($action['files']);
        $specs = $multiple ? $action['files'] : [$action['file']];
        if (! is_array($specs) || ! array_is_list($specs) || $specs === [] || count($specs) > self::MAX_FILES) {
            throw AgentException::usage("{$where}: \"files\" must be a list of 1 to ".self::MAX_FILES.' files.');
        }

        return [
            'upload' => $action['upload'],
            'files' => array_map(fn (mixed $spec) => self::fileSpec($spec, $where), $specs),
            'multiple' => $multiple,
        ];
    }

    /**
     * A file to generate and upload: Laravel's fake files, never a path on this machine.
     *
     * @return array{name: string, kind: string, kb: int, width: int, height: int, mime: string}
     */
    private static function fileSpec(mixed $spec, string $where): array
    {
        if (! is_array($spec) || ($spec !== [] && array_is_list($spec))) {
            throw AgentException::usage("{$where}: a file must be an object such as {\"name\": \"photo.jpg\", \"kb\": 300}.");
        }
        self::onlyKeys($spec, self::FILE_KEYS, "{$where}, file");

        $name = $spec['name'] ?? 'upload.jpg';
        if (! is_string($name) || preg_match('/^[A-Za-z0-9][A-Za-z0-9._ -]{0,99}$/', $name) !== 1 || str_contains($name, '..')) {
            throw AgentException::usage("{$where}: a file \"name\" is a plain file name (letters, digits, dot, dash, underscore, space).");
        }

        $kind = $spec['kind'] ?? (preg_match('/\.(jpe?g|png|gif|webp|bmp)$/i', $name) === 1 ? 'image' : 'file');
        if (! in_array($kind, ['image', 'file'], true)) {
            throw AgentException::usage("{$where}: a file \"kind\" is \"image\" or \"file\".");
        }

        $mime = $spec['mime'] ?? 'application/octet-stream';
        if (! is_string($mime) || preg_match('/^[a-z0-9.+-]+\/[a-z0-9.+-]+$/i', $mime) !== 1) {
            throw AgentException::usage("{$where}: a file \"mime\" looks like image/jpeg.");
        }

        return [
            'name' => $name,
            'kind' => $kind,
            'kb' => self::boundedInt($spec['kb'] ?? 100, 1, self::MAX_FILE_KB, "{$where}: file \"kb\""),
            'width' => self::boundedInt($spec['width'] ?? 640, 1, self::MAX_IMAGE_SIDE, "{$where}: file \"width\""),
            'height' => self::boundedInt($spec['height'] ?? 480, 1, self::MAX_IMAGE_SIDE, "{$where}: file \"height\""),
            'mime' => $mime,
        ];
    }

    /**
     * @return array<string, list<array{name: string, kind: string, kb: int, width: int, height: int, mime: string}>>
     */
    private static function requestFiles(mixed $files, string $where): array
    {
        if (! is_array($files) || ($files !== [] && array_is_list($files))) {
            throw AgentException::usage("{$where}: \"files\" must be an object of form field to file (or list of files).");
        }

        $out = [];
        $total = 0;
        foreach ($files as $field => $spec) {
            if (preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,60}$/', (string) $field) !== 1) {
                throw AgentException::usage("{$where}: \"{$field}\" is not a valid form field name.");
            }
            $list = is_array($spec) && $spec !== [] && array_is_list($spec) ? $spec : [$spec];
            foreach ($list as $one) {
                $out[(string) $field][] = self::fileSpec($one, "{$where}, files.{$field}");
                $total++;
            }
        }
        if ($total > self::MAX_FILES) {
            throw AgentException::usage("{$where}: at most ".self::MAX_FILES.' files per request.');
        }

        return $out;
    }

    private static function boundedInt(mixed $value, int $min, int $max, string $what): int
    {
        if (! is_int($value) || $value < $min || $value > $max) {
            throw AgentException::usage("{$what} must be a whole number from {$min} to {$max}.");
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $step
     * @return array<string, mixed>
     */
    private static function requestStep(array $step, string $where): array
    {
        $method = $step['request'];
        if (! is_string($method) || ! in_array(strtoupper($method), self::METHODS, true)) {
            throw AgentException::usage("{$where}: \"request\" must be one of ".implode(', ', self::METHODS).'.');
        }

        $path = $step['path'] ?? null;
        if (! is_string($path) || ! str_starts_with($path, '/') || str_contains($path, '://') || str_starts_with($path, '//')) {
            throw AgentException::usage("{$where}: \"path\" must be a path on this app, such as /api/orders/list-orders.");
        }

        $expect = $step['expect'] ?? null;
        if ($expect !== null && (! is_int($expect) || $expect < 100 || $expect > 599)) {
            throw AgentException::usage("{$where}: \"expect\" must be an HTTP status code such as 200 or 422.");
        }

        $as = self::optionalObject($step, 'as', $where);
        self::onlyKeys($as, self::AS_KEYS, "{$where}, as");
        foreach (['surface', 'user'] as $key) {
            if (array_key_exists($key, $as) && $as[$key] !== null && (! is_string($as[$key]) || $as[$key] === '')) {
                throw AgentException::usage("{$where}: as.{$key} must be a non-empty string.");
            }
        }
        if (array_key_exists('via', $as) && $as['via'] !== null && $as['via'] !== 'token') {
            throw AgentException::usage("{$where}: as.via can only be \"token\" (send a real token, as an API client does).");
        }

        if (array_key_exists('json', $step) && (isset($step['form']) || isset($step['files']))) {
            throw AgentException::usage("{$where}: use \"json\" or \"form\"/\"files\", not both.");
        }
        $files = self::requestFiles($step['files'] ?? [], $where);
        if ($files !== [] && strtoupper($method) !== 'POST') {
            throw AgentException::usage("{$where}: files are sent with POST.");
        }

        $headers = self::optionalObject($step, 'headers', $where);
        foreach ($headers as $header => $value) {
            if (preg_match(self::CREDENTIAL_HEADER, (string) $header) === 1) {
                throw AgentException::usage("{$where}: set credentials with \"as\" (a surface), not the {$header} header; headers that carry a credential are refused.");
            }
            if (! is_scalar($value)) {
                throw AgentException::usage("{$where}: header {$header} must be a string.");
            }
        }

        return [
            'kind' => 'request',
            'method' => strtoupper($method),
            'path' => $path,
            'json' => array_key_exists('json', $step) ? $step['json'] : null,
            'form' => self::optionalObject($step, 'form', $where),
            'files' => $files,
            'query' => self::optionalObject($step, 'query', $where),
            'headers' => array_map('strval', $headers),
            'as' => $as,
            'session' => self::optionalObject($step, 'session', $where),
            'expect' => $expect,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<string>  $allowed
     */
    private static function onlyKeys(array $data, array $allowed, string $where): void
    {
        $extra = array_diff(array_map('strval', array_keys($data)), $allowed);
        if ($extra !== []) {
            throw AgentException::usage("Unknown key in {$where}: ".implode(', ', $extra).'.', 'Allowed: '.implode(', ', $allowed).'.');
        }
    }

    /** @param  array<string, mixed>  $data */
    private static function optionalString(array $data, string $key, string $where): ?string
    {
        if (! array_key_exists($key, $data) || $data[$key] === null) {
            return null;
        }
        if (! is_string($data[$key]) || $data[$key] === '') {
            throw AgentException::usage("{$where}: \"{$key}\" must be a non-empty string.");
        }

        return $data[$key];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private static function optionalObject(array $data, string $key, string $where): array
    {
        $value = $data[$key] ?? [];
        if (! is_array($value) || ($value !== [] && array_is_list($value))) {
            throw AgentException::usage("{$where}: \"{$key}\" must be an object.");
        }

        return $value;
    }
}

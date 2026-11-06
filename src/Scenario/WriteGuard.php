<?php

declare(strict_types=1);

namespace Lantern\Scenario;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Events\ConnectionEstablished;
use Lantern\Instrumentation\Query\QuerySuspend;
use Throwable;

/**
 * Proves a rollback: before the first write to a table, on any connection, it records the table's row count and a
 * checksum of its contents; once the run is rolled back, it reads them again. A table that differs, a table that
 * cannot be read, or a statement that cannot be rolled back (DDL commits implicitly on MySQL) is reported. Writes it
 * cannot attribute to a table are listed, so what the check does not cover is visible.
 */
final class WriteGuard
{
    /** MySQL and MariaDB checksum tables up to this size: CHECKSUM TABLE reads the whole table. */
    public const CHECKSUM_MAX_ROWS = 200_000;

    /** Other drivers get a hash computed in PHP, which reads every row, so only for small tables. */
    public const HASH_MAX_ROWS = 20_000;

    private const SEGMENT = '(?:`[^`]+`|"[^"]+"|\[[^\]]+\]|[\w$]+)';

    private const WRITE_VERB = '(?:insert\s+(?:(?:or\s+\w+|ignore|low_priority|high_priority|delayed)\s+)*into|replace\s+(?:(?:low_priority|delayed)\s+)*into|update(?:\s+(?:or\s+\w+|low_priority|ignore))*|delete\s+(?:(?:low_priority|quick|ignore)\s+)*from|merge\s+into|load\s+data\s+[^;]*?\binto\s+table)';

    private const IMPLICIT_COMMIT = '/^\s*(create|alter|drop|rename|truncate)\s/i';

    private bool $hooked = false;

    private bool $measuring = false;

    /** @var array<string, array{connection: Connection, table: string, count: ?int, checksum: ?string, error: ?string}> */
    private array $seen = [];

    /** @var list<string> */
    private array $implicitCommits = [];

    /** @var list<string> */
    private array $unattributed = [];

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly Application $app,
    ) {}

    public static function forApp(Application $app): self
    {
        if (! $app->bound(self::class)) {
            $app->instance(self::class, new self($app->make('db'), $app));
        }

        return $app->make(self::class);
    }

    /**
     * Reads a statement: is it a write, and which table does it write to? The table is the physical name that is in
     * the SQL (Laravel has put the connection's prefix on it already), without quotes.
     *
     * @return array{write: bool, table: ?string}
     */
    public static function classify(string $sql): array
    {
        $sql = trim((string) preg_replace('/^(?:\s|\/\*.*?\*\/|--[^\n]*\n)+/s', '', $sql));
        $segment = self::SEGMENT;
        $verb = self::WRITE_VERB;

        if (preg_match("/^{$verb}\s+({$segment}(?:\.{$segment})?)/is", $sql, $match) === 1) {
            return ['write' => true, 'table' => self::unquote($match[1])];
        }

        if (preg_match('/^with\b/i', $sql) === 1) {
            // a data-modifying CTE: the write comes right after a parenthesis
            if (preg_match("/[()]\s*{$verb}\s+({$segment}(?:\.{$segment})?)/is", $sql, $match) === 1) {
                return ['write' => true, 'table' => self::unquote($match[1])];
            }

            return ['write' => preg_match('/[()]\s*(?:insert|replace|update|delete|merge)\b/i', $sql) === 1, 'table' => null];
        }

        return ['write' => preg_match('/^(?:insert|replace|update|delete|merge|load\s+data)\b/i', $sql) === 1, 'table' => null];
    }

    public function start(): void
    {
        if ($this->hooked) {
            return;
        }
        $this->hooked = true;

        foreach ($this->db->getConnections() as $connection) {
            $this->hook($connection);
        }
        $this->app->make('events')->listen(ConnectionEstablished::class, fn (ConnectionEstablished $e) => $this->hook($e->connection));
    }

    /**
     * Call after the rollback.
     *
     * @return array{ok: bool, checked: list<array<string, mixed>>, problems: list<string>, unattributed: list<string>, note: string}
     */
    public function verify(): array
    {
        $checked = [];
        $problems = [];

        foreach ($this->seen as $entry) {
            $now = $this->measure($entry['connection'], $entry['table']);
            $table = $entry['table'];

            if ($entry['error'] !== null || $now['error'] !== null) {
                $why = $entry['error'] !== null ? "before the run: {$entry['error']}" : "after the rollback: {$now['error']}";
                $problems[] = "Could not read {$table} {$why}. Its rollback is not verified; if this table cannot be read, set lantern.scenarios.verify_rollback to false.";
                $checked[] = ['connection' => (string) $entry['connection']->getName(), 'table' => $table, 'rows_before' => $entry['count'], 'rows_after' => $now['count'], 'checksum' => 'could not be read'];

                continue;
            }

            $checksum = $entry['checksum'] === null || $now['checksum'] === null ? 'not checked' : ($entry['checksum'] === $now['checksum'] ? 'same' : 'changed');
            $checked[] = ['connection' => (string) $entry['connection']->getName(), 'table' => $table, 'rows_before' => $entry['count'], 'rows_after' => $now['count'], 'checksum' => $checksum];

            if ($entry['count'] !== $now['count']) {
                $problems[] = "{$table} has {$now['count']} rows after the rollback, it had {$entry['count']}.";
            }
            if ($checksum === 'changed') {
                $problems[] = "{$table} has different contents after the rollback.";
            }
        }

        foreach ($this->implicitCommits as $statement) {
            $problems[] = "\"{$statement}\" commits implicitly on MySQL and cannot be rolled back.";
        }

        return [
            'ok' => $problems === [],
            'checked' => $checked,
            'problems' => $problems,
            'unattributed' => $this->unattributed,
            'note' => $checked === [] && $this->unattributed === []
                ? 'No write statement ran, so there was nothing to roll back.'
                : 'Row counts on every driver; contents by a checksum on MySQL and MariaDB (tables up to '.self::CHECKSUM_MAX_ROWS.' rows) and by a hash computed in PHP elsewhere (tables up to '.self::HASH_MAX_ROWS.' rows). A row changed by another process during the run would show here too.',
        ];
    }

    private function hook(Connection $connection): void
    {
        $connection->beforeExecuting(function (string $query) use ($connection): void {
            if (! $this->measuring) {
                $this->before($connection, $query);
            }
        });
    }

    private function before(Connection $connection, string $query): void
    {
        if (preg_match(self::IMPLICIT_COMMIT, $query) === 1) {
            $this->implicitCommits[] = $this->excerpt($query);

            return;
        }

        $statement = self::classify($query);
        if (! $statement['write']) {
            return;
        }
        if ($statement['table'] === null) {
            $excerpt = $this->excerpt($query);
            if (count($this->unattributed) < 10 && ! in_array($excerpt, $this->unattributed, true)) {
                $this->unattributed[] = $excerpt;
            }

            return;
        }

        $table = $statement['table'];
        if ($this->isLanternTable($connection, $table)) {
            return;
        }

        $key = $connection->getName().'|'.$table;
        if (isset($this->seen[$key])) {
            return;
        }

        $this->seen[$key] = ['connection' => $connection, 'table' => $table] + $this->measure($connection, $table);
    }

    /** Lantern's own tables, with or without the connection's prefix, are not the application's to verify. */
    private function isLanternTable(Connection $connection, string $table): bool
    {
        $segments = explode('.', $table);
        $name = (string) end($segments);
        $prefix = $connection->getTablePrefix();
        if ($prefix !== '' && str_starts_with($name, $prefix)) {
            $name = substr($name, strlen($prefix));
        }

        return str_starts_with($name, 'lantern_');
    }

    /** @return array{count: ?int, checksum: ?string, error: ?string} */
    private function measure(Connection $connection, string $table): array
    {
        $this->measuring = true;

        try {
            return QuerySuspend::run(function () use ($connection, $table): array {
                // quote each segment on its own: wrapTable() would put the connection's prefix on a name that has it
                $grammar = $connection->getQueryGrammar();
                $wrapped = implode('.', array_map(static fn (string $segment): string => $grammar->wrap($segment), explode('.', $table)));
                $count = (int) (((array) $connection->selectOne("select count(*) as c from {$wrapped}"))['c'] ?? 0);

                return ['count' => $count, 'checksum' => $this->checksum($connection, $wrapped, $count), 'error' => null];
            });
        } catch (Throwable $e) {
            return ['count' => null, 'checksum' => null, 'error' => $this->short($e)];
        } finally {
            $this->measuring = false;
        }
    }

    /** Contents are a bonus on top of the row count: a table that cannot be summed is only counted. */
    private function checksum(Connection $connection, string $wrapped, int $count): ?string
    {
        try {
            if (in_array($connection->getDriverName(), ['mysql', 'mariadb'], true)) {
                if ($count > self::CHECKSUM_MAX_ROWS) {
                    return null;
                }
                $row = (array) $connection->selectOne("checksum table {$wrapped}");

                return isset($row['Checksum']) ? (string) $row['Checksum'] : null;
            }

            if ($count > self::HASH_MAX_ROWS) {
                return null;
            }

            // the sum of a hash per row does not depend on the order the rows come back in
            $sum = 0;
            $rows = 0;
            foreach ($connection->cursor("select * from {$wrapped}") as $row) {
                $sum += crc32(serialize((array) $row));
                $rows++;
            }

            return "{$rows}:{$sum}";
        } catch (Throwable) {
            return null;
        }
    }

    private static function unquote(string $table): string
    {
        $segments = preg_match_all('/'.self::SEGMENT.'/', $table, $found) > 0 ? $found[0] : [$table];

        return implode('.', array_map(static fn (string $segment): string => trim($segment, '`"[]'), $segments));
    }

    private function excerpt(string $query): string
    {
        return mb_substr(trim((string) preg_replace('/\s+/', ' ', $query)), 0, 60);
    }

    private function short(Throwable $e): string
    {
        $message = trim((string) strtok($e->getMessage(), "\n"));

        return mb_substr(class_basename($e).': '.$message, 0, 160);
    }
}

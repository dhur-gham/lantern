<?php

declare(strict_types=1);

namespace Lantern\Console;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Events\ConnectionEstablished;
use Throwable;

/**
 * Wraps a run in a transaction on every connection it touches: the default one at once, any other just before its
 * first query, including connections opened in the middle of the run. rollBack() undoes them all.
 */
final class ConnectionRollback
{
    private bool $active = false;

    private bool $hooked = false;

    /** @var array<string, array{0: Connection, 1: int}> connection name => connection and its level before the run */
    private array $begun = [];

    /** @var array<string, true> */
    private array $failed = [];

    /** @param list<string> $skip connections left alone (Lantern's own storage) */
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly Dispatcher $events,
        private readonly array $skip = [],
    ) {}

    public function start(): void
    {
        $this->active = true;
        $this->begun = [];
        $this->failed = [];

        if (! $this->hooked) {
            $this->hooked = true;
            foreach ($this->db->getConnections() as $connection) {
                $this->hook($connection);
            }
            $this->events->listen(ConnectionEstablished::class, fn (ConnectionEstablished $e) => $this->hook($e->connection));
        }

        $this->begin($this->db->connection());
    }

    /** @return array<string, bool> connection name => whether its writes were rolled back */
    public function rollBack(): array
    {
        $this->active = false;

        $out = [];
        foreach ($this->begun as $name => [$connection, $level]) {
            try {
                while ($connection->transactionLevel() > $level) {
                    $connection->rollBack();
                }
                $out[$name] = true;
            } catch (Throwable) {
                $out[$name] = false;
            }
        }
        foreach ($this->failed as $name => $_) {
            $out[$name] = false;
        }
        $this->begun = [];
        $this->failed = [];

        return $out;
    }

    private function hook(Connection $connection): void
    {
        $connection->beforeExecuting(function () use ($connection): void {
            if ($this->active) {
                $this->begin($connection);
            }
        });
    }

    private function begin(Connection $connection): void
    {
        $name = (string) $connection->getName();
        if (isset($this->begun[$name]) || isset($this->failed[$name]) || in_array($name, $this->skip, true)) {
            return;
        }

        $level = $connection->transactionLevel();
        try {
            $connection->beginTransaction();
            $this->begun[$name] = [$connection, $level];
        } catch (Throwable) {
            $this->failed[$name] = true;
        }
    }
}

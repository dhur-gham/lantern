<?php

declare(strict_types=1);

namespace Lantern\Console;

use Illuminate\Database\Connection;
use Lantern\Agent\AgentCommand;
use Lantern\Agent\AgentException;
use Lantern\Agent\Reader;
use Throwable;

/**
 * Base for the schema commands: they read the application's own database, not Lantern storage, so the storage
 * preflight of AgentCommand does not apply.
 */
abstract class SchemaCommand extends AgentCommand
{
    abstract protected function execute_(): int;

    public function handle(): int
    {
        try {
            $this->format();

            return $this->execute_();
        } catch (AgentException $e) {
            return $this->failWith($e);
        } catch (Throwable $e) {
            return $this->failWith(AgentException::config('Schema command failed: '.$e->getMessage()));
        }
    }

    protected function perform(Reader $reader): int
    {
        return self::SUCCESS;
    }

    protected function connection(): Connection
    {
        $name = $this->option('connection');
        $connection = $this->laravel->make('db')->connection(is_string($name) && $name !== '' ? $name : null);
        if (! $connection instanceof Connection) {
            throw AgentException::config('Unknown database connection.');
        }
        if (! in_array($connection->getDriverName(), ['mysql', 'mariadb', 'pgsql', 'sqlite'], true)) {
            throw AgentException::config('Unsupported database driver: '.$connection->getDriverName(), 'Supported drivers: mysql, mariadb, pgsql, sqlite.');
        }

        return $connection;
    }
}

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function getConnection(): ?string
    {
        $name = config('lantern.storage.connection');

        return is_string($name) && $name !== '' ? $name : null;
    }

    public function up(): void
    {
        if (\Lantern\Storage\MigrationGuard::skip($this->getConnection())) {
            return;
        }

        $schema = Schema::connection($this->getConnection());
        if (! $schema->hasColumn('lantern_issues', 'site_hash')) {
            $schema->table('lantern_issues', function (Blueprint $table) {
                $table->string('site_hash', 32)->nullable();
            });
        }
    }

    public function down(): void
    {
        if (\Lantern\Storage\MigrationGuard::skip($this->getConnection())) {
            return;
        }

        $schema = Schema::connection($this->getConnection());
        if ($schema->hasTable('lantern_issues') && $schema->hasColumn('lantern_issues', 'site_hash')) {
            $schema->table('lantern_issues', function (Blueprint $table) {
                $table->dropColumn('site_hash');
            });
        }
    }
};

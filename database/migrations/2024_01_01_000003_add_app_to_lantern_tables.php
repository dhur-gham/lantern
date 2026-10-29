<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLES = ['lantern_traces', 'lantern_issues'];

    public function getConnection(): ?string
    {
        $name = config('lantern.storage.connection');

        return is_string($name) && $name !== '' ? $name : null;
    }

    public function up(): void
    {
        $schema = Schema::connection($this->getConnection());
        foreach (self::TABLES as $name) {
            if ($schema->hasColumn($name, 'app')) {
                continue;
            }
            $schema->table($name, function (Blueprint $table) {
                $table->string('app', 100)->nullable()->index();
            });
        }
    }

    public function down(): void
    {
        $schema = Schema::connection($this->getConnection());
        foreach (self::TABLES as $name) {
            if ($schema->hasTable($name) && $schema->hasColumn($name, 'app')) {
                $schema->table($name, function (Blueprint $table) {
                    $table->dropIndex(['app']);
                    $table->dropColumn('app');
                });
            }
        }
    }
};

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
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
        $schema = Schema::connection($this->getConnection());
        if (! $schema->hasColumn('lantern_issues', 'resolved_at')) {
            $schema->table('lantern_issues', function (Blueprint $table) {
                $table->dateTime('resolved_at', 3)->nullable()->index();
            });
        }
        if (! $schema->hasColumn('lantern_issues', 'shape')) {
            $schema->table('lantern_issues', function (Blueprint $table) {
                $table->string('shape', 64)->nullable()->index();
            });
        }

        if (! $schema->hasColumn('lantern_issues', 'in_size')) {
            $schema->table('lantern_issues', function (Blueprint $table) {
                $table->unsignedInteger('in_size')->nullable();
            });
        }
        if (! $schema->hasColumn('lantern_issues', 'sql_hash')) {
            $schema->table('lantern_issues', function (Blueprint $table) {
                $table->string('sql_hash', 32)->nullable();
            });
        }

        // These types are keyed by the query shape alone, so existing rows can be resolved without waiting to fire again.
        $connection = DB::connection($this->getConnection());
        $connection->table('lantern_issues')
            ->whereRaw("type in ('missing_index', 'index_not_chosen', 'plan_flip')")
            ->whereNull('shape')
            ->update(['shape' => $connection->raw('issue_key')]);
    }

    public function down(): void
    {
        $schema = Schema::connection($this->getConnection());
        foreach (['in_size', 'sql_hash'] as $column) {
            if ($schema->hasTable('lantern_issues') && $schema->hasColumn('lantern_issues', $column)) {
                $schema->table('lantern_issues', function (Blueprint $table) use ($column) {
                    $table->dropColumn($column);
                });
            }
        }
        foreach (['resolved_at', 'shape'] as $column) {
            if ($schema->hasTable('lantern_issues') && $schema->hasColumn('lantern_issues', $column)) {
                $schema->table('lantern_issues', function (Blueprint $table) use ($column) {
                    $table->dropIndex([$column]);
                });
                $schema->table('lantern_issues', function (Blueprint $table) use ($column) {
                    $table->dropColumn($column);
                });
            }
        }
    }
};

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
        $schema = Schema::connection($this->getConnection());
        if ($schema->hasColumn('lantern_plan_samples', 'sql_hash')) {
            return;
        }

        $schema->table('lantern_plan_samples', function (Blueprint $table) {
            $table->string('sql_hash', 32)->nullable();
        });
    }

    public function down(): void
    {
        $schema = Schema::connection($this->getConnection());
        if (! $schema->hasTable('lantern_plan_samples') || ! $schema->hasColumn('lantern_plan_samples', 'sql_hash')) {
            return;
        }

        $schema->table('lantern_plan_samples', function (Blueprint $table) {
            $table->dropColumn('sql_hash');
        });
    }
};

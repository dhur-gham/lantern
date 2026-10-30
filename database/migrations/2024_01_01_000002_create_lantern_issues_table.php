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

        Schema::connection($this->getConnection())->create('lantern_issues', function (Blueprint $table) {
            $table->string('issue_key', 64)->primary();
            $table->string('type', 32);
            $table->string('title', 500);
            $table->string('severity', 16);
            $table->string('app', 100)->nullable()->index();
            $table->unsignedInteger('occurrences')->default(0);
            $table->unsignedBigInteger('count_total')->default(0);
            $table->double('total_ms')->default(0);
            $table->double('max_ms')->default(0);
            $table->text('call_site')->nullable();
            $table->text('suggestion')->nullable();
            $table->dateTime('first_seen', 3);
            $table->dateTime('last_seen', 3);
            $table->uuid('last_trace_id')->nullable();

            $table->index('last_seen');
            $table->index('total_ms');
        });
    }

    public function down(): void
    {
        if (\Lantern\Storage\MigrationGuard::skip($this->getConnection())) {
            return;
        }

        Schema::connection($this->getConnection())->dropIfExists('lantern_issues');
    }
};

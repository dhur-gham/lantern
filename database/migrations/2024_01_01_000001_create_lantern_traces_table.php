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
        Schema::connection($this->getConnection())->create('lantern_traces', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->dateTime('started_at', 3);
            $table->unsignedInteger('duration_ms')->default(0);
            $table->string('status', 16)->nullable();
            $table->string('method', 10)->nullable();
            $table->string('path', 255)->nullable();
            $table->string('route', 255)->nullable();
            $table->unsignedInteger('query_count')->default(0);
            $table->float('query_total_ms')->default(0);
            $table->unsignedInteger('issue_count')->default(0);
            $table->unsignedBigInteger('peak_memory')->default(0);
            $table->longText('payload');
            $table->timestamp('created_at')->nullable();

            $table->index('started_at');
            $table->index('duration_ms');
            $table->index('issue_count');
        });
    }

    public function down(): void
    {
        Schema::connection($this->getConnection())->dropIfExists('lantern_traces');
    }
};

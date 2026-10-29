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
        Schema::connection($this->getConnection())->create('lantern_plan_samples', function (Blueprint $table) {
            $table->string('shape', 64);
            $table->unsignedInteger('in_size');
            $table->unsignedInteger('samples')->default(1);
            $table->double('avg_ms')->default(0);
            $table->string('plan_table', 191)->nullable();
            $table->string('plan_key', 191)->nullable();
            $table->unsignedBigInteger('plan_rows')->nullable();
            $table->boolean('full_scan')->default(false);
            $table->dateTime('explained_at', 3)->nullable();
            $table->dateTime('updated_at', 3);

            $table->primary(['shape', 'in_size']);
            $table->index('updated_at');
        });
    }

    public function down(): void
    {
        Schema::connection($this->getConnection())->dropIfExists('lantern_plan_samples');
    }
};

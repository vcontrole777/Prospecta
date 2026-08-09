<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Quatro linhas: dialer, worker, fastagi, painel. Fora de `configuracao`
        // de propósito, para não criar contenção com a leitura da config do
        // tronco.
        Schema::create('heartbeat', function (Blueprint $t) {
            $t->string('processo', 30)->primary();
            $t->timestamp('visto_em')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('heartbeat');
    }
};

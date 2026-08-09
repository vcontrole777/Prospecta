<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Lista de bloqueio GLOBAL. Quem pediu para não ser contatado pediu ao
        // negócio, não à Campanha — aplicar por Campanha faria a próxima voltar
        // a ligar. Verificada também no momento da discagem, porque alguém pode
        // entrar nela depois de o arquivo ser importado.
        Schema::create('bloqueio', function (Blueprint $t) {
            $t->string('telefone', 15)->primary();
            $t->foreignId('ligacao_id')->nullable()->constrained('ligacao')->nullOnDelete();
            $t->enum('origem', ['opt_out', 'manual']);
            $t->timestamp('criado_em')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bloqueio');
    }
};

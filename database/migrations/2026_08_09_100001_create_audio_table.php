<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Biblioteca de Áudios. IMUTÁVEL: não se substitui o arquivo de um Áudio.
        // Por isso o CDR sempre explica o que o Lead ouviu — auditabilidade de
        // graça, sem versionar. Exclusão é LÓGICA: `ativo = 0` tira da lista e
        // mantém o arquivo, porque apagar destrói o rastro de Ligações passadas.
        // Conversão para 8 kHz mono alaw acontece no UPLOAD, nunca na ligação.
        Schema::create('audio', function (Blueprint $t) {
            $t->id();
            $t->string('nome');
            $t->string('arquivo');
            $t->unsignedInteger('duracao_seg')->nullable();
            $t->boolean('ativo')->default(true);
            $t->timestamp('criado_em')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audio');
    }
};

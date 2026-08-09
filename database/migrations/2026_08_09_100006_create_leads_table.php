<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // NOME NO PLURAL, contra o padrão singular das outras tabelas, e é
        // deliberado: `LEAD` é PALAVRA RESERVADA no MySQL 8.0+ (função de
        // janela). O Laravel sempre põe acento grave, mas o `fastagi` usa PDO
        // direto por decisão do ADR-0001 — toda consulta escrita à mão seria
        // uma mina, no caminho da chamada.
        // O termo de domínio continua **Lead** (CONTEXT.md): nome de tabela é
        // preocupação física, não de vocabulário. E `contato` está na lista de
        // termos a evitar do glossário, então não serve de alternativa.
        Schema::create('leads', function (Blueprint $t) {
            $t->id();
            $t->foreignId('campanha_id')->constrained('campanha')->cascadeOnDelete();

            // Canônico: só dígitos com DDI — 5511987654321. Normalizado UMA VEZ
            // na importação, nunca na discagem. É o que faz a comparação com a
            // lista de bloqueio funcionar.
            $t->string('telefone', 15);

            $t->json('dados_extras')->nullable();   // objeto com chave de cabeçalho

            // 1 disponível | 2 em processamento | 3 finalizado | 4 aguardando rodada
            $t->unsignedTinyInteger('status')->default(1);

            // Incrementado ANTES de originar. `503` CONSOME tentativa: medido que
            // ele também vem para número ruim, então isentá-lo faria número ruim
            // ser rediscado para sempre. `404` aposenta na hora.
            $t->unsignedTinyInteger('try')->default(0);

            $t->string('audio_gerado')->nullable();  // TTS sintetizado sob demanda
            $t->timestamp('criado_em')->useCurrent();

            $t->unique(['campanha_id', 'telefone']);
            $t->index(['campanha_id', 'status']);   // <- a Fila de discagem
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leads');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campanha', function (Blueprint $t) {
            $t->id();
            $t->enum('tipo', ['opt_in', 'captura']);   // mutuamente exclusivos
            $t->unsignedInteger('rodada_atual')->default(0);

            // ---- Editáveis com a Campanha rodando -------------------------
            $t->string('nome');
            $t->string('bina', 15)->nullable();   // usada em bina_regra='unica' e
                                                  // como fallback de 'lista'

            // 'unica' e NÃO 'fixa': "fixa" estava sobrecarregada — significava
            // "não muda" numa regra e "não móvel" na outra. Em telefonia
            // brasileira "fixo" é telefone fixo, então o outro sentido cedeu.
            $t->enum('bina_regra', ['unica', 'lista', 'espelho', 'espelho_fixo'])
              ->default('unica');

            $t->unsignedSmallInteger('chamadas_simultaneas')->default(1);

            // ---- Travados ao sair de 'rascunho' ---------------------------
            $t->unsignedTinyInteger('max_tentativas')->default(3);
            $t->unsignedTinyInteger('tentativas_ura')->default(2);
            $t->enum('canal_opt_in', ['whatsapp', 'sms'])->nullable();
            $t->char('tecla_opt_in', 1)->nullable();
            $t->char('tecla_opt_out', 1)->nullable();
            $t->json('colunas')->nullable();          // cabeçalhos do arquivo importado
            $t->text('texto_mensagem')->nullable();
            $t->text('texto_sms')->nullable();

            $t->foreignId('audio_mensagem_id')->nullable()->constrained('audio');
            $t->foreignId('audio_confirma_opt_in_id')->nullable()->constrained('audio');
            $t->foreignId('audio_confirma_opt_out_id')->nullable()->constrained('audio');
            $t->foreignId('audio_nao_entendi_id')->nullable()->constrained('audio');

            $t->string('template_nome')->nullable();
            $t->string('template_idioma', 10)->nullable();
            $t->json('template_mapa')->nullable();    // {"1":"Nome do Lead"}
            $t->string('fluxo')->nullable();          // roteia o bot sem depender de id

            // 0 rascunho | 1 ativa | 2 pausada | 3 concluída
            $t->unsignedTinyInteger('status')->default(0);
            $t->timestamp('criada_em')->useCurrent();
            $t->timestamp('iniciada_em')->nullable();

            $t->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campanha');
    }
};

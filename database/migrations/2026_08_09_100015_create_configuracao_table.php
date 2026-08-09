<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // LINHA ÚNICA. Não é multi-tenant: cada cliente tem sua instância.
        Schema::create('configuracao', function (Blueprint $t) {
            $t->unsignedTinyInteger('id')->primary()->default(1);

            // Tronco SIP. O painel NÃO valida esta configuração — o operador
            // digita certo. Senha CIFRADA em repouso, exibida MASCARADA.
            $t->string('tronco_ip')->nullable();
            $t->string('tronco_user')->nullable();
            $t->text('tronco_password')->nullable();
            $t->string('tronco_from_user')->nullable();
            $t->string('tronco_from_domain')->nullable();
            $t->string('tronco_tech_prefix', 10)->nullable();

            // Horário de Trabalho é config GLOBAL, como a gravação.
            $t->time('horario_inicio')->nullable();
            $t->time('horario_fim')->nullable();
            $t->json('dias_semana')->nullable();

            $t->boolean('classificador_pre_ativo')->default(true);
            $t->boolean('gravacao_ativa')->default(true);

            // 15 dias em regime dá ~21 GB/mês por cliente em GSM. "Gravar tudo"
            // só é sustentável comprimido: WAV são 41 GB/dia em 200 canais.
            $t->unsignedSmallInteger('gravacao_retencao_dias')->default(15);

            $t->foreignId('audio_amd_id')->nullable()->constrained('audio');
            $t->foreignId('audio_espera_id')->nullable()->constrained('audio');
            $t->foreignId('audio_mensagem_generica_id')->nullable()->constrained('audio');

            $t->foreignId('integracao_tts_id')->nullable()->constrained('integracao');
            $t->foreignId('integracao_whatsapp_id')->nullable()->constrained('integracao');
            $t->foreignId('integracao_sms_id')->nullable()->constrained('integracao');
            $t->foreignId('integracao_automacao_id')->nullable()->constrained('integracao');

            $t->timestamp('atualizado_em')->useCurrent()->useCurrentOnUpdate();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('configuracao');
    }
};

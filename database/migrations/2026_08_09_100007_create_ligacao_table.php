<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // O `id` desta tabela É o ChannelId do Asterisk, definido por nós no
        // Originate. Por isso a linha é gravada ANTES de originar: linha sem
        // chamada é reconciliável, chamada sem linha é invisível.
        // Consequência boa: o caminho da gravação deriva de um inteiro, o que
        // fecha uma classe inteira de injeção (ADR-0003).
        Schema::create('ligacao', function (Blueprint $t) {
            $t->id();

            // NULLABLE: ligação manual ou de agente de IA não pertence a Campanha.
            $t->foreignId('lead_id')->nullable()->constrained('leads')->nullOnDelete();
            $t->foreignId('campanha_id')->nullable()->constrained('campanha')->nullOnDelete();

            $t->unsignedTinyInteger('numero_tentativa')->default(1);
            $t->timestamp('iniciada_em')->useCurrent();
            $t->timestamp('atendida_em')->nullable();
            $t->timestamp('encerrada_em')->nullable();
            $t->unsignedInteger('duracao_seg')->nullable();
            $t->unsignedInteger('duracao_falada_seg')->nullable();

            // TechCause do DialEnd — exige Asterisk >= 22.7.0. Medidos nesta rota:
            // 404 número inexistente (permanente, aposenta o Lead)
            // 480 desligado/fora de área (temporário, retenta)
            // 486 NÃO PERTURBE — não ocupado, ao contrário do nome
            // 487 nosso próprio CANCEL por timeout de toque
            // 503 quatro causas distintas; o mais sobrecarregado da rota
            $t->string('codigo_sip', 60)->nullable();
            $t->string('hangup_cause', 60)->nullable();

            $t->enum('amd_status', ['MACHINE', 'HUMAN', 'NOTSURE', 'HANGUP'])->nullable();
            $t->string('amd_cause', 60)->nullable();   // INITIALSILENCE-<dur>-<lim> etc.

            $t->enum('desfecho', [
                'opt_in', 'opt_out', 'sem_resposta', 'maquina', 'nao_atendeu',
                'invalido', 'erro', 'captura_concluida', 'captura_parcial',
            ])->nullable();

            $t->json('respostas')->nullable();     // {"cpf":"...","matricula":"..."}
            $t->char('tecla_digitada', 1)->nullable();
            $t->foreignId('integracao_tts_id')->nullable()->constrained('integracao');

            // A BINA SOLICITADA, não a entregue. O fornecedor pode substituir e
            // não há como saber do nosso lado. NULL significa "não pedimos nada,
            // o fornecedor definiu" — e a tela deve dizer isso, não mostrar vazio.
            $t->string('bina', 15)->nullable();

            // MixMonitor, alaw 8 kHz, a partir do ANSWER. NULL quando não há.
            // Verificar que o arquivo EXISTE depois da Ligação: com o diretório
            // sem permissão o MixMonitor erra no log do Asterisk e a chamada
            // segue normal, deixando aqui um caminho que não existe.
            $t->string('gravacao')->nullable();

            $t->index(['campanha_id', 'desfecho']);
            $t->index('lead_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ligacao');
    }
};

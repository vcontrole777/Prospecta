<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // TUDO que o sistema faz para fora, num mecanismo só (ADR-0002).
        // Nenhuma chamada HTTP acontece dentro da Ligação: um webhook pendurado
        // deixaria o Lead ouvindo silêncio por até 60 s.
        Schema::create('acao', function (Blueprint $t) {
            $t->id();

            // NÃO existe notificação ao dono: o resultado da captura é
            // acompanhado no painel, não entregue por mensagem.
            // 🔴 SÓ `disparo` É ESCRITO HOJE. Os outros cinco são intenção registrada,
            // não caminho existente — nada os grava e nada os consome. O enum fica largo
            // porque estreitá-lo custaria uma migration agora e outra quando a integração
            // de saída chegar; mas quem lê esta lista não deve concluir que esses tipos
            // circulam. `evento_captura_concluida` chegou a ser escrito e ficava
            // `pendente` para sempre, porque `FilaDeAcoes` só reivindica `disparo`.
            $t->enum('tipo', [
                'disparo', 'consulta_cpf', 'evento_opt_in', 'evento_opt_out',
                'evento_ligacao_encerrada', 'evento_captura_concluida',
            ]);

            $t->unsignedBigInteger('referencia_id');   // normalmente ligacao_id
            $t->json('payload')->nullable();

            // entregue/lido/tocado vêm do WEBHOOK, não do envio.
            // 'pausado' vem do próprio envio: HTTP 200 com message_status
            // 'paused' NÃO é entrega normal.
            // 'limite_destinatario' é o erro 131049 — decisão de entrega da
            // plataforma, não falha técnica. Retentar não resolve.
            $t->enum('status', [
                'pendente', 'enviado', 'entregue', 'lido', 'tocado',
                'falhou', 'limite_destinatario', 'pausado',
            ])->default('pendente');

            $t->string('wamid')->nullable();   // prefixo 'wamid.'
            $t->unsignedTinyInteger('tentativas')->default(0);
            $t->text('resposta')->nullable();
            $t->timestamp('criado_em')->useCurrent();
            $t->timestamp('processado_em')->nullable();

            // Idempotência para TODA Ação, no esquema e não no código.
            // O worker reivindica com UPDATE ... WHERE status='pendente' e
            // confere linhas afetadas — nunca ler-depois-escrever.
            $t->unique(['tipo', 'referencia_id']);
            $t->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('acao');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Mensagens prontas para a caixa de entrada.
     *
     * 🔴 TABELA, e não coluna `json` na `configuracao`. A migration do Pós-atendimento
     * escolheu colunas discretas em vez de um `json` porque lá era a comparação ENTRE
     * campos que pegava o defeito. Aqui a razão é outra e leva ao mesmo lugar: isto é
     * uma LISTA de N itens com ordem e edição individual, e lista em `json` obriga a
     * reescrever o documento inteiro para mexer numa linha — o que perde alteração
     * concorrente sem avisar.
     *
     * 🔴 NÃO GUARDA A QUEM FOI ENVIADA. A resposta rápida só preenche a caixa; quem
     * envia é o `Conversas::responder`, que já existe e já grava em `conversa_mensagem`
     * e `acao`. Sem tabela de junção, sem contador de uso: uma resposta rápida é um
     * texto guardado, não um evento.
     */
    public function up(): void
    {
        Schema::create('resposta_rapida', function (Blueprint $t): void {
            $t->id();

            /* O rótulo do botão. Curto de propósito: é o que cabe na tela. */
            $t->string('titulo', 60);

            /*
             * O texto que vai para a caixa.
             *
             * Pode conter Placeholder `{Nome da Coluna}`, resolvido pela mesma classe
             * que o `fastagi` usa. 4000 é o limite que `Conversas::responder` já
             * valida — o WhatsApp corta acima de 4096, e guardar mais do que dá para
             * enviar seria oferecer o que não sai.
             */
            $t->text('texto');

            /*
             * Ordem na tela, escolhida pelo operador.
             *
             * Sem isto a ordem seria a de inserção, e a resposta mais usada acabaria no
             * fim depois de qualquer cadastro novo.
             */
            $t->unsignedSmallInteger('ordem')->default(0);

            /*
             * Desativar em vez de apagar: uma resposta sazonal volta, e apagar obriga a
             * digitar tudo de novo. É a mesma escolha que `integracao.ativo` faz.
             */
            $t->boolean('ativo')->default(true);

            $t->timestamp('criado_em')->useCurrent();

            $t->index(['ativo', 'ordem']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('resposta_rapida');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Botão de link na resposta rápida.
     *
     * 🔴 `cta_url` É O ÚNICO BOTÃO QUE NÃO EXIGE MEXER NA ENTRADA. Botão de resposta
     * rápida devolve o toque como mensagem de entrada — e o produto até já sabe ler
     * isso (`Whatsapp\Leitura` trata `button_reply`, e o `OptOut` consome). Botão de
     * LINK não devolve nada: a pessoa toca, abre o navegador, e o WhatsApp não avisa
     * ninguém. Por isso ele é o mais barato, e é por ele que se começa.
     *
     * 🔴 SÓ DENTRO DA JANELA DE 24 H, como todo o resto desta tela. Fora dela a Meta
     * só aceita template aprovado, e template com botão é assunto do Disparo da
     * Campanha, não da caixa de entrada. Como a caixa de resposta só é renderizada
     * com a janela aberta, isso já se resolve sozinho.
     *
     * As colunas são anuláveis e andam em PAR: as duas preenchidas mandam mensagem
     * interativa, as duas vazias mandam texto puro. Uma só é estado sem significado,
     * e a tela recusa antes de gravar.
     */
    public function up(): void
    {
        Schema::table('resposta_rapida', function (Blueprint $t): void {
            /*
             * O texto DENTRO do botão.
             *
             * 20 é o teto da Meta para `display_text` do `cta_url`. Guardar mais do
             * que ela aceita seria oferecer ao operador o que não sai — a mesma razão
             * pela qual `texto` foi limitado em 4000 e não em 65535.
             */
            $t->string('botao_texto', 20)->nullable()->after('texto');

            /*
             * Para onde o botão leva. Precisa ser `https`.
             *
             * 2048 é o limite prático de URL em navegador; acima disso o problema não
             * é o banco.
             */
            $t->string('botao_url', 2048)->nullable()->after('botao_texto');
        });

        Schema::table('conversa_mensagem', function (Blueprint $t): void {
            /*
             * 🔴 O BOTÃO VIAJA NA MENSAGEM, e não numa Ação de tipo novo.
             *
             * A alternativa seria um `acao.tipo = 'resposta_interativa'`, com consulta
             * própria na fila e ramo próprio no worker. Guardando na mensagem, o
             * caminho continua sendo UM — `resposta_texto` — e o worker escolhe entre
             * texto e interativo olhando duas colunas. Menos superfície, e a
             * idempotência por `unique(tipo, referencia_id)` continua valendo sem
             * precisar cobrir um tipo a mais.
             */
            $t->string('botao_texto', 20)->nullable()->after('texto');
            $t->string('botao_url', 2048)->nullable()->after('botao_texto');
        });
    }

    public function down(): void
    {
        Schema::table('conversa_mensagem', function (Blueprint $t): void {
            $t->dropColumn(['botao_texto', 'botao_url']);
        });

        Schema::table('resposta_rapida', function (Blueprint $t): void {
            $t->dropColumn(['botao_texto', 'botao_url']);
        });
    }
};

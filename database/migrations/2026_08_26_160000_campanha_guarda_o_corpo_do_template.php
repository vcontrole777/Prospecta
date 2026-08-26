<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * O corpo aprovado do template, guardado na Campanha.
     *
     * 🔴 EXISTE PORQUE A CAIXA DE ENTRADA MOSTRAVA `(template)` E MAIS NADA.
     *
     * `registrarSaida` recebia string VAZIA como texto do Disparo — o worker tem os
     * parâmetros resolvidos, mas nunca teve o corpo do template: quem fala com a Meta
     * para isso é a tela, não ele. Resultado medido em 26/08/2026: das 243 mensagens
     * da base, 213 eram `saida/template` sem texto nenhum. O operador via que ALGO
     * foi enviado e não o quê.
     *
     * 🔴 GUARDADO NA CAMPANHA, e não buscado na hora de exibir. Reconstruir na tela
     * exigiria uma chamada à Meta por conversa aberta, e ainda assim mostraria o
     * corpo de HOJE — não o que a pessoa recebeu. Template aprovado pode ser editado
     * e reaprovado; a mensagem que saiu é histórica.
     *
     * Quem preenche é o formulário de Campanha, que JÁ carrega o corpo ao listar os
     * templates aprovados. Nenhuma chamada nova a lugar nenhum.
     *
     * Anulável: Campanha antiga não tem, e Campanha de captura ou de SMS não usa
     * template. Sem corpo, o worker grava o que sabe — o nome e os parâmetros —, que
     * ainda é melhor que vazio.
     */
    public function up(): void
    {
        Schema::table('campanha', function (Blueprint $t): void {
            $t->text('template_corpo')->nullable()->after('template_mapa');
        });
    }

    public function down(): void
    {
        Schema::table('campanha', function (Blueprint $t): void {
            $t->dropColumn('template_corpo');
        });
    }
};

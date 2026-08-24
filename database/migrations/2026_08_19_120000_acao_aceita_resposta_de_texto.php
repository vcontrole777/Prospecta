<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * `acao.tipo` ganha `resposta_texto`: a resposta que o OPERADOR escreve na tela de
     * Conversas.
     *
     * Por que passa pela fila de Ações em vez de a tela enviar direto: o `Conversas`
     * declarava-se somente-leitura justamente porque responder "exige um caminho novo —
     * um tipo de Ação para texto livre e um remetente no worker". Enviar dentro do
     * request duplicaria a resolução de credencial, a política de tentativas e o
     * mapeamento de erro que o worker já faz, e poria chamada HTTP a um terceiro dentro
     * de uma requisição web. É a mesma disciplina do ADR-0002.
     *
     * 🔴 TIPO PRÓPRIO, não `disparo` com um campo a mais. Os dois têm origem, conteúdo e
     * regra diferentes: `disparo` nasce de um Opt-in, manda TEMPLATE e é reivindicado por
     * uma consulta que junta `ligacao`, `leads` e `campanha`. `resposta_texto` nasce de
     * uma pessoa digitando, manda TEXTO LIVRE, só vale dentro da janela de 24 h e não tem
     * Ligação nenhuma para juntar. Espremer os dois no mesmo tipo faria a consulta de
     * reivindicação precisar de LEFT JOIN e de ramo condicional — e um `disparo` sem
     * Ligação passaria a ser representável.
     *
     * `referencia_id` aponta para `conversa.id` neste tipo, como aponta para `ligacao.id`
     * no `disparo`. O texto vai em `payload`, que já é JSON e já existe.
     */
    public function up(): void
    {
        DB::statement(
            "ALTER TABLE acao MODIFY COLUMN tipo
             ENUM('disparo','resposta_texto','consulta_cpf','evento_opt_in','evento_opt_out',
                  'evento_ligacao_encerrada','evento_captura_concluida') NOT NULL"
        );
    }

    public function down(): void
    {
        // Apaga as linhas do tipo antes de removê-lo do enum: com elas presentes o MySQL
        // as converteria em string vazia em silêncio, deixando registro inválido no lugar
        // de um erro.
        DB::table('acao')->where('tipo', 'resposta_texto')->delete();

        DB::statement(
            "ALTER TABLE acao MODIFY COLUMN tipo
             ENUM('disparo','consulta_cpf','evento_opt_in','evento_opt_out',
                  'evento_ligacao_encerrada','evento_captura_concluida') NOT NULL"
        );
    }
};

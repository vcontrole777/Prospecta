<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `leads.opt_in_em`: quando ESTE Lead disse sim, no próprio Lead.
     *
     * 🔴 EXISTE PORQUE REINICIAR PASSOU A APAGAR O HISTÓRICO, e sem esta coluna o
     * recurso se contradiz na segunda volta.
     *
     * Quem deu Opt-in fica de fora do reinício. A prova disso morava só em
     * `ligacao.desfecho = 'opt_in'` — a mesma tabela que o reinício agora esvazia. Primeiro
     * reinício: o Lead é reconhecido e poupado. Segundo: não há mais Ligação nenhuma, ele
     * parece nunca ter sido discado, e a Campanha liga de novo para quem já converteu.
     * O defeito não aparece no dia em que se escreve o código — aparece na segunda vez que
     * o operador clica, semanas depois, e a causa já não está à vista.
     *
     * O Opt-OUT não precisa de coluna: `bloqueio` é GLOBAL, permanente e não é tocada pelo
     * reinício. É a assimetria certa — "não me ligue" é do negócio, "eu quero" é da relação
     * com este Lead.
     *
     * Carimba a partir do histórico existente: instâncias em produção já têm Opt-in gravado
     * só em `ligacao`, e sem o preenchimento o primeiro reinício depois desta migration
     * devolveria todos eles à fila.
     */
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $t): void {
            $t->timestamp('opt_in_em')->nullable()->after('try');
        });

        // `MIN`: o primeiro sim é o que vale. Um Lead pode ter mais de uma Ligação com
        // `opt_in` — rodadas diferentes, ou a mesma pessoa apertando 1 duas vezes.
        DB::statement(<<<'SQL'
            UPDATE leads le
              JOIN (SELECT lead_id, MIN(COALESCE(encerrada_em, iniciada_em)) AS quando
                      FROM ligacao
                     WHERE desfecho = 'opt_in' AND lead_id IS NOT NULL
                     GROUP BY lead_id) o ON o.lead_id = le.id
               SET le.opt_in_em = o.quando
             WHERE le.opt_in_em IS NULL
        SQL);
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $t): void {
            $t->dropColumn('opt_in_em');
        });
    }
};

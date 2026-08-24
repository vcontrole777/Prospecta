<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * O `silêncio inicial` deixa de nascer morto: 6000 vira 3500.
     *
     * 🔴 ESTA MIGRATION MUDA COMPORTAMENTO, e é a primeira do projeto que faz isso
     * de propósito. A anterior — `configuracao_governa_o_pos_atendimento` — escreveu
     * que preservar o defeito era deliberado, "porque há uma coleta de base em
     * andamento, decidida pelo operador em 21/08/2026; trocar o parâmetro no meio
     * dela contaminaria a comparação antes/depois que a coleta existe para permitir".
     *
     * A coleta terminou. Esta migration é o depois.
     *
     * MEDIÇÃO, 24/08/2026, 8.829 Ligações de campanha real contra tronco real:
     *
     * |                                   | 6000 (19–22/08) | 3500 (24/08) |
     * |-----------------------------------|-----------------|--------------|
     * | atendida sem decisão (`NOTSURE`)  | 9.146 — 55,0%   | 61 — 1,9%    |
     * | causa `INITIALSILENCE`            | ZERO            | 1.638        |
     * | interação por atendida            | 0,92%           | 1,01%        |
     *
     * O critério que não podia disparar voltou a ser o que mais decide. E não cobrou
     * o preço que dava para temer: se estivéssemos matando humano como máquina, a
     * taxa de interação entre as atendidas CAIRIA — ela subiu. `sem_resposta` foi de
     * 18,2% para 3,1% dos desfechos.
     *
     * 🔴 POR QUE 3500 E NÃO O 2500 DO `amd.conf`. Porque 3500 é o que foi medido
     * nesta rota. O 2500 do fornecedor é mais agressivo e não tem medição aqui que o
     * ampare; trocar o número por outro não medido repetiria o erro que esta
     * migration está desfazendo.
     *
     * 🔴 O TESTE VAI QUEBRAR, E A QUEBRA É O ACEITE.
     * `PosAtendimentoTest::o_conjunto_de_fabrica_nasce_com_um_criterio_morto` foi
     * escrito para falhar exatamente aqui — para que corrigir o default fosse uma
     * decisão tomada, e não um efeito colateral de alguém mexendo em outra coisa. Ele
     * é substituído no mesmo commit, e o que entra no lugar afirma o contrário: que
     * o conjunto de fábrica nasce VIVO.
     */
    public function up(): void
    {
        Schema::table('configuracao', function (Blueprint $t): void {
            $t->unsignedSmallInteger('pos_silencio_inicial_ms')->default(3500)->change();
        });

        /*
         * 🔴 SÓ AS LINHAS AINDA DOENTES, e só onde 3500 realmente cura.
         *
         * `WHERE >= analise_total` porque um operador pode ter escolhido um valor
         * diferente e VÁLIDO na tela; sobrescrevê-lo seria a migration desfazendo
         * uma decisão humana em silêncio, que é o oposto do que ela se propõe.
         *
         * `AND analise_total > 3500` porque numa instância com orçamento apertado
         * o 3500 continuaria morto. Essas linhas ficam como estão e a tela segue
         * denunciando — chutar um número para elas seria trocar um defeito visível
         * por um invisível.
         */
        DB::table('configuracao')
            ->whereColumn('pos_silencio_inicial_ms', '>=', 'pos_analise_total_ms')
            ->where('pos_analise_total_ms', '>', 3500)
            ->update(['pos_silencio_inicial_ms' => 3500]);
    }

    /**
     * Volta o DEFAULT para 6000, e só ele.
     *
     * As linhas corrigidas no `up` ficam em 3500: o valor anterior de cada uma não
     * foi guardado, e reverter para 6000 no atacado inventaria um passado que talvez
     * não tenha existido. `down` que restaura esquema sem restaurar dados é honesto;
     * `down` que adivinha dados, não.
     */
    public function down(): void
    {
        Schema::table('configuracao', function (Blueprint $t): void {
            $t->unsignedSmallInteger('pos_silencio_inicial_ms')->default(6000)->change();
        });
    }
};

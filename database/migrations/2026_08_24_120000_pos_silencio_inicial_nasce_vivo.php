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
     * MEDIÇÃO, 24/08/2026, contra tronco real. A MESMA Campanha e a MESMA lista dos
     * dois lados — a `INSS_nova`, discada antes e depois da troca. Comparar com o
     * período inteiro daria números melhores e mentiria: as outras Campanhas do dia
     * 19 a 21 tinham listas diferentes, com propensão diferente a atender e a
     * interagir.
     *
     * |                                    | 6000    | 3500     |
     * |------------------------------------|---------|----------|
     * | atendidas na amostra               | 5.327   | 15.135   |
     * | atendida sem decisão (`NOTSURE`)   | 54,9%   | **2,1%** |
     * | causa `INITIALSILENCE`             | ZERO    | 7.812    |
     * | causa `TOOLONG` (estourou o prazo) | 2.927   | 325      |
     * | classificadas `HUMAN`              | 19,7%   | 21,6%    |
     * | atendida que ficou sem desfecho    | 71,1%   | 20,5%    |
     * | interação por atendida             | 0,64%   | 0,63%    |
     *
     * O critério que não podia disparar voltou a ser o que mais decide, e as 2.927
     * que estouravam o relógio como `TOOLONG` viraram decisão.
     *
     * 🔴 E A LINHA QUE IMPORTA PARA A SEGURANÇA É A DO `HUMAN`, não a da interação.
     *
     * O risco desta troca é falso-MÁQUINA: desligar na cara de quem atendeu. Se
     * estivesse acontecendo, a fatia classificada como `HUMAN` ENCOLHERIA — e ela
     * cresceu, de 19,7% para 21,6%. É a evidência com amostra grande.
     *
     * A interação por atendida ficou ESTÁVEL (0,64% → 0,63%), o que confirma sem
     * contradizer, mas ela não sustenta conclusão sozinha: são 34 e 96 eventos. Uma
     * leitura anterior desta migration afirmou, sobre amostra menor e Campanhas
     * misturadas, que a interação tinha SUBIDO. Não subiu; ficou igual. O registro
     * fica aqui porque número que se corrige calado volta a ser citado errado.
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

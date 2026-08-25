<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Os dois parâmetros que declaravam MÁQUINA quem tinha atendido.
     *
     * `pos_palavras_max`        3    → 6
     * `pos_silencio_inicial_ms` 3500 → 4500
     *
     * 🔴 QUEM ACHOU FOI O OPERADOR, OUVINDO GRAVAÇÃO, e o método importa mais que
     * o número.
     *
     * Em 24/08/2026 ele disse que várias chamadas de humano estavam sendo
     * classificadas como máquina. Os agregados que existiam diziam o contrário: a
     * fatia `HUMAN` tinha SUBIDO (19,7% → 21,6%) e a interação por atendida estava
     * estável (0,64% → 0,63%). Nenhum dos dois responde a pergunta. `HUMAN` mede
     * quem o classificador ACERTOU, não quem ele matou — as duas coisas sobem
     * juntas. E quem é desligado aos 3,6 s não tinha como interagir, então a
     * interação não podia denunciar nada. Para falso-MÁQUINA o instrumento é a
     * gravação; a consulta serve para dimensionar depois que se sabe o que procurar.
     *
     * Dimensionado: das 12.934 Ligações classificadas MÁQUINA naquela tarde, o
     * veredito tinha duas causas e só duas.
     *
     * | causa                      | n     | o que significa                     |
     * |----------------------------|-------|-------------------------------------|
     * | `INITIALSILENCE-3500-3500` | 8.988 | sem som em 3,5 s                    |
     * | `MAXWORDS-4-3`             | 3.931 | falou 4 palavras, o teto era 3      |
     *
     * 🔴 `MAXWORDS` É O CASO FECHADO. "Alô, quem tá falando?" são quatro palavras;
     * "alô, bom dia, quem fala?" são cinco. Secretária eletrônica diz dez, quinze.
     * O teto estava ABAIXO do comprimento normal de uma saudação humana em
     * português — não é ajuste fino, é a régua no lugar errado. 6 cabe nas duas e
     * continua separando com folga. `PosAtendimentoTest` fixa isso com as frases.
     *
     * ⚠️ `INITIALSILENCE` É APOSTA MEDIDA PELA METADE, e vai declarado como tal.
     * Que 3,5 s corta gente que demora uma batida para atender é plausível e casa
     * com o relato; que 4,5 s seja o número certo, não se sabe. Parte dos 8.988 é
     * secretária de verdade, com silêncio inicial antes da saudação, e afrouxar
     * demais faz a Mensagem tocar para caixa postal gastando tronco. 4500 segue
     * abaixo dos 5000 de `análise total`, então não recria o critério morto que a
     * migration anterior consertou. Confirmar ou corrigir É POR GRAVAÇÃO.
     *
     * 🔴 OS DOIS DEFAULTS DO `amd.conf` DO ASTERISK PERDERAM AQUI — o `3` agora e o
     * `initial_silence` na migration anterior. O padrão do fornecedor é ponto de
     * partida, não prova: foi medido contra esta rota e este idioma, e não passou.
     */
    public function up(): void
    {
        Schema::table('configuracao', function (Blueprint $t): void {
            $t->unsignedTinyInteger('pos_palavras_max')->default(6)->change();
            $t->unsignedSmallInteger('pos_silencio_inicial_ms')->default(4500)->change();
        });
    }

    /*
     * 🔴 SEM `UPDATE` NAS LINHAS EXISTENTES, e a assimetria com a migration anterior
     * é deliberada.
     *
     * `pos_silencio_inicial_nasce_vivo` corrigiu linhas gravadas porque lá havia um
     * teste OBJETIVO de defeito: `silêncio inicial >= análise total` é um critério
     * que não pode disparar nunca, o que se demonstra sem opinião.
     *
     * Aqui não há invariante equivalente. Nem `3` nem `3500` são impossíveis — são
     * APERTADOS, e a conclusão vem de uma medição desta rota e deste idioma, não de
     * uma contradição interna. Reescrever a escolha de outra instalação com base
     * numa medição que não é dela seria a migration decidindo no lugar do operador.
     *
     * O efeito da omissão é pequeno: instalação nova nasce nos valores novos, e a
     * instalação que motivou isto já foi ajustada na tela em 24/08/2026. Quem
     * estiver nos valores antigos e sofrer o mesmo verá `MAXWORDS` ou
     * `INITIALSILENCE` na coluna de causa, que é onde o sintoma aparece.
     */

    public function down(): void
    {
        Schema::table('configuracao', function (Blueprint $t): void {
            $t->unsignedTinyInteger('pos_palavras_max')->default(3)->change();
            $t->unsignedSmallInteger('pos_silencio_inicial_ms')->default(3500)->change();
        });
    }
};

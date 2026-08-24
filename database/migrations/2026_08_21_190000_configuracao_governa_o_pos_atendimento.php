<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * O Pós-atendimento passa a ser CONFIGURÁVEL — chave e parâmetros.
     *
     * 🔴 ERA INALCANÇÁVEL, e custou caro. O classificador rodava em toda Ligação
     * atendida a partir de um único ponto sem condicional (`Fluxo::classificar`), com os
     * nove parâmetros fixos no default de `Dados::$amd`. Nem o operador nem o SQL podiam
     * tocar neles.
     *
     * O preço, medido em 21/08/2026 sobre 9.146 Ligações: `initial_silence` estava em
     * 6000 ms e `analise_total` em 5000 ms — o critério do silêncio inicial exigia mais
     * tempo do que o orçamento inteiro da análise, então NUNCA podia disparar. Em 8.406
     * classificações, a causa `INITIALSILENCE` apareceu ZERO vezes. Sobrava o
     * classificador decidindo só por MAXWORDS ou HUMAN, e todo o resto estourava o
     * relógio: 54,6% das atendidas voltavam NOTSURE, gastando 12,7 horas de tronco em
     * análises que não concluíram nada.
     *
     * 🔴 COLUNAS DISCRETAS, não um `json` com os nove. O `json` seria uma coluna só, e
     * pagaria com a validação: é a comparação ENTRE campos que pega o defeito acima, e a
     * tela só consegue recusar `silêncio inicial >= análise total` se os dois forem
     * campos de verdade. Guardar como blob transformaria de novo em falha calada aquilo
     * que esta migration existe para tornar impossível.
     *
     * 🔴 `configuracao`, não `campanha`. Duas razões. O `Banco::carregar` já faz
     * `LEFT JOIN configuracao g ON g.id = 1` para ler `gravacao_ativa`, então ler mais
     * dez colunas do mesmo join custa zero consulta na FASE 1 — que é a fase que o
     * ADR-0001 manda manter barata. E o comportamento é da infraestrutura, não da oferta:
     * se o classificador acerta depende do áudio da rota, do codec e da mídia antecipada,
     * não do que a Campanha propõe. Mesma natureza de `gravacao_ativa`.
     *
     * 🔴 `pos_` e não `amd_`. O CONTEXT.md lista `AMD` como termo a EVITAR — o nome do
     * domínio é Pós-atendimento. Uma coluna `amd_ativo` reintroduziria no esquema a
     * palavra que o vocabulário tirou de circulação, que foi exatamente o erro corrigido
     * quando `Tronco SIP` virou `Rota`.
     *
     * 🔴 OS DEFAULTS PRESERVAM O COMPORTAMENTO DE HOJE, INCLUSIVE O DEFEITO — e isso é
     * deliberado. `pos_silencio_inicial_ms` nasce em 6000, não no 2500 do `amd.conf`.
     *
     * Duas razões. Migration que muda comportamento no instante em que roda é migration
     * que mente sobre o que faz: quem aplica espera esquema novo, não discagem diferente.
     * E há uma coleta de base em andamento, decidida pelo operador em 21/08/2026 — trocar
     * o parâmetro no meio dela contaminaria a comparação antes/depois que a coleta existe
     * para permitir.
     *
     * Consertar o 6000 passa a ser um ato do operador, na tela, com a validação cruzada
     * recusando o valor inválido e dizendo por quê. É a diferença entre corrigir e
     * corrigir às escondidas.
     */
    public function up(): void
    {
        Schema::table('configuracao', function (Blueprint $t): void {
            $t->boolean('classificador_pos_ativo')
                ->default(true)
                ->after('classificador_pre_ativo');

            // Ordem posicional do `AMD()` do Asterisk, preservada aqui para que a
            // montagem em `Banco::carregar` seja legível ao lado da documentação dele.
            $t->unsignedSmallInteger('pos_silencio_inicial_ms')->default(6000)->after('classificador_pos_ativo');
            $t->unsignedSmallInteger('pos_saudacao_max_ms')->default(4000)->after('pos_silencio_inicial_ms');
            $t->unsignedSmallInteger('pos_silencio_apos_saudacao_ms')->default(800)->after('pos_saudacao_max_ms');
            $t->unsignedSmallInteger('pos_analise_total_ms')->default(5000)->after('pos_silencio_apos_saudacao_ms');
            $t->unsignedSmallInteger('pos_palavra_min_ms')->default(100)->after('pos_analise_total_ms');
            $t->unsignedSmallInteger('pos_silencio_entre_palavras_ms')->default(50)->after('pos_palavra_min_ms');
            $t->unsignedTinyInteger('pos_palavras_max')->default(3)->after('pos_silencio_entre_palavras_ms');
            $t->unsignedSmallInteger('pos_limiar_silencio')->default(256)->after('pos_palavras_max');
            $t->unsignedSmallInteger('pos_palavra_max_ms')->default(5000)->after('pos_limiar_silencio');
        });
    }

    public function down(): void
    {
        Schema::table('configuracao', function (Blueprint $t): void {
            $t->dropColumn([
                'classificador_pos_ativo',
                'pos_silencio_inicial_ms',
                'pos_saudacao_max_ms',
                'pos_silencio_apos_saudacao_ms',
                'pos_analise_total_ms',
                'pos_palavra_min_ms',
                'pos_silencio_entre_palavras_ms',
                'pos_palavras_max',
                'pos_limiar_silencio',
                'pos_palavra_max_ms',
            ]);
        });
    }
};

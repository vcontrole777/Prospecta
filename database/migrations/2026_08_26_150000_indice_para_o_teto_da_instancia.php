<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Índice para o teto da instância — e ele conserta uma regressão MEDIDA.
     *
     * 🔴 O TETO DA INSTÂNCIA PÔS UM SCAN COMPLETO NO LAÇO QUENTE.
     *
     * `contarOcupadosNaInstancia` faz `COUNT(*) ... WHERE encerrada_em IS NULL`, e a
     * `ligacao` não tinha índice nessa coluna. O `contarOcupados` por Campanha nunca
     * sofreu porque filtra por `campanha_id`, que é indexado; o global varria a
     * tabela inteira, uma vez POR VOLTA do discador.
     *
     * Medido em 26/08/2026, minutos depois do deploy: a vazão caiu de ~6,7 CPS para
     * 1,9 — o `EXPLAIN` mostrava `type: ALL` sobre 212.665 linhas. Com o índice o
     * plano vira `type: ref` sobre 55.
     *
     * 🔴 E O CUSTO CRESCE COM O HISTÓRICO, que é o que torna isso traiçoeiro: em
     * desenvolvimento, com mil Ligações, o scan é instantâneo e nada aparece. O
     * defeito só nasce quando a tabela cresce — exatamente onde ninguém está olhando.
     *
     * O índice é sobre a coluna inteira e não parcial: o MySQL não tem índice
     * parcial, e não faz falta — as linhas com `encerrada_em` nula são poucas por
     * construção (são as chamadas em voo), então a busca por NULL toca um punhado
     * de entradas.
     */
    public function up(): void
    {
        Schema::table('ligacao', function (Blueprint $t): void {
            $t->index('encerrada_em');
        });
    }

    public function down(): void
    {
        Schema::table('ligacao', function (Blueprint $t): void {
            $t->dropIndex(['encerrada_em']);
        });
    }
};

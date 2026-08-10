<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 🔴 A LINHA ÚNICA DE `configuracao` TEM QUE EXISTIR, e nada a criava.
 *
 * A tabela é singleton — `id tinyint default 1`, uma instância por cliente — e o
 * esquema inteiro assume que ela está lá. O `fastagi` faz
 * `CROSS JOIN configuracao` para ler `gravacao_ativa`, e CROSS JOIN com tabela
 * VAZIA devolve ZERO linhas. Consequência medida numa instância recém-migrada:
 *
 *   · a consulta de `Banco::carregar` devolve 0 linhas
 *   · ela lança "Ligação {id} não encontrada" — e a Ligação EXISTE
 *   · TODA chamada falha, e a mensagem manda investigar a Ligação
 *
 * Ou seja: uma VPS nova, provisionada e implantada corretamente, com Campanha
 * ativa e Lead importado, falhava em toda chamada por causa de uma linha ausente
 * — e nada no erro apontava para ela.
 *
 * Semear numa migration e não num seeder porque isto não é dado de exemplo: é
 * pressuposto estrutural do esquema, no mesmo nível de uma coluna existir. Seeder
 * é opcional por natureza, e este pressuposto não é.
 */
return new class extends Migration
{
    public function up(): void
    {
        // `insertOrIgnore`: a migration roda em banco novo e em banco que já tem a
        // linha inserida à mão. Inserção que falha por chave duplicada aqui não é
        // erro — é a linha já estar onde deveria.
        DB::table('configuracao')->insertOrIgnore(['id' => 1]);
    }

    public function down(): void
    {
        // Não remove. A linha é pressuposto do esquema, e uma reversão que a apagasse
        // deixaria a base num estado em que toda chamada falha — o mesmo estado que
        // esta migration existe para consertar.
    }
};

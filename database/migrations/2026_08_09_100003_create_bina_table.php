<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Pool GLOBAL de BINAs, como a lista de bloqueio e o Tronco SIP.
        // Usado só por campanha.bina_regra = 'lista'.
        Schema::create('bina', function (Blueprint $t) {
            $t->id();
            $t->string('numero', 15)->unique();   // 5511987654321 ou 551133334444

            // Coluna GERADA. Resolve a objeção contra guardar `ddd`: gerada pelo
            // MySQL não pode divergir do número, e ao contrário da expressão
            // solta É INDEXÁVEL — sem ela, WHERE SUBSTRING(numero,3,2) = ?
            // varre a tabela inteira.
            // Fixo ou móvel NÃO é coluna: sai de LENGTH(numero), 13 móvel e
            // 12 fixo. Calculado para exibir, não armazenado.
            $t->char('ddd', 2)->storedAs('SUBSTRING(numero,3,2)');

            $t->boolean('ativo')->default(true);
            $t->timestamp('criado_em')->useCurrent();

            // Seleção por ORDER BY RAND(), seguro AQUI porque a tabela é pequena.
            $t->index(['ativo', 'ddd']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bina');
    }
};

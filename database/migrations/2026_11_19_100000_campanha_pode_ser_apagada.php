<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Apagar Campanha é PEDIDO, não ato.
     *
     * O painel marca `apagar_pedido_em` e `bin/apagar-campanhas.php` apaga em lotes, fora
     * da requisição — ver `App\Worker\ApagadorDeCampanha` para o porquê, com os números.
     * Enquanto a marca existe a Campanha não pode ir ao ar, e a tela a mostra como
     * "apagando".
     *
     * `apagar_erro` é o que a tela mostra quando a volta não terminou por um motivo que
     * pede ação do operador — hoje, gravação que não saiu do disco. Sem esta coluna, a
     * Campanha ficaria "apagando" para sempre, e o motivo só existiria no journal.
     */
    public function up(): void
    {
        Schema::table('campanha', function (Blueprint $t): void {
            $t->timestamp('apagar_pedido_em')->nullable()->after('status');
            $t->string('apagar_erro', 500)->nullable()->after('apagar_pedido_em');
        });
    }

    public function down(): void
    {
        Schema::table('campanha', function (Blueprint $t): void {
            $t->dropColumn(['apagar_pedido_em', 'apagar_erro']);
        });
    }
};

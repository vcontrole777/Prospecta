<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Reiniciar Campanha passa a ser PEDIDO, como apagar.
     *
     * O painel marca `reiniciar_pedido_em` e `php artisan prospecta:campanhas-reiniciar`
     * faz o trabalho em lotes — ver `App\Campanha\Reinicio`, com os números que fizeram o
     * reinício dentro do clique morrer no meio.
     *
     * `reiniciar_aviso` é o recado do último reinício: quantos números voltaram, quanto
     * foi apagado, e se a Campanha foi ao ar ou por que não. Antes ele ia para a sessão,
     * na mesma requisição; agora o trabalho termina sem ninguém olhando, e o recado
     * precisa esperar o operador na linha da Campanha.
     */
    public function up(): void
    {
        Schema::table('campanha', function (Blueprint $t): void {
            $t->timestamp('reiniciar_pedido_em')->nullable()->after('apagar_erro');
            $t->string('reiniciar_aviso', 1000)->nullable()->after('reiniciar_pedido_em');
        });
    }

    public function down(): void
    {
        Schema::table('campanha', function (Blueprint $t): void {
            $t->dropColumn(['reiniciar_pedido_em', 'reiniciar_aviso']);
        });
    }
};

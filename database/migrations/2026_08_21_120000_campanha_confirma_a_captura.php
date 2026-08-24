<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `campanha.audio_confirma_captura_id`: o "recebi, obrigado" no fim da captura.
     *
     * 🔴 A CAPTURA TERMINAVA MUDA, e é o único momento do fluxo sem retorno nenhum.
     *
     * O opt-in confirma a tecla (`audio_confirma_opt_in`), o opt-out confirma a recusa
     * (`audio_confirma_opt_out`), a tecla errada tem o "não entendi". Quem digitava os 11
     * dígitos do CPF certo — o único caminho que dá o produto da Campanha — ouvia silêncio
     * e a linha caía. Do lado da pessoa isso é indistinguível de ter dado errado, e é o
     * pior lugar para essa dúvida: ela acabou de entregar um documento.
     *
     * NO FIM, não campo a campo. Com um campo os dois são a mesma coisa; com quatro,
     * confirmar cada um transforma a ligação numa sequência de interrupções. O que a
     * pessoa precisa saber é que o conjunto chegou — e é isso que `captura_concluida`
     * significa.
     *
     * 🔴 SÓ NO SUCESSO. `captura_parcial` não toca este áudio: dizer "recebemos seus
     * dados" a quem errou o CPF duas vezes é mentir para a pessoa e esconder do operador
     * que a coleta falhou. Um áudio próprio para esse caso é outra decisão, e diferente —
     * ali o texto teria que dizer que NÃO deu certo.
     *
     * NULLABLE: Campanha sem ele segue funcionando exatamente como hoje. É cortesia, e
     * `Fluxo::tocarCortesia` já trata ausência e falha de arquivo sem tocar no desfecho.
     */
    public function up(): void
    {
        Schema::table('campanha', function (Blueprint $t): void {
            $t->foreignId('audio_confirma_captura_id')
                ->nullable()
                ->after('audio_confirma_opt_out_id')
                ->constrained('audio');
        });
    }

    public function down(): void
    {
        Schema::table('campanha', function (Blueprint $t): void {
            $t->dropForeign(['audio_confirma_captura_id']);
            $t->dropColumn('audio_confirma_captura_id');
        });
    }
};

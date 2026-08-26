<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A chave que manda o Disparo pela Marketing Messages API da Meta.
     *
     * 🔴 NASCE DESLIGADA, e isso não é conservadorismo vago. A MM API só aceita
     * envio depois que a WABA chega a `ONBOARDED`; antes disso ela está em
     * `PENDING_INTERNAL_SETUP` — que é o estado da conta em 26/08/2026, minutos
     * depois de os Termos serem aceitos. Ligar por padrão faria toda instalação
     * tentar um endpoint que ainda recusa.
     *
     * 🔴 E É CHAVE, NÃO SUBSTITUIÇÃO. O endpoint antigo continua no código e é para
     * onde o envio volta com um clique, sem deploy. A MM API é caminho novo para um
     * problema medido — as recusas de "healthy ecosystem engagement", em que a Meta
     * ACEITA a mensagem e depois desiste de entregar —, e caminho novo contra
     * problema de plataforma merece volta atrás barata.
     *
     * 🔴 SÓ MARKETING PASSA POR ELA. O Disparo de Campanha vai; a resposta do
     * operador nas Conversas continua no `/messages`, porque texto livre dentro da
     * janela de 24 h não é marketing e a MM API não o aceita. Quem separa os dois é
     * o chamador, não esta coluna.
     *
     * NÃO há coluna para `message_activity_sharing`. Omitindo o campo no corpo, vale
     * o que o operador configurar no painel da Meta — a decisão de compartilhar
     * evento com a Meta fica onde ele pode mudá-la sozinho, em vez de fixa aqui.
     */
    public function up(): void
    {
        Schema::table('configuracao', function (Blueprint $t): void {
            $t->boolean('marketing_api_ativa')->default(false)->after('integracao_whatsapp_id');
        });
    }

    public function down(): void
    {
        Schema::table('configuracao', function (Blueprint $t): void {
            $t->dropColumn('marketing_api_ativa');
        });
    }
};

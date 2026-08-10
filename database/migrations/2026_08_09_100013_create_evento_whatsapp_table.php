<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Entrada crua do webhook. O endpoint valida X-Hub-Signature-256, GRAVA
        // AQUI e devolve 200 — nada de processamento dentro do webhook. É o
        // espelho de entrada do ADR-0002, e a Meta reentrega em não-200 e em
        // lentidão.
        Schema::create('evento_whatsapp', function (Blueprint $t) {
            $t->id();

            // Idempotência contra a reentrega da Meta.
            $t->string('id_externo')->unique();

            // 🔴 NÃO é "cru, como chegou" — e a versão anterior deste comentário
            // afirmava que era. Coluna `JSON` faz o MySQL NORMALIZAR o que entra:
            // espaços somem e chaves podem ser reordenadas. Verificado em teste.
            //
            // Consequência: a assinatura `X-Hub-Signature-256` NÃO pode ser
            // reverificada a partir desta coluna, porque ela é sobre os bytes
            // originais. Para o processamento isso é indiferente — o worker faz
            // json_decode de todo jeito. Se um dia for preciso provar autenticidade
            // depois do fato, o tipo tem que virar LONGTEXT.
            //
            // Definir expurgo ANTES de acumular — e o expurgo apaga o payload, nunca
            // a linha de conversa_mensagem.
            $t->json('payload');

            $t->timestamp('recebido_em')->useCurrent();
            $t->timestamp('processado_em')->nullable();   // NULL até o worker consumir
            $t->index('processado_em');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('evento_whatsapp');
    }
};

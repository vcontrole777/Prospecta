<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Fila de eventos do webhook do httpSMS.
     *
     * 🔴 TABELA PRÓPRIA, e não `evento_whatsapp` reaproveitada. As duas guardam "um
     * corpo cru esperando processamento", e a semelhança tenta — mas o consumo é
     * diferente: uma vira status de `conversa_mensagem` e de `acao`, a outra só de
     * `acao`, e as duas crescem em ritmos distintos. Reaproveitar obrigaria uma
     * coluna `origem` e um `WHERE` em toda consulta das duas, para economizar um
     * `CREATE TABLE`.
     *
     * 🔴 `id_externo` ÚNICO dá a idempotência pelo ESQUEMA. O httpSMS reentrega
     * quando recebe 5XX — até quatro vezes, com pelo menos 1 s entre elas —, e o
     * `id` do CloudEvent é único por evento. `insertOrIgnore` sobre isto torna a
     * reentrega inofensiva sem código nenhum de deduplicação.
     */
    public function up(): void
    {
        Schema::create('evento_sms', function (Blueprint $t): void {
            $t->id();
            $t->string('id_externo')->unique();
            $t->json('payload');
            $t->timestamp('recebido_em')->useCurrent();
            $t->timestamp('processado_em')->nullable();

            // O worker varre por "ainda não processado"; sem índice isso vira
            // varredura completa assim que a tabela crescer.
            $t->index('processado_em');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('evento_sms');
    }
};

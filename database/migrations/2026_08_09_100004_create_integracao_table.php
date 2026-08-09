<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Integrações. UMA LINHA POR NÚMERO no caso de WhatsApp.
        // WhatsApp é SEMPRE Cloud API oficial — sem gateway não oficial.
        // A consulta de CPF NÃO é integração: é nativa, com token em variável
        // de ambiente.
        Schema::create('integracao', function (Blueprint $t) {
            $t->id();
            $t->enum('tipo', ['tts', 'stt', 'whatsapp', 'sms', 'automacao']);
            $t->string('provedor');
            $t->string('nome');
            $t->string('voz')->nullable();   // só se aplica a tts

            // CIFRADO em repouso, chave fora do banco. Exibido MASCARADO na tela.
            // Para whatsapp: { access_token, waba_id, phone_number_id,
            //   display_phone_number, verified_name }
            // phone_number_id NÃO é o telefone — é id próprio usado como caminho
            // na URL de envio. Rotular assim na tela.
            $t->json('credenciais');

            $t->boolean('ativo')->default(true);
            $t->timestamp('criado_em')->useCurrent();
            $t->unique(['tipo', 'provedor', 'voz']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integracao');
    }
};

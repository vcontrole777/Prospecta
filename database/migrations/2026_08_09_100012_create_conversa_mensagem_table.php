<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversa_mensagem', function (Blueprint $t) {
            $t->id();
            $t->foreignId('conversa_id')->constrained('conversa')->cascadeOnDelete();
            $t->string('wamid')->nullable()->unique();
            $t->enum('direcao', ['entrada', 'saida']);

            // String e não enum: o esquema lista o conjunto com reticências,
            // então ele é aberto.
            $t->string('tipo', 30);

            $t->text('texto')->nullable();
            $t->string('template_nome')->nullable();

            // CINCO estados, não quatro: 'reproduzido' (played) existe para áudio.
            // Tela que assume quatro mostra o quinto como desconhecido.
            $t->enum('status', ['enviado', 'entregue', 'lido', 'reproduzido', 'falhou'])
              ->nullable();

            $t->unsignedInteger('erro_codigo')->nullable();
            $t->timestamp('criado_em')->useCurrent();

            // Ordenar pelo carimbo do payload, não pela chegada: evento de
            // webhook chega fora de ordem e duplicado.
            $t->index(['conversa_id', 'criado_em']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversa_mensagem');
    }
};

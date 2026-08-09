<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Campos capturados por DTMF numa Campanha de tipo 'captura'.
        Schema::create('campanha_campo', function (Blueprint $t) {
            $t->id();
            $t->foreignId('campanha_id')->constrained('campanha')->cascadeOnDelete();
            $t->foreignId('campo_definicao_id')->constrained('campo_definicao');
            $t->unsignedTinyInteger('ordem');
            $t->foreignId('audio_prompt_id')->nullable()->constrained('audio');

            // Esgotou tentativas: obrigatório encerra, opcional pula.
            $t->boolean('obrigatorio')->default(true);
            $t->unsignedTinyInteger('tentativas')->default(2);

            // Por campo: 11 dígitos leva muito mais tempo que uma tecla.
            $t->unsignedTinyInteger('timeout_seg')->default(10);

            $t->unique(['campanha_id', 'ordem']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campanha_campo');
    }
};

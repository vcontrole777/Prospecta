<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Configurações -> Campos. Definido uma vez, reutilizado por Campanha.
        // `cpf` é embutido: 11 dígitos, dígito verificador validado localmente
        // ANTES de gastar rede, e só então dispara a consulta na API.
        Schema::create('campo_definicao', function (Blueprint $t) {
            $t->id();
            $t->string('nome')->unique();   // a chave que aparece em ligacao.respostas
            $t->unsignedTinyInteger('tamanho');
            $t->enum('tipo', ['cpf', 'numero']);
            $t->timestamp('criado_em')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campo_definicao');
    }
};

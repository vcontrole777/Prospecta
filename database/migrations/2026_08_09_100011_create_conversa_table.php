<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Thread de WhatsApp. POR NÚMERO, não por Campanha: o WhatsApp não sabe
        // das nossas Campanhas, então o mesmo Lead em duas Campanhas tem UMA
        // Conversa — que é o que ele vê no telefone.
        Schema::create('conversa', function (Blueprint $t) {
            $t->id();
            $t->string('telefone', 15)->unique();

            // NULLABLE: pode ser quem escreveu sem nunca ter estado em Campanha.
            // Caso normal, não de borda — tem que aparecer na caixa de entrada.
            $t->foreignId('lead_id')->nullable()->constrained('leads')->nullOnDelete();

            // A janela de 24 h NÃO é coluna: é ultima_entrada_em + 24 h.
            // Armazenar o vencimento criaria duas verdades sobre a mesma coisa.
            // A consulta fica indexável pondo a função no lado da CONSTANTE:
            //   WHERE ultima_entrada_em > NOW() - INTERVAL 24 HOUR   usa índice
            //   WHERE ultima_entrada_em + INTERVAL 24 HOUR > NOW()   não usa
            $t->timestamp('ultima_entrada_em')->nullable();

            $t->timestamp('criado_em')->useCurrent();
            $t->index('ultima_entrada_em');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversa');
    }
};

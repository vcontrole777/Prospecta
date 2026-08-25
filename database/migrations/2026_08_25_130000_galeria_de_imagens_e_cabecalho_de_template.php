<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Galeria de imagens, e a Campanha apontando para uma delas.
     *
     * 🔴 DOIS TEMPLATES APROVADOS ERAM INENVIÁVEIS, e o erro só aparecia na
     * primeira mensagem. Medido em 25/08/2026 contra a conta real: dos quatro
     * templates aprovados, `abordagem_liv_pts` e `abordagem_liv_pts_2` têm
     * `HEADER format=IMAGE`, e `enviarTemplate` montava só o componente `body`. A
     * Meta recusa com `(#132000) Number of parameters does not match the expected
     * number of params` — e o operador lê "falhou" sem saber que falta uma imagem.
     *
     * 🔴 POR LINK, NÃO POR `media_id`. O upload da Meta devolve um id, mas ele
     * EXPIRA (~30 dias) e é preso ao número que subiu. Uma galeria que guardasse só
     * o id quebraria sozinha, sem ninguém mexer em nada, e quebraria no envio. E há
     * uma segunda instância planejada, com outro App e outra BM: id gerado num
     * número não vale no outro, e a mesma imagem precisaria ser resubida e
     * rastreada por número. Guardando o arquivo e servindo por URL, a imagem serve
     * qualquer número e não tem ciclo de vida.
     *
     * O `media_id` continua possível DEPOIS, como cache por cima disto, sem mexer
     * na galeria nem na tela.
     */
    public function up(): void
    {
        Schema::create('imagem', function (Blueprint $t): void {
            $t->id();

            /* O nome que o operador escolheu. É o que a tela mostra. */
            $t->string('nome');

            /*
             * 🔴 O QUE VAI NA URL PÚBLICA, e por isso NÃO é o id.
             *
             * A rota que serve a imagem precisa ser aberta — é a Meta que busca o
             * arquivo, e ela não faz login. Com o id na URL, `/imagem/1`, `/imagem/2`
             * enumeraria a galeria inteira de quem descobrisse uma. O token opaco
             * torna a URL não adivinhável, que é a única proteção que uma rota
             * pública pode ter.
             */
            $t->char('token', 32)->unique();

            /*
             * Nome do arquivo em disco, DERIVADO do id — nunca do nome digitado.
             *
             * Mesma disciplina da tela de Áudios e do ADR-0003: assim não há como um
             * nome com `../` chegar ao caminho. O legível vive em `nome`.
             */
            $t->string('arquivo');

            /* Servido no `Content-Type`. A Meta recusa imagem cujo tipo não bate. */
            $t->string('mime', 50);

            $t->unsignedInteger('bytes');

            $t->timestamp('criado_em')->useCurrent();
        });

        Schema::table('campanha', function (Blueprint $t): void {
            /*
             * 🔴 ANULÁVEL, e a maioria das Campanhas fica com nulo. Só template com
             * `HEADER format=IMAGE` precisa disto; para os outros, mandar componente
             * de cabeçalho é o mesmo erro 132000 ao contrário — parâmetro a mais.
             *
             * `nullOnDelete`: apagar a imagem não pode apagar a Campanha. Ela fica
             * sem cabeçalho e a tela volta a cobrar, que é o comportamento visível.
             */
            $t->foreignId('imagem_id')->nullable()->after('audio_nao_entendi_id')
                ->constrained('imagem')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('campanha', function (Blueprint $t): void {
            $t->dropConstrainedForeignId('imagem_id');
        });

        Schema::dropIfExists('imagem');
    }
};

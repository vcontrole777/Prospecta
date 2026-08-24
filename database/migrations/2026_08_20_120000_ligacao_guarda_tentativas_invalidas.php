<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `ligacao.tentativas_invalidas`: o que a pessoa DIGITOU e não passou na validação.
     *
     * Hoje esse dado é jogado fora. `Fluxo::lerCampo` devolve `null` quando as tentativas
     * do campo esgotam, e nem o log guarda o valor — registra só `strlen`. O desfecho vira
     * `captura_parcial` e a tela mostra célula VAZIA, então o operador vê que a captura
     * falhou e não tem como saber por quê: a pessoa digitou 5 dígitos e desistiu? Digitou
     * 11 e errou o dígito verificador? Digitou o CPF do cônjuge? São problemas diferentes,
     * com correções diferentes — no áudio do prompt, no tamanho do campo, ou em nada.
     *
     * 🔴 COLUNA SEPARADA, e isto não é organização: é correção.
     *
     * `respostas` é o PRODUTO da Campanha de captura — é o JSON que a tela exporta em CSV
     * e que vai para o sistema do cliente. Guardar tentativa inválida ali dentro mandaria
     * um CPF reprovado no dígito verificador como se fosse um CPF bom, e ninguém do outro
     * lado teria como distinguir. O valor inválido é diagnóstico, não é entrega.
     *
     * Guarda a LISTA de tentativas por campo, não só a última: `{"CPF":["123","45678"]}`
     * conta a história inteira — duas tentativas, ambas curtas, prompt provavelmente ruim.
     * Só a última esconderia isso.
     */
    public function up(): void
    {
        Schema::table('ligacao', function (Blueprint $t): void {
            $t->json('tentativas_invalidas')->nullable()->after('respostas');
        });
    }

    public function down(): void
    {
        Schema::table('ligacao', function (Blueprint $t): void {
            $t->dropColumn('tentativas_invalidas');
        });
    }
};

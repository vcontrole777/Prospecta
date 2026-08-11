<?php

declare(strict_types=1);

namespace App\Fastagi;

/**
 * Tudo que a FASE 1 carregou. Depois disto o banco é DESCONECTADO (ADR-0001):
 * processo por chamada que segura conexão pela duração inteira transforma 200
 * canais em 200 conexões contra um max_connections de 151, ociosas em ~95% do
 * tempo.
 *
 * Ser um objeto de dados simples é o que deixa a FASE 3 testável sem banco.
 */
final class Dados
{
    /**
     * @param 'opt_in'|'captura' $tipo
     * @param list<array{nome:string,tamanho:int,tipo:string,audio:?string,obrigatorio:bool,tentativas:int,timeoutSeg:int}> $campos
     * @param list<string> $amd parâmetros do AMD, na ordem posicional
     */
    public function __construct(
        public readonly int $ligacaoId,
        public readonly string $tipo,
        public readonly string $audioAbertura,
        /**
         * 🔴 OPCIONAL, e a mudança é o conserto de uma falha calada.
         *
         * Antes era `string` com `'prospecta/mensagem'` fixo como recurso — um arquivo
         * que o código referenciava e o provisionamento NÃO instalava. Campanha ativada
         * por SQL sem áudio de mensagem fazia a URA ler dígitos sem prompt: a pessoa
         * ouvia SILÊNCIO por 8 segundos e o desfecho virava `sem_resposta`.
         *
         * Agora o recurso é `configuracao.audio_mensagem_generica_id`, e `null` aqui é
         * um estado que o `Fluxo` recusa com causa nomeada em vez de discar mudo.
         */
        public readonly ?string $audioMensagem,
        public readonly ?string $audioConfirmaOptIn,
        public readonly ?string $audioConfirmaOptOut,
        public readonly ?string $audioNaoEntendi,
        public readonly ?string $teclaOptIn,
        public readonly ?string $teclaOptOut,
        public readonly int $tentativasUra,
        public readonly bool $gravacaoAtiva,
        public readonly string $gravacaoDir,
        public readonly array $campos = [],
        public readonly array $amd = ['6000', '4000', '800', '5000', '100', '50', '3', '256', '5000'],
    ) {
    }
}

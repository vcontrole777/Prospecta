# ADR-0005 — Ritmo do `dialer` realimentado em canal livre

**Data:** 2026-08-09
**Status:** aceito
**Supersede:** a variante "lote por minuto com espaçamento fixo", dimensionada para 20 canais (issue #11).

## Contexto

O `dialer` varre Campanhas ativas e origina pela Fila de discagem via AMI `Originate` com `Async: true`. A questão é o ritmo.

A primeira decisão foi lote por minuto com espaçamento calculado — simples, e adequada a 20 canais. A análise de escala mostrou que não sobrevive a 200: taxa em malha aberta escorrega nos dois sentidos, deixando canal ocioso ou estourando o contratado.

O MagnusBilling faz malha aberta e o defeito é demonstrável:

```php
else $sleep = $campaign->frequency / 60;   // 100/60 = 1,667
...
if (($i % $sleep) == 0) $sleepNext += 1;   // % é inteiro: 1,667 vira 1
```

`$i % 1 == 0` é sempre verdadeiro, então com `frequency = 100` saem **60 originações por minuto em vez de 100** — 40% abaixo, em silêncio. Funciona só quando `frequency / 60` é inteiro. Mesma assinatura do defeito de bloco de tarifação deles: correto quando divide, errado quando não.

## Decisão

O `dialer` conta **canal livre real** a cada volta e origina para preencher, em vez de aplicar uma taxa calculada.

O reprocessamento é por **rodadas completas da Fila de discagem**: cada Lead tem `status` (1–4) e contador `try`, sem agendamento por contato. A Campanha carrega `rodada_atual` e `max_tentativas`.

Três regras de integridade acompanham, todas vindas de defeitos observados:

1. **Todo caminho de saída do laço grava status terminal no Lead.** Um `continue` sem escrita deixa a linha reivindicada e nunca mais discada. No `MassiveCallCommand` deles a falha de seleção de tronco sai por `continue` seco, e esses números ficam em "discando" para sempre.
2. **Depois de reivindicar a linha, nunca gravar model completo — só `UPDATE` de coluna.** Model carregado antes da reivindicação reverte a reivindicação. No Yii deles o `save()` desfaz o `try + 1` de um `updateAll` anterior.
3. **Roteamento não lê estado de retentativa.** Entrelaçar as duas responsabilidades quebrou a segunda tentativa deles por inteiro: `$phone->try > 0` dentro da escolha de tronco faz a retentativa discar no Tronco SIP do número anterior, com o prefixo dele.

E uma regra que protege a lista. A formulação inicial era **"erro do lado da conta não consome tentativa"**, porque um Tronco SIP morto devolvendo `503` em toda chamada esgotaria `max_tentativas` de todos os Leads com o sistema parecendo funcionar.

**Medição de 2026-08-09 contra a IDT derrubou essa formulação.** Um DDD inexistente devolve:

```
<-- SIP/2.0 503 Service Unavailable
HANGUPCAUSE = 34 (circuit/channel congestion)   DIALSTATUS = CONGESTION
```

Ou seja, **`503` não distingue número ruim de conta com problema.** Isentar `503` de tentativa faria número ruim ser rediscado para sempre.

Formulação corrigida: **`503` consome a tentativa, e a proteção do tronco é só o contador de erros consecutivos.** Funciona porque as duas situações têm assinaturas diferentes no agregado: tronco morto dá 100% de `503`, enquanto lista com números ruins tem sucessos intercalados que zeram o contador. O tronco morto é pego em N chamadas, não em `max_tentativas` × tamanho da lista.

## Consequências

- A originação acompanha o canal de verdade, então a mesma implementação serve de 20 a 200 canais.
- **O `dialer` é serviço persistente, não execução de cron.** Taxa realimentada quer processo contínuo: um cron por minuto só consegue aplicar taxa calculada, que é exatamente a malha aberta rejeitada acima. Efeito colateral que só apareceu ao escrever o provisionamento (#23): o systemd garante instância única, então **a trava de arquivo que um cron exigiria deixa de ser necessária** — o `flock` que o MagnusBilling precisa ter não tem equivalente aqui.
- A reivindicação de Lead usa `SELECT ... FOR UPDATE SKIP LOCKED`. Continua valendo: protege contra reinício do serviço em cima de linhas já reivindicadas, e contra o `worker` tocando as mesmas linhas.
- **Gravar a Ligação antes de originar.** Linha sem chamada é reconciliável; chamada sem linha é invisível. E `ligacao.id` é o `ChannelId`, então precisa existir antes do `Originate`.
- **Toda Ligação gera linha, independente de duração.** O CDR deles descarta `duration <= 1` e some justamente com o sinal mais diagnóstico que existe: a chamada de zero segundo, que é Tronco SIP rejeitando ou BINA recusada.
- Estouro de teto de CPS da operadora vai disparar a pausa por erro consecutivo. Se a operadora sinalizar ritmo com código distinto de `503`, separar as duas causas na mensagem ao operador — pergunta aberta no #13.

# ADR-0002 — Ação é linha em tabela, nunca chamada HTTP dentro da Ligação

**Data:** 2026-08-09
**Status:** aceito

## Contexto

Quando o Lead digita a tecla de Opt-in, dispara uma Ação — um Disparo (WhatsApp ou SMS para o Lead) ou uma Notificação (mensagem para o dono da Campanha). A decisão de produto é que a Ação dispara **no instante da tecla**.

A implementação óbvia é fazer a chamada HTTP ali mesmo, no handler. O MagnusBilling faz isso, em três sítios:

```php
file_get_contents($url);                                       // webhook
@file_get_contents($url, false);                               // SMS
exec(" curl -k -X POST  -d '" . $url[1] . "' " . $url[0] . ""); // webhook POST
```

`file_get_contents` sobre HTTP usa `default_socket_timeout`, cujo padrão é **60 segundos**. Um webhook pendurado deixa o Lead ouvindo silêncio por um minuto. O `-k` desliga verificação de TLS. E o caminho de SMS suprime erro com `@` e testa só se a resposta é vazia — sem olhar código de status.

## Decisão

Nenhuma chamada HTTP acontece dentro da Ligação. A Ação é uma **linha na tabela `acao`**, consumida por um `worker` separado.

A tabela carrega a idempotência no esquema: `UNIQUE (tipo, referencia_id)`.

Quatro regras acompanham:

1. **O `worker` reivindica a linha atomicamente** — `UPDATE acao SET status='processando' WHERE id=? AND status='pendente'`, verificando linhas afetadas. Nunca ler-depois-escrever.
2. **Marcar `enviado` depois de enviar.** Escolha explícita da direção do erro: o `UNIQUE` impede linha duplicada, não envio duplicado. Duplicar incomoda; perder frustra quem pediu contato.
3. **Gravar a tecla e criar a linha de `acao` é uma transação.** Sem isso, "a Ação dispara no instante da tecla" é retórica: o processo pode morrer entre as duas escritas e o Lead que apertou 2 fica sem Disparo.
4. **A transação sobrevive ao desligamento do Lead.** O caso comum é apertar a tecla e desligar no mesmo instante. O handler detecta socket fechado e ainda assim commita.

## Consequências

- Um provedor lento ou fora do ar nunca degrada a Ligação. O pior caso é atraso na Ação.
- Retentativa é gratuita: a linha fica onde está, com o estado nela.
- A fila é inspecionável — o painel mostra Ações pendentes sem instrumentação extra.
- **As filas do Laravel não substituem esta tabela.** A idempotência vem do `UNIQUE`, não do backend de fila.

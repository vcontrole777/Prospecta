# ADR-0001 — Processo por chamada, com bootstrap mínimo

**Data:** 2026-08-09
**Status:** aceito
**Supersede:** a variante Swoole e a variante "comando Laravel completo", ambas consideradas e descartadas durante a especificação (issue #11).

## Contexto

Cada Ligação atendida precisa de um handler que toque a Mensagem, colete DTMF e grave o Desfecho. Três modelos foram considerados.

**Swoole** foi descartado por três custos operacionais: build por VPS, recompilação a cada bump de minor do PHP, e a armadilha silenciosa do `mysqlnd` bloqueando corrotina. Como cada cliente tem sua instância, o custo multiplica por instalação.

**Comando Laravel completo por chamada** foi a decisão intermediária, e não chega a 200 canais: ~35 MB de RSS por processo × 200 = ~7 GB só de handlers.

**Autoload + PDO, sem framework** fica em ~5 MB por processo, ~1 GB em 200 canais.

Havia ainda um custo que o modelo de processo por chamada carrega e que quase passou: o processo vive a Ligação inteira, então **segura uma conexão MySQL pela duração toda**, ociosa em ~95% do tempo. Em 200 canais isso são 200 conexões contra um `max_connections` padrão de 151, e uma Ligação travada prende conexão até a varredura de reconciliação agir. O `app_mbilling` do MagnusBilling libera a conexão ODBC antes de discar exatamente por isso; a análise deles nomeia a decisão como a melhor engenharia do módulo, e registra que o AGI em PHP deles não a tem.

## Decisão

O handler é um **processo por Ligação**, ativado por socket do systemd, com **bootstrap mínimo**: `composer autoload` e PDO direto. Sem container de serviços, sem Eloquent, sem providers.

O handler tem quatro fases, e a terceira não toca o banco:

1. Ler tudo que precisa — Campanha, Lead, Áudios, campos a capturar
2. Soltar a conexão
3. Rodar o fluxo — tocar áudio, capturar DTMF. Zero banco. É a fase longa
4. Reconectar e gravar

## Consequências

- Isolamento de falha por Ligação: um defeito no tratamento de um Lead derruba uma chamada, não as 200. É a propriedade que um módulo `.so` dentro do Asterisk perde (ver ADR-0004).
- A concorrência real de conexões cai para um punhado: cada processo segura a conexão ~50 ms de uma Ligação de ~40 s.
- Reconectar custa ~1–5 ms, irrelevante contra o volume de originações.
- **As classes compartilhadas com o painel precisam ser puras** — sem `app()`, sem model de framework. Ver ADR-0006.
- `MaxConnections=` na unit `.socket` do systemd tem padrão **64**. Com `Accept=yes` e 200 Ligações simultâneas, conexões passam a ser recusadas em silêncio. Ajustar, junto com `LimitNOFILE`.

## Revisitar se

O RSS medido do handler ficar 5–10× acima dos ~5 MB estimados. A medição está no #13.

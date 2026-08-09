# ADR-0003 — Valor externo é hostil em cinco contextos

**Data:** 2026-08-09
**Status:** aceito

## Contexto

Duas fontes de valor externo chegam ao handler: a sinalização SIP (número, BINA, nome de canal, uniqueid) e o CSV importado (telefone e `dados_extras`, que alimenta Placeholder, texto de Mensagem e parâmetro de template do WhatsApp).

O MagnusBilling trata as duas como confiáveis, e cada contexto rende um defeito:

| Contexto | Defeito deles |
|---|---|
| SQL | `$phonenumberCity` e `$destination`, de CSV e de SIP, interpolados em `INSERT` |
| Shell | nome do CSV sem codificação dentro de `exec(" curl ... -d '...'")` — apóstrofo escapa das aspas e executa shell no PBX |
| Caminho de arquivo | `$this->CallerID` cru compõe caminho de gravação — um `/` escreve fora do diretório |
| Argumento de aplicação Asterisk | o mesmo CallerID com `,` injeta opções no `MixMonitor` |
| **Padrão de regex** | `preg_match('/' . $accountcode . '\|' . $dnid . '\|' . $sip_account . '/', ...)` |

O quinto é o menos óbvio e tem duas falhas próprias: um `/` no `dnid` encerra o padrão e o resto vira flag de modificador; e um `accountcode` **vazio** produz `/|123|abc/`, cuja alternação com ramo vazio casa em qualquer posição — sempre verdadeiro, ligando log verboso em toda chamada do PBX.

## Decisão

Valor vindo de SIP ou de CSV é hostil em **cinco** contextos, e cada um tem defesa própria:

| Contexto | Defesa |
|---|---|
| SQL | parâmetro vinculado, sempre. Nunca concatenação, nunca escape manual |
| Shell | não construir linha de comando com valor externo |
| Caminho de arquivo | o caminho de gravação vem **só** do `ligacao.id` inteiro |
| Argumento de aplicação Asterisk | idem — nada de valor externo em argumento de aplicação |
| Padrão de regex | valor externo nunca entra em padrão; se precisar casar literal, `preg_quote` |

## Consequências

- O caminho do `MixMonitor` é derivado do `ligacao.id`, que é inteiro por construção. Sai de graça.
- Escape manual não conta como defesa. O protótipo em C deles tem uma função `sql_esc` e a análise registra que ela quebra sob `NO_BACKSLASH_ESCAPES` e em charset multibyte.
- Vale para o `worker` também: `dados_extras` vai para parâmetro de template do WhatsApp, que é o mesmo problema noutra roupa.

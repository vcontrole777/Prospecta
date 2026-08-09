# ADR-0007 — Disciplina de falha do handler

**Data:** 2026-08-09
**Status:** aceito

## Contexto

O handler roda sem navegador, sem usuário olhando, com o Lead na linha. É o pior lugar possível para um `Undefined variable`.

O MagnusBilling é o caso de estudo, e a cadeia causal é precisa. A linha 3 do despachante deles:

```php
error_reporting(E_ALL ^ (E_NOTICE | E_WARNING | E_DEPRECATED));
```

Isso suprime exatamente o que pegaria os seis defeitos do caminho de coleta de DTMF deles — variável não inicializada lida numa comparação, `==` onde se queria `=` num reset que por isso nunca acontece, índice de array vazio, conversão de float para int com perda, propriedade inexistente. **A supressão não é dano colateral: é a causa.** Sem ela, a configuração mais comum de Campanha cospe warnings em toda chamada e alguém conserta na primeira semana. Com ela, o defeito vive anos — e um dos caminhos (`$agi = explode(...)` seguido de `$agi->exec_agi(...)`, método chamado em array) nunca executou uma única vez.

Todos os seis são pegos por análise estática sem configuração especial.

Dois outros padrões do despachante deles produzem falha silenciosa. O despacho é cadeia de `if` sem `else` e sem `exit` consistente, governada por uma flag mutável de modo: depois de uma chamada de Campanha a execução **cai** em verificação de DID e num segundo caminho de faturamento, nenhum dos dois protegido pela flag. E não há `hangup_handler`, então comandos AGI continuam sendo emitidos em canal morto — inclusive no ramo em que o AMD devolve `HANGUP` e o código segue tratando como atendimento humano.

## Decisão

Cinco regras.

1. **Nunca suprimir diagnóstico.** Warning, notice e deprecated vão para log estruturado, com o `ligacao.id`.
2. **PHPStan em CI**, com o handler no nível mais alto que passar.
3. **O despachante termina explicitamente em todo ramo.** Sem flag mutável de modo governando fall-through.
4. **Desfecho só é gravado quando é conhecido.** Nada de status otimista antes da validação — eles gravam "atendida por humano" antes de checar `OutgoingSpoolFailed`, e originação falhada entra no relatório como atendimento.
5. **`hangup_handler_push` no dialplan faz a limpeza.** O handler não pode ser responsável por isso: o canal pode já ter ido. É também onde `HANGUPCAUSE(...,tech)` é capturado.

## Consequências

- `AMDSTATUS = HANGUP` desvia para o fim, não para o ramo humano. `NOTSURE` segue como humano **de propósito** — falso-MÁQUINA é o erro caro.
- Verificação de áudio depois da conversão, com causa nomeada (`AUDIO_MISSING`, `AUDIO_INCOMPATIBLE`): `sox` e `ffmpeg` podem terminar com sucesso e parâmetro errado. O diagnóstico de áudio deles é elogiado pela própria análise como o melhor trabalho de engenharia do repositório, e é o padrão a seguir.
- **"Testar Campanha" sem discar** é contexto de request sintético pelo mesmo caminho de código, sem canal. Modelo de referência: o bloco `debug` do `mbilling.php` deles, que injeta `agi_extension`, `agi_channel` e `agi_uniqueid` falsos — e que é, ironicamente, o único código validado por lista de permissão em todo o AGI, num caminho cuja entrada vem de operador confiável, enquanto o número vindo do INVITE de um desconhecido vai cru para SQL e para regex (ADR-0003).

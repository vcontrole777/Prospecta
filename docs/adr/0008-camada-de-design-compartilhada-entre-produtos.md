# ADR-0008 — Camada de design compartilhada entre produtos

**Data:** 2026-08-09
**Status:** aceito

## Contexto

Prospecta é o primeiro de uma família planejada de produtos de telefonia da mesma empresa. Os produtos terão bases de código separadas, e a identidade visual precisa ser a mesma entre eles.

Isso levanta a dúvida se a escolha de Livewire (server-rendered, sem SPA — ADR-0001, ADR-0004) atrapalha a consistência. Não atrapalha: consistência visual mora na camada de design, não na estratégia de renderização. Blade e Livewire compartilham componentes por pacote Composer da mesma forma que React compartilha por pacote npm. Trocar para um framework JS custaria um fork da stack para resolver um problema que o Composer resolve.

O ganho real, porém, não está nos componentes genéricos — botão, input, modal, tabela vêm de qualquer kit pronto. Produtos de telefonia se parecem no **vocabulário do domínio**:

- badge de estado de Ligação — "tocando", "atendida", "falhou" — com a mesma cor em todo produto
- exibição de telefone, formatado e mascarado igual
- duração formatada igual (nunca segundos crus numa tela e formatado noutra)
- código SIP e causa de desligamento com a mesma legenda humana
- tabela estilo CDR com a mesma convenção e ordem de colunas
- monitor de canais ao vivo com a mesma leitura

Nenhum kit pronto entrega essa camada. É a que a empresa constrói.

Contra isso pesa que design system para um produto só é abstração prematura: não se sabe o que é compartilhado antes de existir o segundo produto.

## Decisão

Separar por assimetria de custo de retrofit.

**Tokens são artefato compartilhado desde já.** Cor, espaçamento, raio, tipografia e modo escuro, num arquivo CSS com `@theme` do Tailwind, publicável e importável. São baratos, quase certamente certos, e dolorosos de retrofitar porque cor vaza para centenas de lugares.

**Componentes ficam em Prospecta, com disciplina de pacote.** Vivem em `resources/views/components/` e **não contêm regra de negócio de Prospecta**. A extração para um pacote `empresa/ui` acontece quando o segundo produto começar — que é quando se aprende o que de fato é compartilhado. Abstrair antes é abstrair errado.

A disciplina é a mesma do ADR-0006 por um motivo análogo: a classe compartilhada é pura porque roda em dois lugares; o componente compartilhado é agnóstico de produto porque vai viver em dois produtos.

## Consequências

- A tela de chamadas online é o ponto duro, e é comum à família: `wire:poll` de 1 s sobre 200 Ligações ativas re-renderiza 200 linhas por segundo. A solução — componente estreito que devolve só o que mudou, ou SSE se o polling não segurar — deve ser decidida pensando nos três produtos e resolvida uma vez. Resolver três vezes, diferente em cada um, é a divergência que o MagnusBilling documenta com as duas classes `SearchTariff`.
- Componentes que nomeiam conceito de domínio usam o termo do `CONTEXT.md`. Um badge chamado `<x-status-ligacao>` carrega o glossário para dentro da interface.
- **Kit pronto está descartado por decisão do dono do produto:** o visual não pode ser genérico. Isso exclui Flux e equivalentes, inclusive para a camada genérica — adotar o kit no botão e no input já entrega o registro visual do kit.

## O que "não genérico" significa aqui

Quatro escolhas carregam a identidade, e nenhuma delas vem de biblioteca:

1. **Estado de Ligação é o sistema de cor, não um acessório.** Toda linha de toda tabela é uma Ligação num estado, então a paleta é projetada em volta do conjunto (`tocando`, `atendida`, `máquina`, `falhou`, `bloqueado`, `opt-in`): seis cores distinguíveis em badge, em 4 px de sparkline, e numa tabela densa com centenas simultâneas. A cor da marca vem depois disso, não antes.
2. **Algarismo tabular em todo dado numérico** (`font-variant-numeric: tabular-nums`), porque o dígito não pode pular quando o monitor ao vivo atualiza. Mais uma família mono ou semi-mono nos identificadores — telefone, código SIP, `ChannelId`.
3. **Densidade deliberada.** O produto é dado denso por natureza (CDR, monitor de canais); kits são espaçosos porque servem app de marketing. Denso e calmo é o registro correto e é funcionalmente melhor aqui.
4. **Modo escuro como padrão, claro como alternativa.** O operador acompanha o monitor ao vivo por horas.

## Registro e paleta

O registro escolhido é **suíço / industrial**: grade rigorosa, quase monocromático, cantos retos, régua e espaço em vez de sombra. Não existem tokens de sombra em `design/tokens.css`, e a omissão é deliberada — sombra é o vocabulário do registro rejeitado.

A paleta de origem é **Dracula (OSS)**, nas variantes escura e clara fornecidas pela empresa. Ela traz sete acentos saturados, o que contradiz "quase monocromático". A contradição se resolve por lugar de aplicação:

| | |
|---|---|
| **Cromo** — tabela, navegação, formulário, régua | espinha neutra, **um** acento (Cyan), vermelho de falha |
| **Gráfico** — distribuição de Desfecho, volume por hora | os seis acentos, porque ali o dado é genuinamente categórico |

É como design suíço se comporta: os gráficos são coloridos, o layout em volta não é. Efeito colateral desejável: com a regra de escassez aplicada, o resultado **não lê como Dracula** — a cara do Dracula vem de usar os sete acentos ao mesmo tempo em realce de sintaxe.

O acento tem **um** significado: "isto importa / isto está vivo". Opt-in, foco, seleção, item ativo da navegação. O vermelho é exclusivo de falha — se aparecer em aviso ou destaque, perde o significado na tabela.

**Estado de Ligação é codificado por peso e rótulo; cor é reforço escasso.** Três pesos de cinza mais duas cores: só `opt-in` (acento) e `falhou` (vermelho) recebem tinta. Isso substitui a formulação anterior de que "estado é o sistema de cor" — seis cores saturadas numa tabela de centenas de linhas é o ruído que este registro evita, e a codificação por peso funciona também em daltonismo.

### Contraste verificado

A cor `Comment` do Dracula escuro (`#6272a4`) dá **3,36:1** sobre o fundo e **falha WCAG AA** para texto normal. Ela é discreta de propósito em realce de sintaxe, onde comentário deve recuar; rótulo de 11px em painel não pode. Por isso `--ink-muted` usa `#8b90ad` (5,03:1) e `#6272a4` fica restrito a uso decorativo.

A variante clara não tem esse problema — passa AA em todos os níveis de texto.

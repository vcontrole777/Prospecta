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
- **REVERTIDO — o kit pronto entra: adotamos Mary UI.** Ver a seção "Reversão sobre kit pronto" abaixo.

## O que "não genérico" significa aqui

Quatro escolhas carregam a identidade, e nenhuma delas vem de biblioteca:

1. **Estado de Ligação é codificado por PESO e RÓTULO; cor é reforço escasso.** Toda linha de toda tabela é uma Ligação num estado, então o conjunto (`tocando`, `atendida`, `máquina`, `falhou`, `bloqueado`, `opt-in`) governa o desenho — mas por peso, não por tinta. Ver a seção "Registro e paleta" para o raciocínio completo e o contraste verificado. (Uma formulação anterior deste ADR dizia "estado é o sistema de cor", com seis cores saturadas; seis cores numa tabela de centenas de linhas é o ruído que o registro escolhido evita, e a codificação por peso funciona também em daltonismo.)
2. **Algarismo tabular em todo dado numérico** (`font-variant-numeric: tabular-nums`), porque o dígito não pode pular quando o monitor ao vivo atualiza. Mais uma família mono ou semi-mono nos identificadores — telefone, código SIP, `ChannelId`.
3. **Densidade deliberada.** O produto é dado denso por natureza (CDR, monitor de canais); kits são espaçosos porque servem app de marketing. Denso e calmo é o registro correto e é funcionalmente melhor aqui.
4. **Modo escuro como padrão, claro como alternativa.** O operador acompanha o monitor ao vivo por horas.

## Reversão sobre kit pronto — adotamos Mary UI

**Posição anterior:** kit pronto descartado, porque "adotar o kit no botão e no input já entrega o registro visual do kit".

**Por que estava errada:** aquilo tratava "não quero visual genérico" como se implicasse construir tudo. Mas a identidade do produto não está nos componentes que um kit entrega. Está em quatro lugares, e três deles são nossos de qualquer forma:

| Onde a identidade mora | Quem constrói |
|---|---|
| Régua em vez de sombra, canto reto, densidade | tokens + camada de override |
| Estado por **peso e rótulo**, cor escassa | nosso |
| Algarismo tabular, zero cortado | tokens |
| Badge de estado de Ligação, duração, código SIP, telefone formatado | **nosso, sempre** |

O que um kit entrega — formulário, modal, dropdown, tabela com ordenação, calendário — é o 80% onde o tempo vai e onde a identidade **não** está. Construir isso do zero seriam semanas e uma superfície de manutenção permanente, contra a preferência declarada de escopo mínimo.

**Decisão:** **Mary UI** (`robsontenorio/mary`, **MIT**) para a camada genérica, o tema impondo o registro, e o vocabulário de telefonia construído por nós.

Flux foi considerado primeiro e descartado por ser pago — e a troca saiu **melhor**, não só mais barata, nos dois pontos que estavam marcados como "confirmar antes de pagar".

### O registro suíço é a API DE TEMA do daisyUI, não um override

Mary UI não traz CSS próprio: ele apoia em **daisyUI**. E o daisyUI 5 expõe exatamente as alavancas do registro como variáveis de tema:

```css
--radius-selector: 0;   --depth: 0;     --border: 1px;
--radius-field: 0;      --noise: 0;
--radius-box: 0;
```

Canto reto e ausência de sombra deixam de ser luta contra a biblioteca e passam a ser **configuração dela**. Verificado no CSS compilado: os seis valores chegam nos dois temas.

Isso promove a ausência de tokens de sombra de higiene a **carga estrutural** — é o tema que impede a sombra do kit de voltar.

### O tema DERIVA dos nossos tokens, nunca duplica

Toda cor do tema é `var()` apontando para `design/tokens.css`, jamais literal:

```css
--color-base-100: var(--bg-base);
--color-primary:  var(--accent);
--color-error:    var(--fail);
```

É a lição "regra duplicada sempre diverge" aplicada à camada de design. Se a cor fosse escrita duas vezes, uma nos tokens e outra no tema, elas divergiriam na primeira mudança — e o sintoma seria uma tela quase certa, que é o pior tipo de defeito visual. Verificado: o CSS compilado emite as referências `var()`, e o navegador resolve em execução.

### 🔴 Os nomes de tema TÊM que casar com o atributo que os tokens usam

`design/tokens.css` chaveia o tema claro por `[data-theme="light"]` e guarda o escuro por `:not([data-theme="dark"])`. O daisyUI lê **o mesmo atributo**.

Com nomes diferentes — por exemplo `prospecta` e `prospecta-claro` — alternar o tema mudaria o daisyUI e deixaria os nossos tokens no outro, e o `var()` dele resolveria para a cor errada: **painel metade claro, metade escuro**. Por isso os temas se chamam `dark` e `light`.

### Risco nomeado

daisyUI é dependência transitiva, e a API de tema dele **mudou entre a v4 e a v5** (`--p`/`--b1` viraram `--color-primary`/`--color-base-100`). Um salto de major quebraria o tema. Fixado em `^5` no `package.json`, o que exclui major automático.

### Também removido: pipeline de fonte remota

O esqueleto do Laravel 13 buscava Instrument Sans de CDN em tempo de build. Removido por duas razões: contraria a escolha da linhagem Helvetica como canônica do registro, e um build que precisa de egresso é um build que falha na hora errada — com uma VPS por cliente, isso importa.

## O ponto duro se evita antes de se resolver

As Consequências acima nomeiam a tela de chamadas ao vivo como o ponto duro:
`wire:poll` de 1 s sobre 200 Ligações ativas re-renderiza 200 linhas por segundo,
e a solução deveria ser decidida uma vez para a família.

A primeira tela construída **não resolve esse problema — ela não o tem**, e isso
é a decisão, não um adiamento por preguiça.

O que o operador acompanha de minuto a minuto são ~8 números por Campanha: em
voo, discadas, atendidas, taxa, opt-in, erros. Isso é agregado, e o custo é fixo
no número de Campanhas, **independente dos canais contratados**. Uma instância de
200 canais paga o mesmo que uma de 10.

A tabela linha-a-linha ao vivo continua sendo uma tela, e continua sendo difícil.
A diferença é que ela deixa de ser pré-requisito para o painel existir, e passa a
ser escolha de quando pagar aquele custo.

Regra que sai disso, e que vale para os próximos produtos: **antes de otimizar
uma tela densa ao vivo, verificar se a pergunta do operador não é agregada.**
Costuma ser.

### Duas armadilhas que a construção revelou

**O agregado são DUAS consultas, não uma com dois JOIN.** Juntar `leads` e
`ligacao` no mesmo `GROUP BY` multiplica cada Lead por cada Ligação da Campanha e
infla toda contagem por um fator que muda conforme ela avança. É a pior espécie
de defeito de tela: números plausíveis. Travado por teste que usa quantidades
primas entre si (4 Leads, 3 Ligações), para que a inflação apareça como valor
impossível em vez de coincidência.

**`.rotulo` vence `text-primary`.** As classes utilitárias de `design/tokens.css`
vivem FORA de `@layer`, e as do Tailwind vivem dentro — CSS sem layer vence
qualquer layer, independente de ordem ou especificidade. Então `.rotulo`, que
define `color: var(--ink-muted)`, sobrescreve silenciosamente qualquer cor de
utilidade aplicada junto. Componente de estado não usa `.rotulo`; referencia os
tokens por valor arbitrário e mantém a cor sob controle da própria classe.

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

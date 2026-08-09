# ADR-0006 — Uma classe pura, usada pelo painel e pelo handler

**Data:** 2026-08-09
**Status:** aceito

## Contexto

Duas regras de negócio precisam rodar em dois lugares:

- **Resolver Placeholder por posição** (`{{1}}`, `{{2}}`, alinhado ao template da Meta) — o painel resolve no preview e no "testar Campanha"; o handler resolve na Ligação.
- **Validar dígito verificador de CPF** — o painel valida ao configurar a Campanha de captura; o handler valida o que o Lead digitou por DTMF antes de consultar a API.

O MagnusBilling é a demonstração do que acontece quando isso divergе. O AGI deles roda fora do framework e duplica a lógica do painel: existem duas classes `SearchTariff` com a mesma responsabilidade e assinaturas diferentes, montando o mesmo SQL de formas diferentes. A análise deles escreve: *"Divergirem é questão de tempo — e a divergência aparece como 'o simulador de tarifa do painel não bate com a conta real'."*

Com o módulo em C viraram **três** implementações do cálculo de custo, com **três** resultados diferentes. A própria análise se corrigiu duas vezes sobre qual estava certa.

Prospecta tem a vantagem estrutural de o handler e o painel estarem na mesma base de código. Mas ela **não vem de graça** — vem se for deliberada.

## Decisão

Cada regra dessas é **uma classe**, usada pelos dois. E, porque o handler não sobe framework (ADR-0001), as classes compartilhadas são **puras**:

- sem `app()`, sem container, sem facade
- sem model de framework, sem Eloquent
- entrada e saída são tipos da linguagem
- teste unitário sem banco, sem Asterisk, sem HTTP

## Consequências

- Uma classe que precise de `app()` não é compartilhável. Isso é o critério, não uma preferência de estilo.
- É o primeiro código de aplicação que vale escrever: puro por exigência, e coberto por teste sem infraestrutura nenhuma.
- Se um dia o handler for reescrito noutra linguagem, esta decisão é o que se perde primeiro — é a razão registrada no ADR-0004 para não fazer isso.

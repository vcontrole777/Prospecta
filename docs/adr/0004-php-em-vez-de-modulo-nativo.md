# ADR-0004 — PHP, não módulo em C nem serviço em Go/Rust

**Data:** 2026-08-09
**Status:** aceito

## Contexto

O MagnusBilling comercializa um módulo Asterisk em C (`app_mbilling`) que substitui o caminho de chamada em PHP, motivado por custo por chamada. A pergunta natural é se Prospecta deveria fazer o mesmo, em C, Go ou Rust.

O resultado deles é evidência direta, e é negativa. O protótipo em C perdeu revendedor, ofertas, LCR, tarifa customizada, restrição de número e portabilidade; introduziu cobrança em dobro, porque a regra de débito vivia num trigger invisível para um cliente escrito noutra linguagem; e passou a ser a **terceira** implementação do cálculo de custo. As três divergem, e a análise se corrigiu duas vezes sobre qual estava certa antes de concluir que a versão em C sobrecobrava ~25% num caso.

### Módulo `.so` dentro do Asterisk

Dois custos decisivos. A API interna do Asterisk não é estável entre majors — o fonte comercial deles se chama `app_mbilling_asterisk_20.c`, um arquivo por versão, que é o mesmo custo de build por VPS que descartou o Swoole (ADR-0001). E um segfault no módulo derruba o processo do Asterisk: **as 200 Ligações, não uma**, invertendo o isolamento de falha que o ADR-0001 escolheu de propósito. Somado a isso, C é a pior linguagem para receber a entrada hostil do ADR-0003.

### Serviço FastAGI em Go ou Rust

Tecnicamente muito melhor: segue FastAGI sobre TCP, sem acoplamento à API do Asterisk, sem build por versão, e a morte do serviço não mata o Asterisk. O ganho contra o handler PHP com bootstrap mínimo:

| | Go | PHP mínimo | Ganho |
|---|---|---|---|
| RAM, 200 canais | ~20 MB | ~1 GB | 6% de 16 GB |
| CPU de spawn | 0 | ~17% de 1 core | 2% de 8 cores |

O custo é a decisão do ADR-0006: resolver Placeholder e validar dígito verificador de CPF como **uma** classe usada pelo painel e pelo handler. Em Go elas existem duas vezes. Trocar 6% de RAM por recriar o defeito central do sistema que serviu de referência não fecha.

## Decisão

O caminho de chamada é PHP: handler FastAGI por processo, conforme ADR-0001.

**Exceção nomeada:** a objeção da duplicação só vale para componente que carrega regra de negócio. A futura ponte de mídia do agente de IA — áudio bidirecional contínuo, WebSocket, latência baixa — não carrega nenhuma: ela move frames. Go ali é adequado e não contradiz este ADR. Rust só se houvesse DSP próprio.

## Consequências

- Uma linguagem no caminho de chamada, uma no painel, e são a mesma.
- Deploy é `git pull` mais migração. Sem artefato binário por arquitetura, sem segundo serviço supervisionado por VPS.
- Três das quatro pressões que produziram o módulo deles não existem aqui: escala de operadora, multi-tenant com revenda, e débito de saldo por chamada. A quarta — conexão de banco presa pela Ligação inteira — está resolvida no ADR-0001.

## Revisitar se

Uma das duas medições do #13 contrariar a estimativa: RSS do handler 5–10× acima dos ~5 MB, ou PHP não sustentar ~5,7 originações por segundo via socket do systemd.

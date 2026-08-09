# Protótipo do fluxo da URA — ticket #10

Descartável. Existe para **medir**, não para virar o sistema.

O sistema real conduz a chamada por **FastAGI** (decisão do [#4](https://github.com/vcontrole777/Prospecta/issues/4)). Aqui é **dialplan puro de propósito**: todas as perguntas do #10 são sobre o caminho de áudio, e nenhuma delas precisa do servidor FastAGI existir. Um arquivo, três variantes, e os números saem do CDR e do log.

## O que este protótipo responde

| Pergunta | Como sai |
|---|---|
| Quanto o AMD atrasa o áudio | diferença entre as variantes `completo` e `sem-amd` |
| O silêncio inicial faz o Lead desligar | `AMDSTATUS = HANGUP` — conta exatamente isso |
| O barge-in funciona no primeiro segundo | `READSTATUS = INTERRUPTED` com tecla no início da Mensagem |
| Com que frequência falamos por cima do Lead | `WAITSTATUS = TIMEOUT` (ainda falando após 2s) |
| `WaitForSilence` vale a pena | diferença entre `completo` e `sem-silence` |
| Onde o AMD está decidindo | distribuição de `AMDCAUSE` |
| O AMD está **acertando** | **só ouvindo as gravações** — ver abaixo |
| Que código SIP a rota devolve | `HANGUPCAUSE(...,tech)` por condição discada |
| `MixMonitor` e `AMD` convivem | se a gravação sai íntegra com a variante `completo` |

## O que ele NÃO responde sem tronco real

O [#13](https://github.com/vcontrole777/Prospecta/issues/13) precisa acontecer para valer: **código SIP por condição**, **precisão do AMD contra caixa postal brasileira de verdade**, e **qualidade do áudio a 8 kHz numa operadora**. Rodar contra ramal local mede a mecânica, não a realidade.

## Como rodar

1. Incluir `dialplan.conf` no `extensions.conf`.
2. Áudios em `/var/lib/asterisk/sounds/prospecta/`, **já em 8 kHz mono alaw** — converter antes, nunca na ligação (decisão do [#8](https://github.com/vcontrole777/Prospecta/issues/8)):

   ```
   espera.alaw       tocado DURANTE o AMD. Obrigatório — sem ele o Lead ouve
                     1 a 5 segundos de silêncio absoluto e desliga
   mensagem.alaw     a Mensagem. Longa o suficiente para dar tempo de digitar no meio
   optin.alaw
   optout.alaw
   naoentendi.alaw   prompt CURTO, não a Mensagem inteira
   ```

3. Originar pelo AMI com `Variable: VARIANTE=completo`.

**Rode as três variantes contra os mesmos números.** A diferença entre elas é o que isola o custo de cada etapa — é o desenho experimental, não capricho.

| Variante | Fluxo |
|---|---|
| `completo` | AMD + `WaitForSilence` + Mensagem — o desenho proposto |
| `sem-amd` | só Mensagem — mede o ar morto que o AMD custa |
| `sem-silence` | AMD + Mensagem — mede o efeito do `WaitForSilence` |

## Discar de propósito para cada condição

Isto é o [#13](https://github.com/vcontrole777/Prospecta/issues/13) e é o dado que **nenhum provedor documenta honestamente**:

| Condição | Como conseguir |
|---|---|
| Número inexistente | número com DDD válido e assinante inexistente |
| Ocupado | ligar para um número em conversa |
| Aparelho desligado | celular seu, desligado |
| Caixa postal | celular seu, sem atender até cair |
| Humano | você mesmo atendendo |

Anotar para cada: `HANGUPCAUSE(...,tech)`, `HANGUPCAUSE(...,ast)`, `AMDSTATUS`, `AMDCAUSE`, e o que o CDR mostra.

**A linha da caixa postal é a mais importante.** A pesquisa do [#5](https://github.com/vcontrole777/Prospecta/issues/5) indicou que no celular brasileiro ela normalmente **atende** — devolve `200 OK` e toca anúncio — e portanto o Pré-atendimento não a pega e ela cai toda no AMD. Se a sua rota devolver `480` em vez de atender, isso vale mais que centavos no minuto.

## O AMD só se calibra ouvindo

**Falso positivo do AMD não é medível a partir do dado.** Humano classificado como máquina é desligado e **não gera erro nenhum** — nenhum evento, nenhum log, nenhuma reclamação.

Por isso o `MixMonitor` está no protótipo desde a primeira linha. O procedimento:

1. Rodar algumas centenas de ligações
2. Filtrar as que deram `AMDSTATUS = MACHINE`
3. **Ouvir uma amostra**
4. Contar quantas eram humano

Esse número é a taxa de falso positivo, e é o único jeito de saber se os valores abaixo servem.

## Os valores do AMD e a hipótese que eles carregam

```
AMD(2500, 2200, 800, 5000, 100, 50, 3, 256, 5000, prospecta/espera)
     ↑     ↑                          ↑
     |     greeting 2200 (padrão 1500)
     |                                maxWords 3 (padrão 2)
     initialSilence
```

Os dois ajustes reduzem **falso-MÁQUINA**, que é o erro caro.

⚠️ **É hipótese, não medição.** Ela supõe que caixa postal de celular brasileiro **começa a falar de imediato**, sem silêncio inicial — e por isso o `INITIALSILENCE` quase nunca dispararia, jogando o peso em `greeting` e `maximumNumberOfWords`.

**A distribuição de `AMDCAUSE` testa isso diretamente:**

| Predomina | Significa |
|---|---|
| `INITIALSILENCE` | **hipótese errada** — a caixa postal aqui tem silêncio inicial, e o ajuste vai no sentido oposto |
| `LONGGREETING` ou `MAXWORDS` | hipótese certa, e são esses os botões |
| `NOTSURE` / `TOOLONG` | o teto de 5s está sendo batido, áudio ambíguo — **é o caso a favor do WebRTC VAD** (ver #4) |

## Decisões que o protótipo materializa

- **`NOTSURE` segue como HUMANO**, não como máquina. Falso-MÁQUINA é o erro caro e invisível.
- **A ação dispara no instante da tecla**, antes do áudio de confirmação ([#6](https://github.com/vcontrole777/Prospecta/issues/6)).
- **Tecla inválida toca prompt curto**, não a Mensagem inteira.
- **Máquina desliga sem deixar recado** — sem ninguém para digitar tecla, não há Opt-in possível.
- **Gravação desde o ANSWER**, não desde o toque.

## Armadilhas embutidas no arquivo

- **`WaitForSilence(500,1,2)`**: `silencerequired` em **milissegundos**, `timeout` em **segundos**. `WaitForSilence(1000,1,3000)` dá timeout de 3000 **segundos**.
- **`AMDCAUSE` tem um valor que a documentação não lista**: `NOAUDIODATA`. Existe só no código-fonte. Se aparecer no log, não é bug.
- **A documentação do AMD não traz os valores padrão** — eles vêm do `amd.conf.sample`.
- **`dtmf_mode` precisa ser `rfc4733`** no tronco. Inband degrada a detecção em 8 kHz alaw e perde dígito, o que faria o barge-in parecer quebrado sem ser.

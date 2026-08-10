# Áudios de sistema

**Regra: nenhum áudio embutido do Asterisk chega ao Lead.**

Não porque falte pacote pt-BR, e sim porque voz de sistema no meio de uma
Campanha soa como outra pessoa falando. Todo áudio que o Lead ouve é do
projeto ou do dono da Campanha.

## Os dois áudios de sistema

Estes são fixos, do produto, e vivem aqui. Vão para
`/var/lib/asterisk/sounds/prospecta/` no provisionamento.

| Arquivo | Conteúdo | Duração |
|---|---|---|
| `abertura` | "Alô?" | **~1 s, não mais** |
| `naoentendi` | "Não entendi, vamos tentar de novo." | ~2 s |

### `abertura` tem um limite duro de duração

Ela toca imediatamente antes do `AMD()`. Áudio que invada a janela de análise
faz o classificador analisar **o próprio som do sistema** e devolver `HUMAN`
para todo mundo — o AMD para de classificar.

Foi verificado em chamada real que ~1 s não invade. **Se trocar o arquivo,
refazer a checagem:** ligar para um destino com a linha comprovadamente muda e
confirmar que o veredito continua `NOTSURE`.

## Os áudios de Campanha NÃO ficam aqui

Mensagem e Áudio de confirmação são enviados pelo dono da Campanha e vivem no
banco (`audio`) com o arquivo em disco. A verificação deles acontece na
ativação da Campanha, com causa nomeada (`AUDIO_MISSING`,
`AUDIO_INCOMPATIBLE`).

## Formato

```
8000 Hz · mono · alaw
```

`alaw` casando com o codec do tronco elimina transcodificação na reprodução.

### 🔴 O comando

```sh
sox entrada.wav -r 8000 -c 1 -t al abertura.alaw
```

**`-t al` é obrigatório.** Sem ele o `sox` recusa com
`no handler for file extension 'alaw'` — a extensão que o Asterisk usa não é uma
extensão que o `sox` reconheça. O tipo dele para A-law cru é `al`.

Uma versão anterior deste arquivo documentava o comando sem `-t al`, e com `-e
a-law`. Aquele comando **não funciona**. Verificado nesta máquina.

### 🔴 A verificação NÃO é com `soxi`

`soxi` não consegue ler um `.alaw`, e não é questão de sintaxe: em `soxi` a opção
`-t` significa *mostre o tipo*, não *assuma este tipo*. Não existe como informar o
formato. E o motivo é mais fundo — **arquivo raw não tem cabeçalho**, então não há
nada dentro dele dizendo 8000 Hz.

O que verifica é o **tamanho**. A 8 kHz, mono, A-law, cada amostra é um byte:

```
8000 bytes por segundo, exatos
```

Medido: 2 s viraram 16000 bytes. A mesma conversão sem `-r 8000 -c 1` produziu
176400 bytes — **11 vezes mais**. O tamanho delata taxa e canal errados, que é
exatamente o modo de falha do qual este arquivo avisa.

```sh
sox entrada.wav -r 8000 -c 1 -t al abertura.alaw
stat -c %s abertura.alaw     # dividir por 8000 = duração em segundos
```

Isso ganha um segundo uso que o `soxi` nunca teve: como o tamanho **é** a duração,
verificar tamanho impõe o limite da `abertura` descrito acima — o limite que existe
para não invadir a janela do AMD.

## Por que a verificação é obrigatória

MEDIDO: `Playback` em arquivo ausente devolve `PLAYBACKSTATUS=FAILED` **e o
dialplan continua**. O Asterisk registra o aviso, o Lead ouve silêncio, e o
fluxo segue como se tudo estivesse bem.

Por isso o provisionamento falha se os arquivos não estiverem aqui, e o dialplan
checa `PLAYBACKSTATUS` a cada reprodução.

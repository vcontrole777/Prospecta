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

Conferir com `soxi` depois de converter — `sox` e `ffmpeg` terminam com sucesso
e parâmetro errado, e conversão que "deu certo" em estéreo 44,1 kHz é silêncio
na chamada.

```sh
sox entrada.wav -r 8000 -c 1 -e a-law abertura.alaw
soxi abertura.alaw     # confirmar: 8000 Hz, 1 canal
```

## Por que a verificação é obrigatória

MEDIDO: `Playback` em arquivo ausente devolve `PLAYBACKSTATUS=FAILED` **e o
dialplan continua**. O Asterisk registra o aviso, o Lead ouve silêncio, e o
fluxo segue como se tudo estivesse bem.

Por isso o provisionamento falha se os arquivos não estiverem aqui, e o dialplan
checa `PLAYBACKSTATUS` a cada reprodução.

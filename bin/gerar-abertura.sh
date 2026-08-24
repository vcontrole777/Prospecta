#!/usr/bin/env bash
#
# Condiciona o áudio de abertura no `abertura.alaw` que o Asterisk espera.
#
# A entrada é a SAÍDA DO TTS — os dois áudios de sistema do produto são gerados,
# não gravados. Este script não chama o TTS: recebe o arquivo pronto e o ajusta.
#
# Uso:  bash bin/gerar-abertura.sh /caminho/da/saida-do-tts.m4a
#
# Aceita qualquer formato que o ffmpeg leia (wav, mp3, m4a, ogg). Produz
# 8000 Hz, mono, A-law cru — o formato do `audios/README.md`.
#
# Faz três coisas além da conversão, e as três importam para o AMD:
#
#   · APARA o silêncio do começo e do fim. Silêncio à frente do "Alô?" consome
#     o orçamento de `initial_silence` (2500 ms em amd.conf) sem que ninguém
#     tenha falado — o classificador começa a contar antes do áudio existir.
#   · NORMALIZA o volume. Áudio de nível baixo fica abaixo do `silence_threshold`
#     (256) e o AMD a lê como silêncio, que é o modo de falha que estamos
#     consertando.
#   · CONFERE o tamanho. A 8 kHz mono A-law, 1 byte = 1 amostra, então
#     8000 bytes = 1 segundo exato. O teto da abertura é 12000 bytes (1,5 s):
#     acima disso o áudio invade a janela de análise do AMD e o classificador
#     passa a analisar o próprio som do sistema.

set -euo pipefail

ENTRADA="${1:?uso: $0 <arquivo-de-audio>}"
# 🔴 DERIVADO da posição do script, nunca fixo. Estava cravado numa pasta com o
# nome de um zip extraído, então rodar a partir de um clone reescrevia o áudio do
# OUTRO diretório e o clone continuava com o arquivo velho — sem erro nenhum.
RAIZ="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
DESTINO_REPO="$RAIZ/audios/abertura.alaw"
DESTINO_ASTERISK="/var/lib/asterisk/sounds/prospecta/abertura.alaw"
TETO_BYTES=12000

[ -r "$ENTRADA" ] || { echo "não consigo ler: $ENTRADA" >&2; exit 1; }

TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

echo "==> decodificando"
ffmpeg -loglevel error -y -i "$ENTRADA" -ar 8000 -ac 1 "$TMP/bruto.wav"

echo "==> aparando silêncio e normalizando"
# 1 0.05 1%   : corta o silêncio inicial até o primeiro som acima de 1%
# reverse ... : mesma coisa no fim, invertendo duas vezes
# norm         : nivel de pico. -12 dBFS por padrao, NAO -3: MEDIDO que -3 volta
#                por eco no celular e o AMD conta o eco como a saudacao da
#                pessoa, devolvendo HUMAN ate para linha muda.
sox "$TMP/bruto.wav" "$TMP/limpo.wav" \
    silence 1 0.05 1% reverse silence 1 0.05 1% reverse norm "${NIVEL_DB:--12}"

echo "==> convertendo para A-law cru"
# `-t al` é obrigatório: o sox não reconhece a extensão .alaw, e o tipo dele
# para A-law cru é `al`. Ver audios/README.md.
sox "$TMP/limpo.wav" -r 8000 -c 1 -t al "$TMP/abertura.alaw"

BYTES=$(stat -c %s "$TMP/abertura.alaw")
DUR=$(awk -v b="$BYTES" 'BEGIN{printf "%.2f", b/8000}')

echo
echo "    duração: ${DUR}s  (${BYTES} bytes, teto ${TETO_BYTES} = 1,50s)"

if [ "$BYTES" -eq 0 ]; then
    echo "    RECUSADO: arquivo vazio — o TTS devolveu só silêncio?" >&2
    exit 1
fi

if [ "$BYTES" -gt "$TETO_BYTES" ]; then
    echo "    RECUSADO: passa do teto. Gere um 'Alô?' mais curto e seco." >&2
    exit 1
fi

install -D -m 0644 "$TMP/abertura.alaw" "$DESTINO_REPO"
install -o asterisk -g asterisk -m 0640 "$TMP/abertura.alaw" "$DESTINO_ASTERISK"

echo
echo "==> instalado"
echo "    $DESTINO_REPO"
echo "    $DESTINO_ASTERISK"
echo
echo "Agora REFAÇA a verificação do AMD (audios/README.md exige isso a cada troca):"
echo "  asterisk -rx 'channel originate PJSIP/99901<numero>@rota extension s@prospecta-teste-dtmf'"
echo "  grep '>>>TESTE AMD' /var/log/asterisk/messages.log | tail -1"

#!/usr/bin/env bash
#
# Compila o Asterisk UMA VEZ e produz um .deb versionado.
#
# Por que artefato em vez de compilar por cliente:
#   - provisionamento rápido (minutos de compilação x segundos de instalação)
#   - versão IDÊNTICA em todas as instâncias, não "a mesma versão
#     compilada N vezes com resultados que podem divergir"
#   - rollback trivial: instala o .deb anterior
#
# Rode numa máquina Ubuntu 24.04 limpa, da MESMA arquitetura das instâncias.
# Publique o resultado onde o playbook alcance e aponte asterisk_url_artefato.

set -euo pipefail

VERSAO="${1:-22.10.1}"
TRABALHO="$(mktemp -d)"
STAGE="${TRABALHO}/stage"
trap 'rm -rf "${TRABALHO}"' EXIT

echo "==> Asterisk ${VERSAO}"

echo "==> Dependências de compilação"
apt-get update
apt-get install -y --no-install-recommends \
  build-essential wget libedit-dev libjansson-dev libsqlite3-dev \
  uuid-dev libxml2-dev libssl-dev libcurl4-openssl-dev pkg-config

echo "==> Fonte"
cd "${TRABALHO}"
wget -q "https://downloads.asterisk.org/pub/telephony/asterisk/asterisk-${VERSAO}.tar.gz"
tar xzf "asterisk-${VERSAO}.tar.gz"
cd "asterisk-${VERSAO}"

echo "==> configure"
./configure --with-jansson-bundled --with-pjproject-bundled

echo "==> menuselect"
make menuselect.makeopts

# app_amd é o classificador pós-atendimento — sem ele o produto não
# distingue humano de caixa postal, e caixa postal ATENDE (manda 200 OK).
# res_pjsip é obrigatório: chan_sip foi removido no Asterisk 21.
menuselect/menuselect \
  --enable app_amd \
  --enable app_mixmonitor \
  --enable app_waitforsilence \
  --enable app_read \
  --enable res_pjsip \
  --enable res_agi \
  --enable format_gsm \
  --enable codec_alaw \
  --enable codec_ulaw \
  menuselect.makeopts

echo "==> make"
make -j"$(nproc)"

echo "==> instalar em árvore de estágio"
mkdir -p "${STAGE}"
make DESTDIR="${STAGE}" install
make DESTDIR="${STAGE}" samples

# ------------------------------------------------------------------
# Unidade do systemd.
#
# VERIFICADO POR FALHA: `make install` do Asterisk NÃO instala unidade de
# systemd — as distribuições adicionam a própria. Sem isto, o playbook
# instala o pacote e falha em seguida com
#   "Could not find the requested service asterisk"
# ------------------------------------------------------------------
echo "==> unidade do systemd"
mkdir -p "${STAGE}/lib/systemd/system"
cat > "${STAGE}/lib/systemd/system/asterisk.service" <<'UNIT'
[Unit]
Description=Asterisk PBX
Documentation=https://docs.asterisk.org
After=network-online.target mysql.service
Wants=network-online.target

[Service]
Type=simple
User=asterisk
Group=asterisk

# -f mantém em primeiro plano, que é o que Type=simple espera.
ExecStart=/usr/sbin/asterisk -f -U asterisk -G asterisk
ExecReload=/usr/sbin/asterisk -rx "core reload"

Restart=always
RestartSec=5

# Cria /run/asterisk com o dono certo a cada boot.
RuntimeDirectory=asterisk
RuntimeDirectoryMode=0750

# Uma chamada consome descritores; {{ canais }} canais consomem muitos.
LimitNOFILE=65535

# Prioridade de tempo real para a mídia. Sem isto, jitter sob carga.
LimitRTPRIO=infinity
LimitMEMLOCK=infinity

[Install]
WantedBy=multi-user.target
UNIT

echo "==> metadados do pacote"
mkdir -p "${STAGE}/DEBIAN"
cat > "${STAGE}/DEBIAN/control" <<CONTROL
Package: asterisk
Version: ${VERSAO}
Section: comm
Priority: optional
Architecture: amd64
Depends: libedit2, libjansson4, libsqlite3-0, libuuid1, libxml2, libssl3, libcurl4
Maintainer: Prospecta <ops@exemplo.com>
Description: Asterisk ${VERSAO} para o Prospecta
 Compilado com app_amd, app_mixmonitor, app_waitforsilence e res_pjsip.
 Artefato gerado por provisionamento/build-asterisk.sh — versao identica
 em todas as instancias.
CONTROL

cat > "${STAGE}/DEBIAN/postinst" <<'POSTINST'
#!/bin/sh
set -e
if ! getent passwd asterisk >/dev/null; then
  adduser --system --group --no-create-home --disabled-login asterisk
fi
install -d -o asterisk -g asterisk /var/lib/asterisk /var/log/asterisk /var/spool/asterisk
ldconfig
systemctl daemon-reload || true
POSTINST
chmod 0755 "${STAGE}/DEBIAN/postinst"

SAIDA="/tmp/asterisk_${VERSAO}_amd64.deb"
dpkg-deb --build --root-owner-group "${STAGE}" "${SAIDA}"

echo
echo "==> pronto: ${SAIDA}"
echo "    Publique e aponte asterisk_url_artefato em group_vars/all.yml"

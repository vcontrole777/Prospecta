#!/usr/bin/env bash
#
# Instala uma instância do Prospecta em UM comando, numa VPS Ubuntu 24.04 nua.
#
# Como este arquivo chega lá — o repositório é privado, então não há `raw.` que
# funcione sem autenticação:
#
#   T=ghp_...
#   curl -fsSL -H "Authorization: Bearer $T" -H "Accept: application/vnd.github.raw" \
#     https://api.github.com/repos/vcontrole777/Prospecta/contents/provisionamento/install.sh \
#     -o install.sh
#   PROSPECTA_TOKEN=$T bash install.sh
#
# 🔴 ESTE SCRIPT NÃO INSTALA NADA. Quem instala é o `site.yml` — o MESMO playbook
# que converge a máquina depois, porque instalar e atualizar são o mesmo
# mecanismo (ver README). Uma segunda implementação da instalação, em bash,
# divergiria da primeira na primeira correção: seria preciso lembrar de arrumar
# a chave do MySQL, a permissão do diretório de gravação e o dono do pool do
# php-fpm em dois lugares, e o segundo lugar é o que ninguém executa.
#
# Aqui só existe o que o playbook não consegue fazer sozinho numa máquina nua:
#
#   1. buscar o código, que está num repositório privado
#   2. responder as sete variáveis de `host_vars` sem um humano editando YAML
#   3. criar o operador do painel, sem o qual ninguém entra
#
# O resto é `ansible-playbook`.

set -euo pipefail

REPO="vcontrole777/Prospecta"
FONTE="/usr/local/src/prospecta"      # o código de onde o playbook roda
CONFIG="/etc/prospecta/instancia.yml" # as respostas, fora da árvore de código
ATUALIZAR="no"

[ "${1:-}" = "--atualizar" ] && ATUALIZAR="sim"

erro() { echo; echo "ERRO: $*" >&2; exit 1; }
passo() { echo; echo "==> $*"; }

echo
echo "============================================================"
echo "  Prospecta — instalação de instância"
echo "============================================================"

# ------------------------------------------------------------------
# Portões. Falhar aqui é barato; falhar na metade da instalação não é.
# ------------------------------------------------------------------

[ "${EUID}" -eq 0 ] || erro "rode como root."

[ -r /etc/os-release ] || erro "/etc/os-release não existe — distribuição não identificada."
# shellcheck disable=SC1091
. /etc/os-release

# 24.04 e não "Ubuntu qualquer": é a ÚNICA distribuição em que este
# provisionamento foi executado de ponta a ponta (README, tabela de procedência).
# O PPA do PHP, o componente `mysql-8.4-lts` do repositório da Oracle e o
# `.deb` do Asterisk são todos específicos de noble.
if [ "${ID:-}" != "ubuntu" ] || [ "${VERSION_ID:-}" != "24.04" ]; then
    erro "esta instalação é para Ubuntu 24.04. Detectado: ${PRETTY_NAME:-desconhecido}."
fi

if [ "${ATUALIZAR}" = "no" ] && [ -f /opt/prospecta/artisan ]; then
    echo
    echo "Esta máquina JÁ tem o Prospecta instalado."
    echo
    echo "Para atualizar, rode:  bash install.sh --atualizar"
    echo "(baixa o release novo e reexecuta o playbook — instalar e atualizar"
    echo " são o mesmo mecanismo, e reexecutar é seguro.)"
    exit 1
fi

if [ "${ATUALIZAR}" = "sim" ] && [ ! -f "${CONFIG}" ]; then
    erro "--atualizar precisa de ${CONFIG}, que não existe. Esta máquina foi instalada de outro jeito."
fi

# ------------------------------------------------------------------
# Respostas. Tudo aceita variável de ambiente, para instalação desassistida.
# ------------------------------------------------------------------

TOKEN="${PROSPECTA_TOKEN:-}"
if [ -z "${TOKEN}" ]; then
    echo
    echo "O repositório é privado, então o código vem de um release autenticado."
    echo "Cole um token do GitHub com escopo 'repo' (não aparece na tela):"
    read -rs TOKEN
    echo
fi
[ -n "${TOKEN}" ] || erro "sem token não há como baixar o código."

TAG="${PROSPECTA_VERSAO:-}"   # vazio = release mais recente

if [ "${ATUALIZAR}" = "no" ]; then
    IP_PUBLICO="$(curl -fsS --max-time 10 https://api.ipify.org 2>/dev/null || hostname -I | awk '{print $1}')"

    DOMINIO="${PROSPECTA_DOMINIO:-}"
    if [ -z "${DOMINIO}" ]; then
        echo
        echo "Domínio desta instância (ex: cliente-x.exemplo.com)."
        echo "Deixe vazio para usar o IP ${IP_PUBLICO} — instância SEM HTTPS, só para teste:"
        read -r DOMINIO
    fi
    [ -n "${DOMINIO}" ] || DOMINIO="${IP_PUBLICO}"

    # O teste de "tem letra" é o mesmo de `https_ativo` em group_vars: nome de
    # domínio sempre tem letra, IPv4 nunca.
    if echo "${DOMINIO}" | grep -q '[a-zA-Z]'; then
        HTTPS="sim"
        # 🔴 AVISO, NÃO BLOQUEIO. O certificado é emitido por validação HTTP: se o
        # DNS ainda não aponta para cá, o certbot falha e leva o playbook junto,
        # depois de tudo já estar instalado. Avisar ANTES é a diferença entre
        # "espere o DNS propagar" e "por que isto quebrou no fim".
        RESOLVIDO="$(getent hosts "${DOMINIO}" | awk '{print $1}' | head -n1 || true)"
        if [ "${RESOLVIDO}" != "${IP_PUBLICO}" ]; then
            echo
            echo "AVISO: ${DOMINIO} resolve para '${RESOLVIDO:-nada}', e esta máquina é ${IP_PUBLICO}."
            echo "       O Let's Encrypt valida por HTTP e vai FALHAR assim."
            echo "       Sem certificado válido a Meta recusa o webhook do WhatsApp (#24)."
            echo
            printf "Continuar mesmo assim? [s/N] "
            read -r RESP
            [ "${RESP}" = "s" ] || [ "${RESP}" = "S" ] || erro "ajuste o DNS e rode de novo."
        fi
    else
        HTTPS="nao"
    fi

    EMAIL_CERT="${PROSPECTA_EMAIL:-}"
    if [ -z "${EMAIL_CERT}" ] && [ "${HTTPS}" = "sim" ]; then
        echo
        echo "E-mail para o Let's Encrypt (avisos de expiração do certificado):"
        read -r EMAIL_CERT
    fi
    [ -n "${EMAIL_CERT}" ] || EMAIL_CERT="ops@${DOMINIO}"

    CANAIS="${PROSPECTA_CANAIS:-}"
    if [ -z "${CANAIS}" ]; then
        echo
        echo "Canais contratados [60]:"
        read -r CANAIS
    fi
    [ -n "${CANAIS}" ] || CANAIS=60
    echo "${CANAIS}" | grep -qE '^[0-9]+$' || erro "canais precisa ser um número inteiro."

    EMAIL_OPERADOR="${PROSPECTA_OPERADOR:-}"
    if [ -z "${EMAIL_OPERADOR}" ]; then
        echo
        echo "E-mail de acesso ao painel:"
        read -r EMAIL_OPERADOR
    fi
    echo "${EMAIL_OPERADOR}" | grep -q '@' || erro "e-mail do operador inválido."

    # Segredos. `head -c` lê e sai; nada de `tr -dc < /dev/urandom | head`, que
    # mata o `tr` com SIGPIPE e derruba o script sob `set -o pipefail`.
    #
    # 🔴 A APP_KEY É GERADA UMA VEZ E NUNCA MAIS. Trocar depois invalida toda
    # sessão E torna ilegível todo valor cifrado no banco — a senha da Rota entre
    # eles. Por isso ela vive em ${CONFIG}, fora da árvore de código, e o
    # --atualizar relê em vez de gerar outra. São 32 bytes: o que o AES-256-CBC
    # do Laravel espera.
    BANCO_SENHA="$(head -c 24 /dev/urandom | base64 | tr -d '/+=' | cut -c1-24)"
    AMI_SEGREDO="$(head -c 24 /dev/urandom | base64 | tr -d '/+=' | cut -c1-24)"
    SENHA_OPERADOR="$(head -c 20 /dev/urandom | base64 | tr -d '/+=' | cut -c1-20)"
    APP_KEY="base64:$(head -c 32 /dev/urandom | base64 -w0)"

    passo "Gravando as respostas em ${CONFIG}"
    mkdir -p /etc/prospecta
    chmod 0700 /etc/prospecta
    cat > "${CONFIG}" <<YAML
---
# Respostas desta instância, geradas por install.sh.
#
# 🔴 NÃO APAGUE E NÃO EDITE app_key: trocar a chave torna ilegível tudo que já
# está cifrado no banco. Este arquivo é a única cópia.
#
# É daqui que --atualizar relê a configuração; a árvore de código é substituída
# a cada atualização e não pode guardar estado.
dominio: ${DOMINIO}
email_certificado: ${EMAIL_CERT}
banco_senha: "${BANCO_SENHA}"
app_key: ${APP_KEY}
ami_usuario: prospecta
ami_segredo: "${AMI_SEGREDO}"
canais_contratados: ${CANAIS}
YAML
    chmod 0600 "${CONFIG}"
else
    DOMINIO="$(sed -n 's/^dominio: *//p' "${CONFIG}")"
    echo
    echo "Atualizando a instância de ${DOMINIO}."
fi

# ------------------------------------------------------------------
# O que o playbook precisa para existir. Só isto — o resto ele instala.
# ------------------------------------------------------------------

passo "Pacotes do bootstrap"
export DEBIAN_FRONTEND=noninteractive
apt-get update -qq
apt-get install -y -qq ansible-core curl ca-certificates python3 sudo tar >/dev/null

passo "Baixando o código"

# Duas etapas de propósito: a API do GitHub responde o download de asset com um
# redirecionamento para outro domínio, e o `curl -L` reenvia o cabeçalho
# Authorization para lá — o armazenamento recusa com "only one auth mechanism
# allowed". Pegar a URL do redirecionamento e baixar dela, sem cabeçalho, evita
# a classe inteira.
API="https://api.github.com/repos/${REPO}/releases"
if [ -n "${TAG}" ]; then
    RELEASE_JSON="$(curl -fsSL -H "Authorization: Bearer ${TOKEN}" -H "Accept: application/vnd.github+json" "${API}/tags/${TAG}")" \
        || erro "release '${TAG}' não encontrado (ou o token não tem acesso ao repositório)."
else
    RELEASE_JSON="$(curl -fsSL -H "Authorization: Bearer ${TOKEN}" -H "Accept: application/vnd.github+json" "${API}/latest")" \
        || erro "nenhum release publicado (ou o token não tem acesso). Publique um com o workflow 'release'."
fi

# O NOME do asset carrega a versão e vai mudar; o SUFIXO não. Casar por sufixo
# evita que o instalador precise saber como o workflow nomeia o arquivo.
leia_release() { python3 -c "$1" <<<"${RELEASE_JSON}"; }
TAG_ENCONTRADA="$(leia_release 'import json,sys; print(json.load(sys.stdin)["tag_name"])')"
ASSET_ID="$(leia_release 'import json,sys; a=[x for x in json.load(sys.stdin)["assets"] if x["name"].endswith(".tar.gz")]; print(a[0]["id"] if a else "")')"
[ -n "${ASSET_ID}" ] || erro "o release ${TAG_ENCONTRADA} não tem nenhum .tar.gz anexado."

URL_ASSET="$(curl -sS -o /dev/null -w '%{redirect_url}' \
    -H "Authorization: Bearer ${TOKEN}" -H "Accept: application/octet-stream" \
    "${API}/assets/${ASSET_ID}")"
[ -n "${URL_ASSET}" ] || erro "o GitHub não devolveu a URL do artefato."

curl -fSL --progress-bar -o /tmp/prospecta-release.tar.gz "${URL_ASSET}" \
    || erro "falha ao baixar o artefato da aplicação."

echo "    ${TAG_ENCONTRADA}"

# Apaga a árvore anterior inteira: código velho que sobrevive a uma atualização
# é o defeito que custou um deploy neste projeto (ver "Limpar o código anterior"
# no site.yml — o Laravel carrega TODO arquivo de `config/`).
rm -rf "${FONTE}"
mkdir -p "${FONTE}"
tar xzf /tmp/prospecta-release.tar.gz -C "${FONTE}"
rm -f /tmp/prospecta-release.tar.gz

[ -f "${FONTE}/provisionamento/site.yml" ] || erro "o artefato não tem provisionamento/site.yml dentro."
[ -f "${FONTE}/public/build/manifest.json" ] || erro "o artefato veio sem os assets construídos. O workflow de release roda 'npm run build' — este arquivo não passou por ele."

# ------------------------------------------------------------------
# Asterisk. PRÉ-INSTALADO aqui, e não pelo playbook, por um motivo só: o
# `get_url` do playbook não manda cabeçalho de autenticação, e o `.deb` está num
# release privado. A tarefa lá é guardada por `asterisk -V` — com o pacote já
# instalado ela sai como pulada, que é exatamente o comportamento desenhado.
# ------------------------------------------------------------------

ASTERISK_VERSAO="$(sed -n 's/^asterisk_versao: *"\([0-9.]*\)".*/\1/p' "${FONTE}/provisionamento/group_vars/all.yml")"
[ -n "${ASTERISK_VERSAO}" ] || erro "não consegui ler asterisk_versao de group_vars/all.yml."

if asterisk -V 2>/dev/null | grep -q "${ASTERISK_VERSAO}"; then
    passo "Asterisk ${ASTERISK_VERSAO} já instalado"
else
    passo "Asterisk ${ASTERISK_VERSAO}"
    AST_JSON="$(curl -fsSL -H "Authorization: Bearer ${TOKEN}" -H "Accept: application/vnd.github+json" \
        "${API}/tags/asterisk-${ASTERISK_VERSAO}")" \
        || erro "não existe o release 'asterisk-${ASTERISK_VERSAO}'. Gere o pacote com o workflow 'asterisk-artefato'."

    AST_ID="$(python3 -c 'import json,sys; a=[x for x in json.load(sys.stdin)["assets"] if x["name"].endswith(".deb")]; print(a[0]["id"] if a else "")' <<<"${AST_JSON}")"
    [ -n "${AST_ID}" ] || erro "o release asterisk-${ASTERISK_VERSAO} não tem .deb anexado."

    URL_AST="$(curl -sS -o /dev/null -w '%{redirect_url}' \
        -H "Authorization: Bearer ${TOKEN}" -H "Accept: application/octet-stream" \
        "${API}/assets/${AST_ID}")"
    curl -fSL --progress-bar -o "/tmp/asterisk_${ASTERISK_VERSAO}_amd64.deb" "${URL_AST}" \
        || erro "falha ao baixar o pacote do Asterisk."

    apt-get install -y -qq "/tmp/asterisk_${ASTERISK_VERSAO}_amd64.deb" >/dev/null
fi

# ------------------------------------------------------------------
# O playbook. Daqui para baixo, quem trabalha é ele.
# ------------------------------------------------------------------

cd "${FONTE}/provisionamento"

cat > inventario.yml <<'YAML'
---
# Gerado por install.sh. Uma máquina, ela mesma: o playbook nasceu para rodar de
# uma máquina de controle por SSH, e roda igual contra localhost.
todos:
  hosts:
    localhost:
      ansible_connection: local
YAML

cp "${CONFIG}" host_vars/localhost.yml
chmod 0600 host_vars/localhost.yml

passo "Provisionando (o playbook, de 5 a 15 minutos)"
echo

# `-e operador_criado_depois=true` desarma o portão de operador do site.yml. O
# portão existe porque implantação sem operador não é implantação concluída —
# o painel sobe e ninguém entra. Aqui ele estorvaria: a criação do operador é o
# passo seguinte deste script, e com o portão armado o playbook morreria antes
# de configurar o nginx, as unidades e o HTTPS.
ansible-playbook site.yml -e operador_criado_depois=true

# ------------------------------------------------------------------
# Operador.
# ------------------------------------------------------------------

PHP_VERSAO="$(sed -n 's/^php_versao: *"\([0-9.]*\)".*/\1/p' group_vars/all.yml)"

if [ "${ATUALIZAR}" = "no" ]; then
    passo "Criando o operador do painel"
    # A senha vai por stdin, não por `--senha`: a opção existe para automação e
    # o custo dela é aparecer no `ps` da máquina e no histórico do shell. O
    # comando pede sem eco e aceita o que vier na entrada padrão.
    printf '%s\n' "${SENHA_OPERADOR}" | \
        sudo -u prospecta "php${PHP_VERSAO}" /opt/prospecta/artisan prospecta:operador "${EMAIL_OPERADOR}" >/dev/null

    # Conferir em vez de confiar: se a senha não chegou pela entrada padrão, o
    # comando pode ter saído bem sem gravar nada, e a instalação terminaria
    # anunciando uma credencial que não existe.
    TOTAL="$(mysql --protocol=socket -N -B prospecta -e "SELECT COUNT(*) FROM users" | tr -d '[:space:]')"
    [ "${TOTAL}" -ge 1 ] || erro "o operador não foi criado. Rode à mão: sudo -u prospecta php${PHP_VERSAO} /opt/prospecta/artisan prospecta:operador ${EMAIL_OPERADOR}"
fi

# ------------------------------------------------------------------
# Resumo.
# ------------------------------------------------------------------

if echo "${DOMINIO}" | grep -q '[a-zA-Z]'; then URL="https://${DOMINIO}"; else URL="http://${DOMINIO}"; fi

echo
echo "============================================================"
echo "  Prospecta instalado"
echo "============================================================"
echo
echo "  Painel:  ${URL}"
if [ "${ATUALIZAR}" = "no" ]; then
echo "  Usuário: ${EMAIL_OPERADOR}"
echo "  Senha:   ${SENHA_OPERADOR}"
echo
echo "  🔴 ANOTE A SENHA AGORA. Ela não fica gravada em lugar nenhum — só o"
echo "     hash está no banco. Perdida, o conserto é redefinir:"
echo "     sudo -u prospecta php${PHP_VERSAO} /opt/prospecta/artisan prospecta:operador ${EMAIL_OPERADOR}"
fi
echo
echo "  Versão:        ${TAG_ENCONTRADA}"
echo "  Código:        /opt/prospecta"
echo "  Configuração:  ${CONFIG}   (contém a APP_KEY — inclua no backup)"
echo
echo "  ⚠️  FALTA UM PASSO, E SEM ELE NÃO SAI LIGAÇÃO:"
echo
echo "     Abra o painel e preencha a tela **Rota** — host, usuário, senha,"
echo "     from_domain e prefixo técnico da operadora. O playbook não gera esse"
echo "     arquivo de propósito: quem escreve nele é a tela, e um segundo"
echo "     escritor reverteria em silêncio o que o operador salvou."
echo
echo "     Até lá o dialer RECUSA originar e registra 'sem_rota'."
echo
echo "  Para atualizar depois:  bash ${FONTE}/provisionamento/install.sh --atualizar"
echo

# Provisionamento

Ansible para subir e **manter** uma instância do Prospecta. Decisão registrada em [#23](https://github.com/vcontrole777/Prospecta/issues/23).

A moldura que importa: **instalar e atualizar são o mesmo mecanismo.** Rodar `site.yml` numa máquina nova provisiona; rodar na mesma máquina depois converge o que derivou. Não existe passo manual de atualização.

## O que está verificado, e o que não está

Seguindo a disciplina de procedência do vault:

| | |
|---|---|
| **Executado** | `base`, `php`, `mysql`, `web`, `asterisk` numa Ubuntu 24.04.4 real (KVM, systemd 255, IP público sem NAT) |
| **Resultado** | PHP 8.4.24 · MySQL 8.4.11 · nginx 1.24.0 · 8 de 8 extensões · banco em `utf8mb4` · **Asterisk 22.10.1 ativo, 64 MB de RSS ocioso, 8 de 8 módulos críticos carregados, RTP 10000–20000, 5060 escutando** |
| **Idempotência provada** | segunda execução: **14 ok, 0 changed** |
| **Verificado sem executar** | `yamllint`, `--syntax-check`, `bash -n`, e os 9 templates Jinja renderizando com `StrictUndefined` |
| **Ainda não executado** | `app` (depende do código da aplicação), `https` (depende de domínio com DNS resolvendo) |

Trate a primeira execução como parte do trabalho, não como formalidade — esta encontrou **quatro** defeitos.

## 🔴 Se você reconstruir o artefato, MUDE a versão

O módulo `apt` é idempotente por versão: um `.deb` reconstruído com o mesmo número **não é reinstalado**, e a correção não chega à máquina. O playbook reporta `ok` e você acredita que aplicou.

Isso é comportamento correto — versão deveria ser imutável — mas é armadilha na primeira vez. Se o artefato mudar, o número muda.

## Quatro defeitos que só a execução encontrou

**`stdout_callback = yaml` não existe em `ansible-core` puro.** Aquele callback vive na coleção `community.general`. O playbook morria antes da primeira tarefa com `Invalid callback for stdout specified: yaml`. Removido, com comentário no `ansible.cfg` para ninguém readicionar.

**O `.deb` não trazia unidade do systemd.** `make install` do Asterisk não instala uma — as distribuições adicionam a própria. O pacote instalava e o playbook morria em seguida com `Could not find the requested service asterisk`. A unidade agora é gerada pelo `build-asterisk.sh`, com `RuntimeDirectory`, `LimitNOFILE`, e `LimitRTPRIO`/`LimitMEMLOCK` para prioridade de tempo real na mídia.

**A chave GPG do MySQL estava expirada.** `RPM-GPG-KEY-mysql-2023` venceu em **2025-10-22**, e o apt recusa o repositório com `EXPKEYSIG`. A Oracle renova a expiração e republica com o ano no nome, **mantendo a mesma impressão digital** — a `-2025` vale até **2027-10-23**.

Virou variável com a data de vencimento escrita ao lado, porque isto vai acontecer de novo. Quando o provisionamento falhar com `EXPKEYSIG`, o conserto é subir o ano — não é comprometimento de chave.

## Duas correções que só apareceram ao descer ao concreto

**PHP não vem do Sury nesta distribuição.** O ticket #23 e a nota de versões do vault dizem "Sury", que é o repositório **Debian**. No Ubuntu o equivalente do mesmo mantenedor (Ondřej Surý) é o PPA `ondrej/php`. Mesma origem, repositório diferente. Corrigido nos dois.

**O `dialer` não roda em cron.** O ADR-0005 mudou o ritmo para malha fechada, e taxa realimentada quer processo persistente, não execução por minuto. Virou serviço com `Restart=always` — o que também **elimina a necessidade de trava de arquivo**, porque o systemd garante instância única. Uma consequência boa da mudança de ADR que ninguém tinha percebido.

## Estrutura

```
site.yml                  o playbook. Um arquivo, por blocos
ansible.cfg
inventario.exemplo.yml    copie e edite
group_vars/all.yml        versões e padrões, iguais em toda instância
host_vars/exemplo.yml     por cliente: domínio, canais, credenciais do tronco
templates/                unidades do systemd, pjsip, nginx
build-asterisk.sh         roda UMA vez, gera o artefato
install.sh                instalação em um comando, na própria VPS
```

## Uso, a partir da máquina de controle

```bash
cp inventario.exemplo.yml inventario.yml
cp host_vars/exemplo.yml host_vars/cliente-x.yml   # edite
ansible-playbook -i inventario.yml site.yml
```

## Uso, em um comando, na própria VPS

Numa Ubuntu 24.04 nua, como root. O repositório é privado, então não existe
`raw.githubusercontent.com` que funcione sem autenticação — o próprio script vem
pela API:

```bash
T=ghp_...
curl -fsSL -H "Authorization: Bearer $T" -H "Accept: application/vnd.github.raw" \
  https://api.github.com/repos/vcontrole777/Prospecta/contents/provisionamento/install.sh \
  -o install.sh
PROSPECTA_TOKEN=$T bash install.sh
```

**O `install.sh` não instala nada.** Quem instala é o `site.yml` — o mesmo de
cima, rodando contra `localhost`. O script existe só para o que o playbook não
consegue fazer sozinho numa máquina nua:

1. baixar o código, que está num repositório privado (daí o token) e o `.deb` do Asterisk
2. responder as sete variáveis de `host_vars` sem um humano editando YAML — gera
   `banco_senha`, `ami_segredo` e a `APP_KEY`, e grava tudo em `/etc/prospecta/instancia.yml`
3. criar o operador do painel, sem o qual ninguém entra, e imprimir a credencial no fim

Atualizar é o mesmo comando com `--atualizar`: relê `/etc/prospecta/instancia.yml`,
baixa o release novo e reexecuta o playbook. Continua valendo que instalar e
atualizar são o mesmo mecanismo — o instalador é uma porta de entrada, não um
segundo caminho.

### 🔴 `/etc/prospecta/instancia.yml` entra no backup

É a única cópia da `APP_KEY` daquela instância. Sem ela, todo valor cifrado no
banco fica ilegível — a senha da Rota entre eles — e não há recuperação: a
instância precisa ser reconfigurada do zero.

### O que precisa estar publicado antes

| Release | Gerado por | Contém |
|---|---|---|
| `v*` | workflow `release` | `prospecta-<tag>.tar.gz`: o código com `public/build` já construído |
| `asterisk-<versão>` | workflow `asterisk-artefato` | `asterisk_<versão>_amd64.deb` |

Os assets vêm prontos porque `node` não é instalado na VPS de propósito. O `.deb`
é publicado uma vez por versão do Asterisk, e o número sai de
`group_vars/all.yml` — o mesmo lugar de onde o `install.sh` lê para saber qual
release procurar, para que não existam dois números que possam divergir.

O `install.sh` **pré-instala** o `.deb` em vez de deixar o playbook baixá-lo: o
`get_url` não manda cabeçalho de autenticação e o release é privado. Com o
pacote já instalado, a tarefa do playbook sai pulada — que é o que a guarda
`asterisk -V` sempre fez.

E ele passa `-e operador_criado_depois=true`, que desarma o portão de operador
do `site.yml`. O portão continua armado para qualquer outro chamador: sem ele,
uma instalação nova morreria antes do nginx, das unidades e do certificado, por
um operador que o próprio instalador ia criar no passo seguinte.

## Asterisk é artefato, não compilação por cliente

`build-asterisk.sh` compila o Asterisk 22.10.1 uma vez e produz um pacote versionado. O playbook instala o pacote.

Três razões: provisionamento rápido, **versão idêntica** em todas as instâncias — não "a mesma versão compilada N vezes" — e rollback trivial.

O artefato não fica neste repositório. Publique num lugar que o playbook alcance e aponte `asterisk_url_artefato`.

## `host_vars` com credencial não entra no git

Só `host_vars/exemplo.yml` é versionado. As credenciais de Tronco SIP de cada cliente ficam fora — em `ansible-vault` ou fora do repositório. O `.gitignore` do repositório cobre isso.

## Pré-requisito que não é do Ansible

**O DNS do domínio do cliente tem que resolver para o IP da máquina antes de rodar.** O certificado é emitido por validação HTTP, e sem ele o webhook do WhatsApp não pode ser cadastrado — a Meta recusa certificado autoassinado ([#24](https://github.com/vcontrole777/Prospecta/issues/24)).

Se o DNS não estiver pronto, rode com `--skip-tags https` e volte depois.

## Fora de escopo

**Multi-servidor.** A instância única segura até ~400–500 canais, e a resposta acima disso é separar Asterisk de painel e banco — não distribuir a discagem. Coordenação entre servidores é o que o MagnusBilling errou, com `frequency / n_servidores` sem lock.

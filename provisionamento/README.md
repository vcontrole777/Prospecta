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
```

## Uso

```bash
cp inventario.exemplo.yml inventario.yml
cp host_vars/exemplo.yml host_vars/cliente-x.yml   # edite
ansible-playbook -i inventario.yml site.yml
```

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

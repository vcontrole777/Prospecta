<?php

declare(strict_types=1);

namespace App\Asterisk;

use App\Dialer\Ami;
use App\Dominio\Rota;

/**
 * Põe a Rota em vigor: escreve o arquivo de pjsip e manda o Asterisk recarregar.
 *
 * 🔴 DUAS ETAPAS, E A ORDEM IMPORTA. Escrever e não recarregar deixa o arquivo novo com o
 * Asterisk usando o antigo — divergência silenciosa entre o que a tela mostra e o que
 * disca. Recarregar e não escrever não faz nada. Então: escreve, confere que escreveu,
 * recarrega, e só declara sucesso se as duas partes derem certo.
 *
 * 🔴 SEM `sudo`. O painel roda como `prospecta` e não pode escrever em `/etc/asterisk`.
 * O playbook cria UM arquivo — `pjsip-rota.conf`, incluído pelo `pjsip.conf` — com dono
 * `asterisk:prospecta` e modo 0660. É o privilégio mínimo: o painel escreve aquele arquivo
 * e nada mais, e não ganha permissão de rodar comando como outro usuário.
 *
 * A recarga vai por AMI, com as credenciais que o dialer já usa. A ação `Reload` exige
 * `write = system` no `manager.conf`, que o usuário já tem — então isto não afrouxa nada.
 * `Command` (para `pjsip reload` na CLI) exigiria a permissão `command`, que NÃO é
 * concedida, e conceder daria ao painel a CLI inteira do Asterisk.
 */
final class AplicaRota
{
    public function __construct(
        private readonly string $arquivo,
        private readonly string $amiHost,
        private readonly int $amiPorta,
        private readonly string $amiUsuario,
        private readonly string $amiSegredo,
    ) {
    }

    /**
     * @return array{ok:bool, mensagem:string}
     */
    public function aplicar(Rota $rota): array
    {
        $escrita = $this->escrever($rota);
        if (! $escrita['ok']) {
            return $escrita;
        }

        return $this->recarregar();
    }

    /**
     * @return array{ok:bool, mensagem:string}
     */
    private function escrever(Rota $rota): array
    {
        // O arquivo tem que EXISTIR e ser gravável. Criá-lo aqui não resolveria: o
        // diretório é do `asterisk` e o painel não escreve nele. A ausência é, portanto,
        // problema de provisionamento — e dizer isso é mais útil que "falha ao gravar".
        if (! is_file($this->arquivo)) {
            return [
                'ok' => false,
                'mensagem' => sprintf(
                    'O arquivo %s não existe. Ele é criado pelo provisionamento, com dono '
                    . 'asterisk:prospecta e modo 0660 — rode o playbook.',
                    $this->arquivo,
                ),
            ];
        }

        if (! is_writable($this->arquivo)) {
            return [
                'ok' => false,
                'mensagem' => sprintf(
                    'Não consigo escrever em %s. O usuário do php-fpm precisa estar no grupo '
                    . 'do arquivo, e o serviço precisa ser reiniciado depois de entrar no '
                    . 'grupo — grupo suplementar só é lido quando o processo começa.',
                    $this->arquivo,
                ),
            ];
        }

        // 🔴 RECUSA GRAVAR SENHA EM ARQUIVO QUE "OUTROS" LEEM.
        //
        // Escrever no lugar preserva o modo — o que é certo, porque o modo é decisão do
        // provisionamento (0660). Mas preservar um modo RUIM significaria gravar a senha da
        // rota, em claro, num arquivo legível por qualquer usuário da máquina. O pjsip não lê
        // segredo cifrado, então a única proteção que existe é o modo.
        //
        // Recusar é melhor que consertar por conta própria: um `chmod` daqui esconderia que a
        // instância estava exposta, e o operador nunca saberia que ficou.
        $modo = fileperms($this->arquivo);

        if ($modo !== false && ($modo & 0o004) !== 0) {
            return [
                'ok' => false,
                'mensagem' => sprintf(
                    'NÃO gravei: %s está legível por qualquer usuário da máquina (modo %o) e '
                    . 'o arquivo contém a senha da rota em claro. Ajuste para 0660 com grupo '
                    . 'do Asterisk e aplique de novo.',
                    $this->arquivo,
                    $modo & 0o777,
                ),
            ];
        }

        // 🔴 ESCRITA NO LUGAR, e não temporário + `rename`. A troca é deliberada.
        //
        // `rename` seria atômico, mas exige criar o temporário NO DIRETÓRIO — e isso exige
        // permissão de escrita em `/etc/asterisk`. Medido: como `prospecta`, o
        // `file_put_contents` do `.novo` devolveu "Permission denied", porque a permissão
        // concedida é sobre o ARQUIVO (0660), não sobre a pasta.
        //
        // Dar escrita no diretório deixaria o painel substituir `manager.conf`, `pjsip.conf`
        // e o resto — um caminho de escalada muito pior que o problema que o `rename`
        // resolve. Então perde-se a atomicidade e mantém-se o privilégio mínimo.
        //
        // Efeito colateral bom: escrever no lugar PRESERVA dono e grupo, então o arquivo
        // continua legível pelo Asterisk sem nenhum malabarismo de `chgrp`.
        $conteudo = $rota->paraPjsip();

        if (file_put_contents($this->arquivo, $conteudo) === false) {
            return ['ok' => false, 'mensagem' => sprintf('Não consegui escrever em %s.', $this->arquivo)];
        }

        // 🔴 RELÊ E COMPARA, que é o que substitui a atomicidade perdida.
        //
        // Sem `rename`, uma escrita interrompida — disco cheio, processo morto — deixa o
        // arquivo pela metade: meio endpoint faz o Asterisk recusar a recarga INTEIRA e
        // derruba a rota que estava funcionando. Comparar o que voltou com o que se quis
        // gravar transforma isso em erro NOMEADO, em vez de uma recarga recusada cuja causa
        // aparece só no log do Asterisk.
        clearstatcache(true, $this->arquivo);

        if (file_get_contents($this->arquivo) !== $conteudo) {
            return [
                'ok' => false,
                'mensagem' => sprintf(
                    'A escrita de %s saiu incompleta — o arquivo no disco não é o que gerei. '
                    . 'NÃO recarreguei o Asterisk: metade de um endpoint derrubaria a rota '
                    . 'atual. Confira espaço em disco e aplique de novo.',
                    $this->arquivo,
                ),
            ];
        }

        return ['ok' => true, 'mensagem' => 'arquivo escrito'];
    }

    /**
     * @return array{ok:bool, mensagem:string}
     */
    private function recarregar(): array
    {
        $socket = @stream_socket_client(
            sprintf('tcp://%s:%d', $this->amiHost, $this->amiPorta),
            $errno,
            $errstr,
            5,
        );

        if ($socket === false) {
            return [
                'ok' => false,
                'mensagem' => sprintf(
                    'Gravei o arquivo, mas não falei com o Asterisk pelo AMI (%s). '
                    . 'A rota nova só passa a valer na próxima recarga.',
                    $errstr !== '' ? $errstr : 'sem resposta',
                ),
            ];
        }

        try {
            fgets($socket);   // banner "Asterisk Call Manager/x.y.z"

            $ami = new Ami($socket, $socket);

            $login = $ami->login($this->amiUsuario, $this->amiSegredo);
            if (($login['Response'] ?? '') !== 'Success') {
                return [
                    'ok' => false,
                    'mensagem' => 'Gravei o arquivo, mas o AMI recusou o login: '
                        . ($login['Message'] ?? 'sem motivo'),
                ];
            }

            // `Module: res_pjsip.so` e não uma recarga geral: recarregar tudo relê dialplan
            // e logger sem necessidade, e uma recarga ampla no meio da operação é risco
            // desnecessário para trocar um endpoint.
            $r = $ami->acao(['Action' => 'Reload', 'Module' => 'res_pjsip.so']);

            if (($r['Response'] ?? '') !== 'Success') {
                return [
                    'ok' => false,
                    'mensagem' => 'Gravei o arquivo, mas o Asterisk recusou a recarga: '
                        . ($r['Message'] ?? 'sem motivo'),
                ];
            }

            return ['ok' => true, 'mensagem' => 'Rota aplicada e Asterisk recarregado.'];
        } finally {
            // Fecha SEMPRE. Socket vazado no php-fpm sobrevive à requisição e o Asterisk
            // acumula sessão de AMI aberta até o limite dele.
            @fclose($socket);
        }
    }
}

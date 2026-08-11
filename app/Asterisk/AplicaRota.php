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

        // Escrita ATÔMICA: arquivo temporário no MESMO diretório e depois `rename`. Um
        // `file_put_contents` direto que morra no meio deixa o pjsip com metade de um
        // endpoint, e aí o Asterisk recusa a recarga inteira e derruba a rota que estava
        // funcionando. `rename` no mesmo sistema de arquivos é atômico.
        $temporario = $this->arquivo . '.novo';

        if (file_put_contents($temporario, $rota->paraPjsip()) === false) {
            return ['ok' => false, 'mensagem' => 'Não consegui escrever o arquivo temporário.'];
        }

        // O `rename` preserva o dono e o modo do ARQUIVO NOVO, não do antigo — então o
        // modo é reposto antes de trocar, senão o pjsip ficaria 0644 e a senha da rota
        // legível por qualquer usuário da máquina.
        @chmod($temporario, 0o660);

        if (! rename($temporario, $this->arquivo)) {
            @unlink($temporario);

            return ['ok' => false, 'mensagem' => 'Não consegui substituir o arquivo de configuração.'];
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

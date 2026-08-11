<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Asterisk\AplicaRota;
use App\Dominio\Rota;
use App\Seguranca\Cofre;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Escreve o pjsip da Rota gravada e manda o Asterisk recarregar, pela linha de comando.
 *
 * Existe por três razões, e nenhuma é conveniência:
 *
 * · O PROVISIONAMENTO precisa disso. O playbook cria `pjsip-rota.conf` vazio e para de
 *   gerar o endpoint; sem este comando, uma reimplantação deixaria o Asterisk sem rota até
 *   alguém abrir o painel e salvar de novo — mesmo com a Rota já no banco.
 * · RECUPERAÇÃO. Se o painel estiver fora do ar, ou a permissão do arquivo tiver quebrado,
 *   isto reaplica sem depender de navegador.
 * · Rodar como o usuário CERTO. O `rename` deixa o arquivo com o dono de quem escreve;
 *   rodar como a conta da aplicação é o que mantém o grupo que o Asterisk lê.
 *
 * Idempotente: sem Rota no banco, não escreve nada e diz por quê.
 */
final class AplicarRota extends Command
{
    /** @var string */
    protected $signature = 'prospecta:rota-aplicar';

    /** @var string */
    protected $description = 'Escreve a Rota gravada no pjsip e recarrega o Asterisk';

    public function handle(): int
    {
        $linha = DB::table('configuracao')->where('id', 1)->first();
        $c = $linha === null ? [] : (array) $linha;

        $rota = new Rota(
            host: self::texto($c['rota_host'] ?? null),
            usuario: self::texto($c['rota_usuario'] ?? null),
            senha: $this->senha(self::texto($c['rota_senha'] ?? null)),
            fromDominio: self::texto($c['rota_from_dominio'] ?? null),
            fromUsuario: self::texto($c['rota_from_usuario'] ?? null),
            techPrefix: self::texto($c['rota_tech_prefix'] ?? null),
        );

        // 🔴 SUCESSO quando não há Rota, e não falha. Instância nova legitimamente não tem
        // rota configurada ainda; sair com erro faria o playbook falhar num estado normal, e
        // falha rotineira ensina a ignorar falha. Quem avisa o operador é o painel, e quem
        // recusa discar é o dialer.
        if (! $rota->configurado()) {
            $this->warn('Rota não configurada no banco. Nada a escrever.');

            return self::SUCCESS;
        }

        $r = $this->aplicador()->aplicar($rota);

        if (! $r['ok']) {
            $this->error($r['mensagem']);

            return self::FAILURE;
        }

        $this->info($r['mensagem']);

        return self::SUCCESS;
    }

    private function senha(string $coluna): string
    {
        if ($coluna === '') {
            return '';
        }

        $chave = config('app.key');
        $dados = (new Cofre(is_string($chave) ? $chave : ''))->decifrarDaColuna($coluna);

        return is_string($dados['senha'] ?? null) ? $dados['senha'] : '';
    }

    private function aplicador(): AplicaRota
    {
        $ami = config('dialer.ami');
        $ami = is_array($ami) ? $ami : [];

        return new AplicaRota(
            arquivo: self::texto(config('prospecta.pjsip_rota')),
            amiHost: self::texto($ami['host'] ?? null) ?: '127.0.0.1',
            amiPorta: is_numeric($ami['porta'] ?? null) ? (int) $ami['porta'] : 5038,
            amiUsuario: self::texto($ami['usuario'] ?? null),
            amiSegredo: self::texto($ami['segredo'] ?? null),
        );
    }

    private static function texto(mixed $v): string
    {
        return is_string($v) ? $v : '';
    }
}

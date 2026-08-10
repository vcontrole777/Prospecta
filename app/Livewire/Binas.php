<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Dominio\Telefone;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

/**
 * Lista de BINA para a regra `lista`.
 *
 * 🔴 A TELA É SOBRE COBERTURA POR DDD, não sobre uma lista de números.
 *
 * O dialer casa `bina.ddd` com o DDD do destino. Quando não há número ativo para
 * aquele DDD, `binaDoDdd` devolve `null` e a `Bina::derivar` cai no número único da
 * Campanha — e, se ele estiver vazio, em nenhum CallerID.
 *
 * Isso é uma degradação SILENCIOSA: a Campanha continua discando, a taxa de
 * atendimento cai, e nada no painel liga uma coisa à outra. Por isso a tela mostra os
 * DDDs que têm Lead e não têm BINA, em vez de só listar o que existe.
 *
 * `ddd` é coluna GERADA pelo banco a partir do número — a tela nunca a escreve.
 */
final class Binas extends Component
{
    /** Cola de números, um por linha ou separados por vírgula. */
    public string $entrada = '';

    public int $adicionados = 0;

    public int $repetidos = 0;

    /** @var list<string> */
    public array $recusados = [];

    public function render(): View
    {
        return view('livewire.binas', [
            'porDdd' => $this->porDdd(),
            'descobertos' => $this->dddsDescobertos(),
            'total' => DB::table('bina')->count(),
            'ativos' => DB::table('bina')->where('ativo', 1)->count(),
        ]);
    }

    public function adicionar(): void
    {
        $this->validate(
            ['entrada' => ['required', 'string', 'max:100000']],
            ['entrada.required' => 'Cole ao menos um número.'],
        );

        $t = new Telefone();

        $this->adicionados = 0;
        $this->repetidos = 0;
        $this->recusados = [];

        // Aceita um por linha, separado por vírgula ou por ponto e vírgula — é o que
        // sai de planilha, de bloco de notas e de mensagem colada.
        $pedacos = preg_split('/[\r\n,;]+/', $this->entrada) ?: [];

        $novos = [];
        foreach ($pedacos as $bruto) {
            $bruto = trim($bruto);
            if ($bruto === '') {
                continue;
            }

            // MESMA normalização da importação de lista. Sem isso o `ddd` gerado — que
            // é `SUBSTRING(numero, 3, 2)` — sairia errado para número sem país, e o
            // casamento com o destino nunca aconteceria.
            $canonico = $t->tentar($bruto);

            if ($canonico === null) {
                if (count($this->recusados) < 20) {
                    $this->recusados[] = $bruto;
                }
                continue;
            }

            $novos[$canonico] = true;   // dedup dentro da própria colagem
        }

        foreach (array_keys($novos) as $numero) {
            // `numero` é único; `insertOrIgnore` faz o repetido ser no-op sem engolir
            // outro erro. `ddd` NÃO entra: é coluna gerada.
            $inseriu = DB::table('bina')->insertOrIgnore(['numero' => $numero, 'ativo' => 1]);

            if ($inseriu > 0) {
                $this->adicionados++;
            } else {
                $this->repetidos++;
            }
        }

        $this->entrada = '';
    }

    public function alternar(int $id): void
    {
        $atual = DB::table('bina')->where('id', $id)->value('ativo');
        DB::table('bina')->where('id', $id)->update(['ativo' => (bool) $atual ? 0 : 1]);
    }

    /**
     * Remover é seguro AQUI, diferente do áudio.
     *
     * `ligacao.bina` guarda o número como TEXTO, não como chave estrangeira — a
     * Ligação registra qual BINA saiu, e apagar a linha da lista não apaga o
     * histórico. No áudio a Campanha aponta por id, e por isso lá só se desativa.
     */
    public function remover(int $id): void
    {
        DB::table('bina')->where('id', $id)->delete();
    }

    public function desativarDdd(string $ddd): void
    {
        DB::table('bina')->where('ddd', $ddd)->update(['ativo' => 0]);
    }

    public function ativarDdd(string $ddd): void
    {
        DB::table('bina')->where('ddd', $ddd)->update(['ativo' => 1]);
    }

    /**
     * Números agrupados por DDD, que é a unidade em que o dialer procura.
     *
     * @return list<array{ddd:string, ativos:int, total:int, numeros:list<array{id:int,numero:string,ativo:bool}>}>
     */
    private function porDdd(): array
    {
        $grupos = [];

        foreach (DB::table('bina')->orderBy('ddd')->orderBy('numero')->get() as $linha) {
            $b = (array) $linha;
            $ddd = self::texto($b['ddd'] ?? null);
            $ativo = (bool) ($b['ativo'] ?? false);

            $grupos[$ddd] ??= ['ddd' => $ddd, 'ativos' => 0, 'total' => 0, 'numeros' => []];
            $grupos[$ddd]['total']++;
            if ($ativo) {
                $grupos[$ddd]['ativos']++;
            }
            $grupos[$ddd]['numeros'][] = [
                'id' => self::inteiro($b['id'] ?? null),
                'numero' => self::texto($b['numero'] ?? null),
                'ativo' => $ativo,
            ];
        }

        return array_values($grupos);
    }

    /**
     * 🔴 DDDs que têm Lead esperando e NÃO têm BINA ativa.
     *
     * É a informação que a tela existe para dar. Sem ela, a regra `lista` degrada em
     * silêncio: a Campanha disca, cai no número único ou em nenhum CallerID, o
     * atendimento despenca e nada liga a causa ao efeito.
     *
     * Só conta Campanha que de fato usa a regra `lista` — avisar sobre DDD de Campanha
     * que usa `espelho` seria alarme falso, e alarme falso ensina a ignorar o painel.
     *
     * @return list<array{ddd:string, leads:int}>
     */
    private function dddsDescobertos(): array
    {
        $saida = [];

        foreach (DB::select(
            "SELECT SUBSTRING(l.telefone, 3, 2) AS ddd, COUNT(*) AS leads
               FROM leads l
               JOIN campanha c ON c.id = l.campanha_id
              WHERE c.bina_regra = 'lista'
                AND c.status IN (0, 1, 2)
                AND l.status IN (1, 4)
                AND NOT EXISTS (
                    SELECT 1 FROM bina b
                     WHERE b.ativo = 1 AND b.ddd = SUBSTRING(l.telefone, 3, 2)
                )
              GROUP BY ddd
              ORDER BY leads DESC"
        ) as $linha) {
            $d = (array) $linha;
            $saida[] = [
                'ddd' => self::texto($d['ddd'] ?? null),
                'leads' => self::inteiro($d['leads'] ?? null),
            ];
        }

        return $saida;
    }

    private static function texto(mixed $v): string
    {
        return is_string($v) ? $v : '';
    }

    private static function inteiro(mixed $v): int
    {
        return is_numeric($v) ? (int) $v : 0;
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Worker\FilaDeAcoes;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A reivindicação por canal.
 *
 * `DatabaseTruncation`, e não `RefreshDatabase`, pelo mesmo motivo de
 * `FilaDeAcoesTest`: `reivindicar` abre transação PRÓPRIA, e
 * `PDO::beginTransaction` dentro da transação do `RefreshDatabase` falha com "there
 * is already an active transaction". Testar com transação por fora é não testar a
 * de dentro.
 */
final class FilaPorCanalTest extends TestCase
{
    use DatabaseTruncation;

    /** @return array{campanha:int, acao:int} */
    private function campanhaComDisparo(string $canal, ?string $textoSms): array
    {
        $c = (int) DB::table('campanha')->insertGetId([
            'nome' => 'C', 'tipo' => 'opt_in', 'status' => 1, 'criada_em' => now(),
            'canal_opt_in' => $canal, 'texto_sms' => $textoSms,
        ]);
        $lead = (int) DB::table('leads')->insertGetId([
            'campanha_id' => $c, 'telefone' => '5511987654321',
            'dados_extras' => json_encode(['nome' => 'Maria']), 'status' => 2, 'criado_em' => now(),
        ]);
        $lig = (int) DB::table('ligacao')->insertGetId([
            'lead_id' => $lead, 'campanha_id' => $c, 'numero_tentativa' => 1, 'iniciada_em' => now(),
        ]);
        $acao = (int) DB::table('acao')->insertGetId([
            'tipo' => 'disparo', 'referencia_id' => $lig, 'status' => 'pendente',
            'tentativas' => 0, 'criado_em' => now(),
        ]);

        return ['campanha' => $c, 'acao' => $acao];
    }

    /**
     * 🔴 UM CANAL POR VEZ.
     *
     * Cada um tem remetente próprio — token da Meta de um lado, chave do httpSMS e
     * número do aparelho do outro. Um lote misto obrigaria a escolher o remetente
     * linha a linha dentro do laço de envio.
     */
    #[Test]
    public function reivindica_por_canal_sem_misturar(): void
    {
        $wpp = $this->campanhaComDisparo('whatsapp', null);
        $sms = $this->campanhaComDisparo('sms', 'Olá {nome}');

        $fila = new FilaDeAcoes(DB::connection()->getPdo());

        $doWpp = $fila->reivindicar(10, 'whatsapp');
        $this->assertCount(1, $doWpp);
        $this->assertSame($wpp['acao'], (int) $doWpp[0]['id']);

        $doSms = $fila->reivindicar(10, 'sms');
        $this->assertCount(1, $doSms);
        $this->assertSame($sms['acao'], (int) $doSms[0]['id']);
        $this->assertSame('Olá {nome}', $doSms[0]['texto_sms']);
    }

}

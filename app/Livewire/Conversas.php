<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Whatsapp\JanelaDeAtendimento;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Caixa de entrada. O webhook já populava `conversa` e `conversa_mensagem` e nada
 * mostrava — o Lead respondia e ninguém lia.
 *
 * 🔴 SOMENTE LEITURA nesta versão, e é decisão declarada na tela.
 *
 * Responder exige um caminho novo: um tipo de Ação para texto livre e um remetente no
 * worker. Fazer meia-boca — um campo de texto que às vezes funciona, dependendo da
 * janela e do template disponível — seria pior que não ter: o operador escreveria uma
 * resposta e ela sumiria.
 *
 * O que a tela FAZ é o que decide se responder é possível: mostrar quanto resta da
 * janela de 24 h. Dentro dela texto livre é ilimitado e não custa por mensagem; fora,
 * só template pago.
 */
final class Conversas extends Component
{
    /** Na URL para o operador poder mandar o link de uma conversa a alguém. */
    #[Url(as: 'c', except: null)]
    public ?int $selecionada = null;

    public function render(): View
    {
        $fuso = new DateTimeZone(self::texto(config('app.timezone')) ?: 'UTC');
        $agora = new DateTimeImmutable('now', $fuso);

        return view('livewire.conversas', [
            'conversas' => $this->lista($agora),
            'mensagens' => $this->selecionada === null ? [] : $this->mensagens($this->selecionada),
            'detalhe' => $this->selecionada === null ? null : $this->detalhe($this->selecionada, $agora),
        ]);
    }

    public function selecionar(int $id): void
    {
        $this->selecionada = $id;
    }

    /**
     * Conversas com entrada mais recente primeiro.
     *
     * `ultima_entrada_em DESC` e não `criado_em`: quem escreveu agora é quem espera
     * resposta, e é por quem a janela está correndo. Ordenar por criação enterraria uma
     * conversa antiga que acabou de receber mensagem.
     *
     * @return list<array<string,mixed>>
     */
    private function lista(DateTimeImmutable $agora): array
    {
        $saida = [];

        foreach (DB::select(
            "SELECT c.id, c.telefone, c.lead_id, c.ultima_entrada_em,
                    (SELECT COUNT(*) FROM conversa_mensagem m WHERE m.conversa_id = c.id) AS mensagens,
                    (SELECT m2.texto FROM conversa_mensagem m2
                      WHERE m2.conversa_id = c.id ORDER BY m2.id DESC LIMIT 1) AS ultimo_texto,
                    (SELECT m3.direcao FROM conversa_mensagem m3
                      WHERE m3.conversa_id = c.id ORDER BY m3.id DESC LIMIT 1) AS ultima_direcao,
                    EXISTS(SELECT 1 FROM bloqueio b WHERE b.telefone = c.telefone) AS bloqueado
               FROM conversa c
              ORDER BY c.ultima_entrada_em IS NULL, c.ultima_entrada_em DESC, c.id DESC
              LIMIT 200"
        ) as $linha) {
            $c = (array) $linha;
            $janela = new JanelaDeAtendimento($this->momento($c['ultima_entrada_em'] ?? null));

            $saida[] = [
                'id' => self::inteiro($c['id'] ?? null),
                'telefone' => self::texto($c['telefone'] ?? null),
                'lead_id' => is_numeric($c['lead_id'] ?? null) ? (int) $c['lead_id'] : null,
                'mensagens' => self::inteiro($c['mensagens'] ?? null),
                'ultimo_texto' => self::texto($c['ultimo_texto'] ?? null),
                'ultima_direcao' => self::texto($c['ultima_direcao'] ?? null),
                'bloqueado' => (bool) ($c['bloqueado'] ?? false),
                'janela_aberta' => $janela->aberta($agora),
                'janela_restante' => $janela->restante($agora),
                'janela_acabando' => $janela->acabando($agora),
            ];
        }

        return $saida;
    }

    /** @return array<string,mixed>|null */
    private function detalhe(int $id, DateTimeImmutable $agora): ?array
    {
        $linha = DB::table('conversa')->where('id', $id)->first();
        if ($linha === null) {
            return null;
        }
        $c = (array) $linha;

        $telefone = self::texto($c['telefone'] ?? null);
        $janela = new JanelaDeAtendimento($this->momento($c['ultima_entrada_em'] ?? null));

        return [
            'id' => $id,
            'telefone' => $telefone,
            'lead_id' => is_numeric($c['lead_id'] ?? null) ? (int) $c['lead_id'] : null,
            'bloqueado' => DB::table('bloqueio')->where('telefone', $telefone)->exists(),
            'janela_aberta' => $janela->aberta($agora),
            'janela_restante' => $janela->restante($agora),
            'janela_acabando' => $janela->acabando($agora),
        ];
    }

    /** @return list<array<string,mixed>> */
    private function mensagens(int $conversaId): array
    {
        $saida = [];

        foreach (DB::table('conversa_mensagem')
            ->where('conversa_id', $conversaId)
            ->orderBy('id')
            ->limit(500)
            ->get() as $linha) {
            $m = (array) $linha;

            $saida[] = [
                'direcao' => self::texto($m['direcao'] ?? null),
                'tipo' => self::texto($m['tipo'] ?? null),
                'texto' => self::texto($m['texto'] ?? null),
                'template' => self::texto($m['template_nome'] ?? null),
                'status' => self::texto($m['status'] ?? null),
                'erro' => is_numeric($m['erro_codigo'] ?? null) ? (int) $m['erro_codigo'] : null,
                'quando' => substr(self::texto($m['criado_em'] ?? null), 0, 16),
            ];
        }

        return $saida;
    }

    /**
     * O carimbo do MySQL vem sem fuso. Interpretá-lo no fuso da aplicação é o que faz a
     * conta da janela fechar: ler como UTC daria três horas de diferença no Brasil, e a
     * janela pareceria fechada quando ainda há tempo de responder de graça.
     */
    private function momento(mixed $v): ?DateTimeImmutable
    {
        if (! is_string($v) || $v === '') {
            return null;
        }

        $fuso = new DateTimeZone(self::texto(config('app.timezone')) ?: 'UTC');

        try {
            return new DateTimeImmutable($v, $fuso);
        } catch (\Exception) {
            return null;
        }
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

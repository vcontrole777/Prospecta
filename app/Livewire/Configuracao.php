<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Dialer\Janela;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

/**
 * Configuração da instância. Linha única — não é multi-inquilino.
 *
 * 🔴 SÓ OS CAMPOS QUE ALGUM PROCESSO LÊ, pela mesma razão do formulário de Campanha:
 * campo em tela que nenhum processo consome é promessa falsa. O operador preenche,
 * confere, e nada acontece.
 *
 * A tabela tem colunas que esta tela deliberadamente NÃO mostra:
 *
 * · as `rota_*` têm tela própria (`App\Livewire\Rota`), porque o caminho de saída é
 *   assunto seu e mexer nele recarrega o Asterisk — efeito que não cabe junto de horário
 *   de trabalho e retenção de gravação;
 * · `classificador_pre_ativo`, `audio_amd_id`, `audio_espera_id` e
 *   `audio_mensagem_generica_id` seguem ÓRFÃS: nenhum processo as lê. Ficam fora, e a tela
 *   nomeia a ausência em vez de oferecer um campo morto.
 */
final class Configuracao extends Component
{
    public string $horario_inicio = '';

    public string $horario_fim = '';

    /** @var list<int> ISO-8601: 1 é segunda, 7 é domingo. */
    public array $dias_semana = [];

    public bool $gravacao_ativa = true;

    public int $gravacao_retencao_dias = 15;

    /** @var array<int,string> */
    public const DIAS = [
        1 => 'Segunda', 2 => 'Terça', 3 => 'Quarta', 4 => 'Quinta',
        5 => 'Sexta', 6 => 'Sábado', 7 => 'Domingo',
    ];

    public function mount(): void
    {
        // A linha é semeada por migration; `insertOrIgnore` é cinto de segurança para
        // o caso de alguém a ter apagado — sem ela, TODA chamada falha.
        DB::table('configuracao')->insertOrIgnore(['id' => 1]);

        $linha = DB::table('configuracao')->where('id', 1)->first();
        $c = $linha === null ? [] : (array) $linha;

        // `HH:MM` para o input `time`; o MySQL devolve `HH:MM:SS`.
        $this->horario_inicio = substr(self::texto($c['horario_inicio'] ?? null), 0, 5);
        $this->horario_fim = substr(self::texto($c['horario_fim'] ?? null), 0, 5);

        $dias = json_decode(self::texto($c['dias_semana'] ?? null), true);
        if (is_array($dias)) {
            foreach ($dias as $d) {
                if (is_numeric($d) && (int) $d >= 1 && (int) $d <= 7) {
                    $this->dias_semana[] = (int) $d;
                }
            }
        }

        $this->gravacao_ativa = (bool) ($c['gravacao_ativa'] ?? true);
        $this->gravacao_retencao_dias = max(1, self::inteiro($c['gravacao_retencao_dias'] ?? null) ?: 15);
    }

    public function render(): View
    {
        $janela = $this->janela();
        $fuso = new DateTimeZone(self::texto(config('app.timezone')) ?: 'UTC');
        $agora = new DateTimeImmutable('now', $fuso);

        return view('livewire.configuracao', [
            'descricao' => $janela->descricao(),
            'abertaAgora' => $janela->aberta($agora),
            'agora' => $agora->format('D, d/m H:i'),
            'fuso' => $fuso->getName(),
        ]);
    }

    public function salvar(): void
    {
        $this->validate([
            // Os dois em branco significam "sem restrição", e é o comportamento de
            // antes desta tela existir. `required_with` impede o meio caminho: só um
            // preenchido faria a `Janela` liberar, e o operador acharia que restringiu.
            'horario_inicio' => ['nullable', 'date_format:H:i', 'required_with:horario_fim'],
            'horario_fim' => ['nullable', 'date_format:H:i', 'required_with:horario_inicio'],
            'dias_semana' => ['array'],
            'dias_semana.*' => ['integer', 'between:1,7'],
            'gravacao_retencao_dias' => ['required', 'integer', 'min:1', 'max:3650'],
        ], [
            'horario_inicio.required_with' => 'Preencha os dois horários, ou deixe os dois em branco.',
            'horario_fim.required_with' => 'Preencha os dois horários, ou deixe os dois em branco.',
            'horario_inicio.date_format' => 'Use o formato 24 h, como 09:00.',
            'horario_fim.date_format' => 'Use o formato 24 h, como 18:00.',
        ], [
            'horario_inicio' => 'início',
            'horario_fim' => 'fim',
            'gravacao_retencao_dias' => 'retenção da gravação',
        ]);

        sort($this->dias_semana);

        DB::table('configuracao')->where('id', 1)->update([
            'horario_inicio' => $this->horario_inicio === '' ? null : $this->horario_inicio,
            'horario_fim' => $this->horario_fim === '' ? null : $this->horario_fim,
            // Lista vazia grava NULL, não `[]`: os dois significam "todos os dias" para
            // a `Janela`, e NULL é o que a coluna já usava. Dois valores para o mesmo
            // significado dariam duas leituras possíveis.
            'dias_semana' => $this->dias_semana === [] ? null : json_encode($this->dias_semana),
            'gravacao_ativa' => $this->gravacao_ativa ? 1 : 0,
            'gravacao_retencao_dias' => $this->gravacao_retencao_dias,
        ]);

        session()->flash('recado', 'Configuração salva. O dialer reavalia o horário a cada volta.');
    }

    /** A mesma classe que o dialer usa — a tela não pode discordar dele. */
    private function janela(): Janela
    {
        return new Janela(
            $this->horario_inicio === '' ? null : $this->horario_inicio,
            $this->horario_fim === '' ? null : $this->horario_fim,
            $this->dias_semana === [] ? null : $this->dias_semana,
        );
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

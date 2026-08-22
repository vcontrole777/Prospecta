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
 * · `classificador_pre_ativo`, `audio_amd_id` e `audio_espera_id` seguem ÓRFÃS: nenhum
 *   processo as lê. Ficam fora, e a tela nomeia a ausência em vez de oferecer um campo
 *   morto.
 *
 * 🔴 E DUAS SAÍRAM DESSA LISTA, porque o comentário envelheceu — texto desatualizado
 * mente com a mesma eficácia de um defeito:
 *
 * · `audio_mensagem_generica_id` NÃO é mais órfã: o `Banco::carregar` a lê como mensagem
 *   de reserva. Segue fora desta tela por enquanto, e isso agora é uma PENDÊNCIA, não uma
 *   decisão — o operador não tem como apontá-la;
 * · `classificador_pos_ativo` e os nove `pos_*` nasceram configuráveis e ESTÃO aqui. O
 *   Pós-atendimento rodava sem chave e com parâmetros fixos no código; o `initial_silence`
 *   ficou anos maior que o `total_analysis_time`, anulando um critério inteiro sem que
 *   nada aparecesse como erro.
 *
 * Resta decidir o destino do `classificador_pre_ativo`: com o Pós governado por esta tela,
 * um irmão de nome simétrico que não faz nada é exatamente o campo morto que o parágrafo
 * acima diz que não se oferece. Ou se liga, ou sai do esquema.
 */
final class Configuracao extends Component
{
    public string $horario_inicio = '';

    public string $horario_fim = '';

    /** @var list<int> ISO-8601: 1 é segunda, 7 é domingo. */
    public array $dias_semana = [];

    public bool $gravacao_ativa = true;

    public int $gravacao_retencao_dias = 15;

    public bool $classificador_pos_ativo = true;

    public int $pos_silencio_inicial_ms = 6000;

    public int $pos_saudacao_max_ms = 4000;

    public int $pos_silencio_apos_saudacao_ms = 800;

    public int $pos_analise_total_ms = 5000;

    public int $pos_palavra_min_ms = 100;

    public int $pos_silencio_entre_palavras_ms = 50;

    public int $pos_palavras_max = 3;

    public int $pos_limiar_silencio = 256;

    public int $pos_palavra_max_ms = 5000;

    /**
     * Critérios cujo relógio corre DENTRO do orçamento da análise: se pedirem mais tempo
     * que ele, nunca são alcançados.
     *
     * 🔴 `pos_palavra_max_ms` está FORA desta lista de propósito. O Asterisk entrega
     * `maximum_word_length` e `total_analysis_time` iguais em 5000 no `amd.conf` de
     * fábrica, e ele não é um prazo a vencer: é o tamanho a partir do qual uma única
     * elocução conta como máquina. Recusar 5000/5000 seria recusar o padrão do
     * fornecedor, que é como uma validação zelosa demais vira obstáculo.
     *
     * @var array<string,string>
     */
    public const CRITERIOS_DENTRO_DA_ANALISE = [
        'pos_silencio_inicial_ms' => 'silêncio inicial',
        'pos_saudacao_max_ms' => 'saudação máxima',
    ];

    /**
     * Os campos numéricos do Pós-atendimento, com o padrão a que cada um corresponde.
     *
     * Existe como lista para que carregar, gravar e validar percorram a MESMA fonte —
     * três listas escritas à mão divergiriam, e o campo esquecido numa delas não daria
     * erro: daria um parâmetro que a tela mostra e o classificador ignora.
     *
     * @var array<string,string>
     */
    public const PARAMETROS_POS = [
        'pos_silencio_inicial_ms' => 'silêncio inicial',
        'pos_saudacao_max_ms' => 'saudação máxima',
        'pos_silencio_apos_saudacao_ms' => 'silêncio após a saudação',
        'pos_analise_total_ms' => 'análise total',
        'pos_palavra_min_ms' => 'palavra mínima',
        'pos_silencio_entre_palavras_ms' => 'silêncio entre palavras',
        'pos_palavras_max' => 'máximo de palavras',
        'pos_limiar_silencio' => 'limiar de silêncio',
        'pos_palavra_max_ms' => 'palavra máxima',
    ];

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

        $this->classificador_pos_ativo = (bool) ($c['classificador_pos_ativo'] ?? true);

        // `inteiro()` devolve 0 para nulo e para lixo. Zero não é valor válido para
        // nenhum destes parâmetros, então ele significa "a coluna não disse nada" — e aí
        // o default da propriedade fica, que é o comportamento de antes desta tela.
        foreach (array_keys(self::PARAMETROS_POS) as $campo) {
            $valor = self::inteiro($c[$campo] ?? null);
            if ($valor > 0) {
                $this->{$campo} = $valor;
            }
        }
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
            'posMortos' => $this->posInvalidoGravado(),
            'posAnaliseTotalGravado' => (int) DB::table('configuracao')
                ->where('id', 1)->value('pos_analise_total_ms'),
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

            // `max:65535` é o teto do SMALLINT UNSIGNED das colunas. Deixar o formulário
            // aceitar mais faria o MySQL truncar em silêncio, e o operador veria um número
            // na tela e outro no classificador.
            'pos_silencio_inicial_ms' => ['required', 'integer', 'min:100', 'max:65535'],
            'pos_saudacao_max_ms' => ['required', 'integer', 'min:100', 'max:65535'],
            'pos_silencio_apos_saudacao_ms' => ['required', 'integer', 'min:100', 'max:65535'],
            'pos_analise_total_ms' => ['required', 'integer', 'min:100', 'max:65535'],
            'pos_palavra_min_ms' => ['required', 'integer', 'min:10', 'max:65535'],
            'pos_silencio_entre_palavras_ms' => ['required', 'integer', 'min:10', 'max:65535'],
            'pos_palavras_max' => ['required', 'integer', 'min:1', 'max:255'],
            'pos_limiar_silencio' => ['required', 'integer', 'min:1', 'max:65535'],
            'pos_palavra_max_ms' => ['required', 'integer', 'min:100', 'max:65535'],
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

        /*
         * 🔴 A REGRA QUE ESTA TELA EXISTE PARA TER.
         *
         * Critério que precisa de mais tempo do que o orçamento total da análise NUNCA
         * dispara. Não dá erro, não aparece em log: some. O sistema rodou assim com
         * `silêncio inicial` em 6000 e `análise total` em 5000 — em 8.406 classificações
         * a causa `INITIALSILENCE` apareceu ZERO vezes, e 54,6% das Ligações atendidas
         * voltaram sem decisão, gastando 12,7 horas de tronco. Medido em 21/08/2026.
         *
         * Os três critérios de tempo entram na conta pelo mesmo motivo. `>=` e não `>`:
         * empatar com o orçamento é a mesma coisa que estourá-lo, porque o relógio para
         * ao ATINGIR o total.
         */
        foreach (self::CRITERIOS_DENTRO_DA_ANALISE as $campo => $rotulo) {
            if ($this->{$campo} < $this->pos_analise_total_ms) {
                continue;
            }

            // 🔴 NÃO BLOQUEIA DEFEITO HERDADO, só a introdução de um novo.
            //
            // A combinação inválida já estava gravada quando esta validação nasceu. Se a
            // recusa valesse para ela, a tela inteira travaria: quem viesse mudar o
            // horário de trabalho não conseguiria salvar por causa de um número que
            // outra pessoa deixou ali. Defeito preexistente vira AVISO permanente na
            // tela — ver `posInvalidoGravado()` e a blade —, e continua chamando
            // atenção sem sequestrar o resto da configuração.
            if (! $this->mexeuNoCriterio($campo)) {
                continue;
            }

            $this->addError($campo, sprintf(
                'O %s (%d ms) precisa ser MENOR que a análise total (%d ms). Igual ou maior, '
                    . 'este critério nunca chega a ser alcançado: o relógio da análise para antes, '
                    . 'e a classificação volta sem decisão sem que nada apareça como erro.',
                $rotulo,
                $this->{$campo},
                $this->pos_analise_total_ms,
            ));
        }

        if ($this->getErrorBag()->isNotEmpty()) {
            return;
        }

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
            'classificador_pos_ativo' => $this->classificador_pos_ativo ? 1 : 0,
        ] + array_combine(
            array_keys(self::PARAMETROS_POS),
            array_map(fn (string $c): int => $this->{$c}, array_keys(self::PARAMETROS_POS)),
        ));

        session()->flash('recado', 'Configuração salva. O dialer reavalia o horário a cada volta.');
    }

    /**
     * O operador está MEXENDO nesta relação, ou só passando por ela?
     *
     * Mexer no critério ou no orçamento conta igual: os dois mudam a comparação, e quem
     * baixa a análise total até engolir o silêncio inicial introduziu o mesmo defeito de
     * quem subiu o silêncio inicial.
     */
    private function mexeuNoCriterio(string $campo): bool
    {
        $c = (array) (DB::table('configuracao')->where('id', 1)->first() ?? []);

        return (int) ($c[$campo] ?? 0) !== $this->{$campo}
            || (int) ($c['pos_analise_total_ms'] ?? 0) !== $this->pos_analise_total_ms;
    }

    /**
     * Há combinação inválida JÁ GRAVADA? A tela avisa em vez de calar.
     *
     * @return list<string> os rótulos dos critérios que nunca são alcançados
     */
    private function posInvalidoGravado(): array
    {
        $c = (array) (DB::table('configuracao')->where('id', 1)->first() ?? []);
        $total = (int) ($c['pos_analise_total_ms'] ?? 0);

        if ($total <= 0 || (int) ($c['classificador_pos_ativo'] ?? 1) === 0) {
            return [];
        }

        $mortos = [];
        foreach (self::CRITERIOS_DENTRO_DA_ANALISE as $campo => $rotulo) {
            if ((int) ($c[$campo] ?? 0) >= $total) {
                $mortos[] = sprintf('%s (%d ms)', $rotulo, (int) ($c[$campo] ?? 0));
            }
        }

        return $mortos;
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

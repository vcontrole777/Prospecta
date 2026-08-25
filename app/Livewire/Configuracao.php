<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Dialer\Janela;
use App\Dominio\PosAtendimento;
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

    public int $pos_silencio_inicial_ms = 4500;

    public int $pos_saudacao_max_ms = 4000;

    public int $pos_silencio_apos_saudacao_ms = 800;

    public int $pos_analise_total_ms = 5000;

    public int $pos_palavra_min_ms = 100;

    public int $pos_silencio_entre_palavras_ms = 50;

    public int $pos_palavras_max = 6;

    public int $pos_limiar_silencio = 256;

    public int $pos_palavra_max_ms = 5000;

    /*
     * As duas constantes que moravam aqui — a lista dos nove parâmetros e a dos
     * critérios medidos contra a análise total — foram para `App\Dominio\PosAtendimento`.
     *
     * Não por arrumação: elas descreviam o MESMO conjunto que o `Fastagi\Banco` montava
     * com outra lista, e as duas listas não tinham como se conferir. A tela e o handler
     * agora leem a mesma declaração, que é o que o ADR-0006 exige de uma regra que roda
     * nos dois lugares.
     */

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

        // Coluna que não disse nada cai no default do parâmetro — a regra de "não
        // positivo é ausência" mora em `PosAtendimento::deLinha`, não aqui.
        $pos = PosAtendimento::deLinha($c);
        foreach (PosAtendimento::parametros() as $p) {
            $this->{$p->coluna} = $pos->valor($p->coluna);
        }
    }

    public function render(): View
    {
        $janela = $this->janela();
        $fuso = new DateTimeZone(self::texto(config('app.timezone')) ?: 'UTC');
        $agora = new DateTimeImmutable('now', $fuso);

        // 🔴 UMA leitura da linha, não quatro. `salvar` chamava `mexeuNoCriterio` uma
        // vez por critério, cada uma com seu `first()`, e `render` fazia mais duas — uma
        // delas um `value()` cujo retorno `mixed` era o erro `cast.int` do PHPStan.
        $linha = $this->linhaGravada();
        $gravado = PosAtendimento::deLinha($linha);

        return view('livewire.configuracao', [
            'descricao' => $janela->descricao(),
            'abertaAgora' => $janela->aberta($agora),
            'agora' => $agora->format('D, d/m H:i'),
            'fuso' => $fuso->getName(),
            'posMortos' => $this->posInvalidoGravado($linha),
            'posAnaliseTotalGravado' => $gravado->valor('pos_analise_total_ms'),
            'posRotulosDaAnalise' => PosAtendimento::rotulosDentroDaAnalise(),
            'posParametros' => PosAtendimento::parametros(),
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

        ] + $this->regrasDoPos(), [
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
        $linhaGravada = $this->linhaGravada();
        $proposto = PosAtendimento::padrao()->com($this->valoresDoFormulario());

        foreach ($proposto->criteriosMortos() as $campo => $rotulo) {

            // 🔴 NÃO BLOQUEIA DEFEITO HERDADO, só a introdução de um novo.
            //
            // A combinação inválida já estava gravada quando esta validação nasceu. Se a
            // recusa valesse para ela, a tela inteira travaria: quem viesse mudar o
            // horário de trabalho não conseguiria salvar por causa de um número que
            // outra pessoa deixou ali. Defeito preexistente vira AVISO permanente na
            // tela — ver `posInvalidoGravado()` e a blade —, e continua chamando
            // atenção sem sequestrar o resto da configuração.
            if (! $this->mexeuNoCriterio($campo, $linhaGravada)) {
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
        ] + PosAtendimento::padrao()->com($this->valoresDoFormulario())->paraColunas());

        session()->flash('recado', 'Configuração salva. O dialer reavalia o horário a cada volta.');
    }

    /**
     * O operador está MEXENDO nesta relação, ou só passando por ela?
     *
     * Mexer no critério ou no orçamento conta igual: os dois mudam a comparação, e quem
     * baixa a análise total até engolir o silêncio inicial introduziu o mesmo defeito de
     * quem subiu o silêncio inicial.
     *
     * @param array<string,mixed> $c a linha de `configuracao`, lida uma vez pelo chamador
     */
    private function mexeuNoCriterio(string $campo, array $c): bool
    {
        return self::inteiro($c[$campo] ?? null) !== $this->{$campo}
            || self::inteiro($c['pos_analise_total_ms'] ?? null) !== $this->pos_analise_total_ms;
    }

    /**
     * Há combinação inválida JÁ GRAVADA? A tela avisa em vez de calar.
     *
     * @param array<string,mixed> $c a linha de `configuracao`, lida uma vez pelo chamador
     * @return list<string> os rótulos dos critérios que nunca são alcançados
     */
    private function posInvalidoGravado(array $c): array
    {
        if (self::inteiro($c['classificador_pos_ativo'] ?? 1) === 0) {
            return [];
        }

        $gravado = PosAtendimento::deLinha($c);

        $mortos = [];
        foreach ($gravado->criteriosMortos() as $campo => $rotulo) {
            $mortos[] = sprintf('%s (%d ms)', $rotulo, $gravado->valor($campo));
        }

        return $mortos;
    }

    /**
     * As regras dos nove, derivadas da declaração.
     *
     * Os limites vinham escritos à mão, e `max:65535` é o teto do SMALLINT das colunas:
     * aceitar mais faria o MySQL truncar em silêncio, e o operador veria um número na
     * tela e outro no classificador. Agora o teto vem do mesmo lugar que o `max` do
     * input, então tela e validação não têm como discordar.
     *
     * @return array<string,list<string>>
     */
    private function regrasDoPos(): array
    {
        $regras = [];
        foreach (PosAtendimento::parametros() as $p) {
            $regras[$p->coluna] = ['required', 'integer', 'min:' . $p->minimo, 'max:' . $p->maximo];
        }

        return $regras;
    }

    /** @return array<string,int> */
    private function valoresDoFormulario(): array
    {
        $valores = [];
        foreach (PosAtendimento::parametros() as $p) {
            $valores[$p->coluna] = (int) $this->{$p->coluna};
        }

        return $valores;
    }

    /** @return array<string,mixed> */
    private function linhaGravada(): array
    {
        $linha = DB::table('configuracao')->where('id', 1)->first();

        return $linha === null ? [] : (array) $linha;
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

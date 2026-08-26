<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Dominio\Telefone;
use App\Importacao\Cabecalho;
use App\Importacao\Csv;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * Importação de lista de Leads.
 *
 * Duas etapas de propósito: enviar e depois CONFERIR o mapeamento. Importar direto
 * no upload economizaria um clique e trocaria isso por uma lista de 50 mil Leads
 * com a coluna errada no telefone — que só aparece depois de o dialer discar, e aí
 * já custou dinheiro.
 *
 * 🔴 A LISTA DE BLOQUEIO NÃO É APLICADA AQUI, e é decisão.
 *
 * O dialer já verifica bloqueio no momento de reivindicar o Lead. Filtrar também
 * na importação criaria a MESMA regra em dois lugares — e "regra duplicada sempre
 * diverge". Pior: alguém pode entrar na lista de bloqueio DEPOIS do arquivo ser
 * carregado, então a verificação na importação nem estaria correta. O número entra;
 * quem decide se disca é a discagem.
 */
final class ImportarLista extends Component
{
    use WithFileUploads;

    public int $campanhaId;

    public string $campanhaNome = '';

    public ?TemporaryUploadedFile $arquivo = null;

    /** 'enviar' | 'mapear' | 'pronto' */
    public string $etapa = 'enviar';

    /** @var list<string> */
    public array $colunas = [];

    /**
     * O que na primeira linha parece dado em vez de nome de coluna.
     *
     * 🔴 AVISO, NÃO RECUSA. Cabeçalho legítimo pode ter nome estranho — CRM exporta
     * código de campo como "1281" —, e recusar impediria importação válida. Ver
     * `Importacao\Cabecalho`.
     *
     * @var list<array{coluna:string, motivo:string}>
     */
    public array $cabecalhoSuspeito = [];

    /** @var list<list<string>> */
    public array $amostra = [];

    public int $colunaTelefone = 0;

    public string $delimitador = ',';

    public string $codificacao = 'UTF-8';

    public int $inseridos = 0;

    public int $duplicados = 0;

    public int $rejeitados = 0;

    /** @var list<array{linha:int, valor:string}> */
    public array $exemplosRejeitados = [];

    /** Quantas linhas de exemplo guardar do que foi recusado. */
    private const EXEMPLOS = 10;

    /** Tamanho do lote de inserção. */
    private const LOTE = 500;

    public function mount(int $id): void
    {
        $nome = DB::table('campanha')->where('id', $id)->value('nome');
        if ($nome === null) {
            abort(404);
        }

        $this->campanhaId = $id;
        $this->campanhaNome = is_string($nome) ? $nome : '';
    }

    public function render(): View
    {
        return view('livewire.importar-lista');
    }

    /**
     * Roda quando o arquivo termina de subir. Lê só o começo: o objetivo é mostrar
     * o que o sistema entendeu, não processar nada ainda.
     */
    public function updatedArquivo(): void
    {
        $this->validate([
            // `mimes:csv,txt` sozinho recusa CSV legítimo, porque o tipo detectado
            // para CSV varia entre text/plain, text/csv e application/csv conforme
            // o sistema de quem enviou. `extensions` é o que o operador controla.
            'arquivo' => ['required', 'file', 'max:51200', 'extensions:csv,txt'],
        ], [
            'arquivo.extensions' => 'Envie um arquivo .csv. Planilha do Excel precisa ser salva como CSV antes.',
            'arquivo.max' => 'O arquivo passa de 50 MB. Divida a lista em partes.',
        ]);

        // `getRealPath()` é tipado como `string` no TemporaryUploadedFile, então só
        // o `null` do operador seguro pode acontecer aqui.
        $caminho = $this->arquivo?->getRealPath();
        if ($caminho === null) {
            return;
        }

        $csv = new Csv();

        $amostraBruta = (string) file_get_contents($caminho, false, null, 0, 64 * 1024);
        $d = $csv->detectar($amostraBruta);
        $this->delimitador = $d['delimitador'];
        $this->codificacao = $d['codificacao'];

        $fluxo = fopen($caminho, 'r');
        if ($fluxo === false) {
            return;
        }

        $this->colunas = [];
        $this->amostra = [];
        $this->cabecalhoSuspeito = [];

        foreach ($csv->linhas($fluxo, $this->delimitador, $this->codificacao) as $i => $linha) {
            if ($i === 0) {
                $this->colunas = $linha;
                continue;
            }
            $this->amostra[] = $linha;
            if (count($this->amostra) >= 5) {
                break;
            }
        }
        fclose($fluxo);

        if ($this->colunas === []) {
            $this->addError('arquivo', 'O arquivo está vazio ou não tem cabeçalho.');

            return;
        }

        $this->cabecalhoSuspeito = (new Cabecalho())->suspeitas($this->colunas);
        $this->colunaTelefone = $this->adivinharColunaTelefone();
        $this->etapa = 'mapear';
    }

    /**
     * Adivinha por NOME primeiro e por CONTEÚDO depois.
     *
     * Nome é mais confiável quando existe, e a amostra decide quando o cabeçalho é
     * genérico — planilha exportada de CRM costuma ter "Campo 1", "Campo 2".
     */
    private function adivinharColunaTelefone(): int
    {
        foreach ($this->colunas as $i => $nome) {
            if (preg_match('/tel|fone|celular|whats|m[oó]vel/i', $nome) === 1) {
                return $i;
            }
        }

        $t = new Telefone();
        $melhor = 0;
        $maisValidos = 0;

        foreach (array_keys($this->colunas) as $i) {
            $validos = 0;
            foreach ($this->amostra as $linha) {
                if (isset($linha[$i]) && $t->tentar($linha[$i]) !== null) {
                    $validos++;
                }
            }
            if ($validos > $maisValidos) {
                $maisValidos = $validos;
                $melhor = $i;
            }
        }

        return $melhor;
    }

    public function importar(): void
    {
        $caminho = $this->arquivo?->getRealPath();
        if ($caminho === null) {
            $this->addError('arquivo', 'O arquivo temporário expirou. Envie de novo.');

            return;
        }

        $fluxo = fopen($caminho, 'r');
        if ($fluxo === false) {
            $this->addError('arquivo', 'Não consegui abrir o arquivo.');

            return;
        }

        $csv = new Csv();
        $t = new Telefone();

        $this->inseridos = 0;
        $this->duplicados = 0;
        $this->rejeitados = 0;
        $this->exemplosRejeitados = [];

        $lote = [];
        $tentados = 0;

        foreach ($csv->linhas($fluxo, $this->delimitador, $this->codificacao) as $i => $linha) {
            if ($i === 0) {
                continue;   // cabeçalho
            }

            $bruto = $linha[$this->colunaTelefone] ?? '';
            $canonico = $t->tentar($bruto);

            if ($canonico === null) {
                $this->rejeitados++;
                if (count($this->exemplosRejeitados) < self::EXEMPLOS) {
                    // Guarda o número da LINHA do arquivo, para o operador achar no
                    // Excel. `$i + 1` porque a contagem da planilha começa em 1 e o
                    // cabeçalho é a linha 1.
                    $this->exemplosRejeitados[] = ['linha' => $i + 1, 'valor' => $bruto];
                }
                continue;
            }

            // Toda coluna que não é o telefone vira material de substituição,
            // chaveada pelo NOME do cabeçalho — que é o mesmo nome que o mapa de
            // template da Campanha referencia.
            $extras = [];
            foreach ($this->colunas as $c => $nome) {
                if ($c === $this->colunaTelefone || $nome === '') {
                    continue;
                }
                $extras[$nome] = $linha[$c] ?? '';
            }

            $lote[] = [
                'campanha_id' => $this->campanhaId,
                'telefone' => $canonico,
                'dados_extras' => $extras === [] ? null : json_encode($extras, JSON_UNESCAPED_UNICODE),
                'status' => 1,
            ];
            $tentados++;

            if (count($lote) >= self::LOTE) {
                $this->inseridos += $this->gravar($lote);
                $lote = [];
            }
        }

        if ($lote !== []) {
            $this->inseridos += $this->gravar($lote);
        }

        fclose($fluxo);

        // Duplicata é o que foi tentado e não entrou. Inclui repetição DENTRO do
        // arquivo e número que já estava na Campanha de uma importação anterior —
        // para o operador as duas coisas são a mesma: "não vai discar duas vezes".
        $this->duplicados = $tentados - $this->inseridos;

        $this->etapa = 'pronto';
    }

    /**
     * 🔴 `ON DUPLICATE KEY UPDATE campanha_id = campanha_id` — no-op deliberado.
     *
     * O UNIQUE(campanha_id, telefone) dá a idempotência no ESQUEMA; isto a torna
     * reentrante sem engolir outros erros, ao contrário de `INSERT IGNORE`, que
     * silencia violação de chave estrangeira e valor fora de faixa junto.
     *
     * O retorno é o número REALMENTE inserido: o MySQL conta 1 por inserção e 0
     * quando o update não muda nada — e atribuir uma coluna a ela mesma nunca muda
     * nada. É daí que sai a contagem de duplicatas, sem uma segunda consulta.
     *
     * 🔴 NÃO VOLTE PARA `id = id`. O no-op é o mesmo, mas `id` é AUTO_INCREMENT, e o
     * MySQL 8 recusa `INSERT ... ON DUPLICATE KEY UPDATE` que atribua à coluna
     * auto-increment quando o LOTE tem duplicata dentro dele:
     *
     *     SQLSTATE[HY000]: General error: 1869
     *     Auto-increment value in UPDATE conflicts with internally generated values
     *
     * MEDIDO: lote de 3 linhas com uma repetida quebra; a MESMA instrução linha a
     * linha passa. É por isso que o defeito não aparecia — os outros usos do idioma
     * no projeto (`Fastagi\Banco`, `Worker\FilaDeEventos`) inserem uma linha de cada
     * vez e continuam corretos. Só aqui a inserção é em lote, e só aqui um arquivo com
     * telefone repetido derrubava a importação inteira com 500.
     *
     * `campanha_id` serve porque está no UNIQUE: numa colisão ele já vale o que a
     * cláusula atribui, então o update não muda nada e a contagem continua exata.
     *
     * @param list<array<string,mixed>> $lote
     */
    private function gravar(array $lote): int
    {
        if ($lote === []) {
            return 0;
        }

        $colunas = ['campanha_id', 'telefone', 'dados_extras', 'status'];
        $marcadores = [];
        $valores = [];

        foreach ($lote as $l) {
            $marcadores[] = '(?, ?, ?, ?)';
            foreach ($colunas as $c) {
                $valores[] = $l[$c] ?? null;
            }
        }

        $sql = 'INSERT INTO leads (' . implode(',', $colunas) . ') VALUES '
            . implode(',', $marcadores)
            . ' ON DUPLICATE KEY UPDATE campanha_id = campanha_id';

        return DB::connection()->affectingStatement($sql, $valores);
    }

    /** Volta para a etapa de envio sem recarregar a página. */
    public function outroArquivo(): void
    {
        $this->reset(['arquivo', 'colunas', 'amostra', 'colunaTelefone', 'etapa',
            'inseridos', 'duplicados', 'rejeitados', 'exemplosRejeitados']);
        $this->etapa = 'enviar';
    }
}

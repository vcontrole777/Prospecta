<?php

declare(strict_types=1);

namespace App\Livewire;

use Illuminate\Contracts\View\View;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Resultados de uma Campanha: os desfechos e o dado coletado.
 *
 * 🔴 A TELA QUE FALTAVA PARA A CAPTURA EXISTIR DE VERDADE.
 *
 * `ligacao.respostas` era escrita pelo fastagi e lida por NINGUÉM. Numa Campanha de
 * captura aquele JSON é o produto inteiro — o cliente paga por canal para obter CPF e
 * matrícula, a URA os coletava, e o operador não tinha como ver nem tirar do banco.
 * Mesma classe do `prospecta/campo`: um caminho de escrita completo sem caminho de
 * leitura, que não falha em lugar nenhum e simplesmente não serve para nada.
 *
 * A exportação é o ponto, não a tabela. Tabela é para conferir; o que vai para o
 * sistema do cliente é o CSV.
 */
final class Resultados extends Component
{
    use WithPagination;

    public int $campanhaId;

    public string $campanhaNome = '';

    public string $campanhaTipo = '';

    /**
     * Filtro por desfecho. Na URL para o operador poder mandar o link de "só os
     * erros" a alguém.
     */
    #[Url(as: 'd', except: '')]
    public string $desfecho = '';

    /** Só atendidas — é o corte que separa "a lista é ruim" de "a oferta é ruim". */
    #[Url(as: 'at', except: false)]
    public bool $somenteAtendidas = false;

    /**
     * Desfechos na ordem em que o operador os procura, não na do enum.
     *
     * @var array<string,string>
     */
    public const DESFECHOS = [
        'opt_in' => 'Opt-in',
        'captura_concluida' => 'Captura concluída',
        'captura_parcial' => 'Captura parcial',
        'opt_out' => 'Opt-out',
        'sem_resposta' => 'Sem resposta',
        'nao_atendeu' => 'Não atendeu',
        'maquina' => 'Máquina',
        'invalido' => 'Inválido',
        'erro' => 'Erro',
    ];

    public function mount(int $id): void
    {
        $linha = DB::table('campanha')->where('id', $id)->first(['nome', 'tipo']);
        if ($linha === null) {
            abort(404);
        }
        $c = (array) $linha;

        $this->campanhaId = $id;
        $this->campanhaNome = self::texto($c['nome'] ?? null);
        $this->campanhaTipo = self::texto($c['tipo'] ?? null);
    }

    /** Trocar de filtro tem que voltar para a primeira página, ou a tela abre vazia. */
    public function updatedDesfecho(): void
    {
        $this->resetPage();
    }

    public function updatedSomenteAtendidas(): void
    {
        $this->resetPage();
    }

    public function render(): View
    {
        return view('livewire.resultados', [
            'campos' => $this->campos(),
            'contagens' => $this->contagens(),
            'ligacoes' => $this->pagina(),
            'gravacaoDir' => $this->diretorioDeGravacao(),
            // O diagnóstico UMA vez, no topo, em vez de "não consigo verificar" repetido
            // em cinquenta linhas: a causa é a mesma para todas, e é de configuração.
            'gravacaoAcessivel' => $this->gravacaoAcessivel(),
        ]);
    }

    /**
     * 🔴 O CABEÇALHO VEM DA DEFINIÇÃO DA CAMPANHA, NUNCA DAS CHAVES DO JSON.
     *
     * Tentador derivar as colunas de `array_keys($respostas)` da primeira linha. Seria
     * errado de um modo que não aparece em teste com dado bonito: `captura_parcial`
     * grava as respostas obtidas ATÉ o campo que falhou, então uma Ligação que só
     * respondeu o CPF tem uma chave e a seguinte tem três. Cada linha ganharia colunas
     * diferentes, e no CSV — que é posicional — a matrícula de um cairia debaixo do
     * cabeçalho "CPF" do outro.
     *
     * Vindo da definição, a coluna existe para todo mundo e a parcial fica com célula
     * vazia, que é a verdade.
     *
     * @return list<array{nome:string, tipo:string}>
     */
    private function campos(): array
    {
        $saida = [];

        foreach (DB::select(
            'SELECT cd.nome, cd.tipo
               FROM campanha_campo cc
               JOIN campo_definicao cd ON cd.id = cc.campo_definicao_id
              WHERE cc.campanha_id = ?
              ORDER BY cc.ordem, cc.id',
            [$this->campanhaId],
        ) as $linha) {
            $c = (array) $linha;
            $saida[] = [
                'nome' => self::texto($c['nome'] ?? null),
                'tipo' => self::texto($c['tipo'] ?? null),
            ];
        }

        return $saida;
    }

    /**
     * Contagem por desfecho, com o total e as atendidas.
     *
     * Uma consulta agregada só, sem juntar `leads`: a lista de Campanhas já pagou o
     * preço de descobrir que juntar as duas tabelas no mesmo GROUP BY faz produto
     * cartesiano e infla toda contagem.
     *
     * @return array{total:int, atendidas:int, desfechos:array<string,int>, sem_desfecho:int}
     */
    private function contagens(): array
    {
        $desfechos = [];
        $total = 0;
        $atendidas = 0;
        $semDesfecho = 0;

        foreach (DB::select(
            'SELECT desfecho, COUNT(*) AS quantas,
                    COALESCE(SUM(atendida_em IS NOT NULL), 0) AS atendidas
               FROM ligacao
              WHERE campanha_id = ?
              GROUP BY desfecho',
            [$this->campanhaId],
        ) as $linha) {
            $l = (array) $linha;
            $quantas = self::inteiro($l['quantas'] ?? null);
            $total += $quantas;
            $atendidas += self::inteiro($l['atendidas'] ?? null);

            $chave = $l['desfecho'] ?? null;
            if (! is_string($chave) || $chave === '') {
                // NULL não é categoria: é Ligação em curso, ou que morreu antes de
                // qualquer conclusão. Contar como "erro" mentiria; omitir esconderia o
                // buraco entre o total e a soma dos desfechos.
                $semDesfecho += $quantas;
                continue;
            }

            $desfechos[$chave] = $quantas;
        }

        return [
            'total' => $total,
            'atendidas' => $atendidas,
            'desfechos' => $desfechos,
            'sem_desfecho' => $semDesfecho,
        ];
    }

    /**
     * Uma página de Ligações.
     *
     * PAGINADO, e é requisito, não conforto: 20 mil Leads a 3 tentativas são 60 mil
     * linhas. Renderizar tudo derruba o navegador do operador exatamente na Campanha
     * grande, que é a que ele mais precisa ver.
     *
     * @return LengthAwarePaginator<int, object>
     */
    private function pagina(): LengthAwarePaginator
    {
        /** @var LengthAwarePaginator<int, object> */
        return $this->consulta()
            ->orderByDesc('l.id')
            ->paginate(50);
    }

    /**
     * A consulta base, compartilhada pela tela e pela exportação.
     *
     * Compartilhar é o ponto: se a tabela e o CSV filtrassem por caminhos diferentes, o
     * operador conferiria na tela e exportaria outro conjunto — e não teria como saber.
     *
     * @return \Illuminate\Database\Query\Builder
     */
    private function consulta(): \Illuminate\Database\Query\Builder
    {
        $q = DB::table('ligacao as l')
            ->leftJoin('leads as le', 'le.id', '=', 'l.lead_id')
            ->where('l.campanha_id', $this->campanhaId)
            ->select([
                'l.id', 'l.numero_tentativa', 'l.iniciada_em', 'l.atendida_em',
                'l.duracao_seg', 'l.duracao_falada_seg', 'l.codigo_sip', 'l.hangup_cause',
                'l.amd_status', 'l.desfecho', 'l.respostas', 'l.tecla_digitada',
                'l.bina', 'l.gravacao', 'le.telefone',
            ]);

        // `array_key_exists` e não `in_array` sobre valores: a chave é o que vai para o
        // SQL, e aceitar qualquer texto aqui poria valor do usuário num `where` contra
        // uma coluna ENUM — o MySQL responderia com aviso e zero linhas, e a tela
        // pareceria "sem resultado" em vez de "filtro inválido".
        if ($this->desfecho !== '' && array_key_exists($this->desfecho, self::DESFECHOS)) {
            $q->where('l.desfecho', $this->desfecho);
        }

        if ($this->somenteAtendidas) {
            $q->whereNotNull('l.atendida_em');
        }

        return $q;
    }

    /**
     * 🔴 EXPORTAÇÃO EM FLUXO, não um array montado na memória.
     *
     * `->get()` sobre 60 mil linhas com JSON em cada uma estoura o `memory_limit` do
     * PHP-FPM, e o sintoma é um 500 sem corpo justamente na Campanha grande. `chunkById`
     * mantém a memória constante e `streamDownload` manda os bytes conforme saem.
     *
     * `chunkById` e não `chunk`: paginação por OFFSET sobre tabela que o dialer está
     * escrevendo ao mesmo tempo PULA linha — cada inserção empurra o offset — e uma
     * exportação com furos é pior que nenhuma, porque parece completa.
     */
    public function exportar(): StreamedResponse
    {
        $campos = $this->campos();
        $consulta = $this->consulta();

        $nome = sprintf(
            'prospecta-%d-%s.csv',
            $this->campanhaId,
            preg_replace('/[^a-z0-9]+/', '-', mb_strtolower($this->campanhaNome)) ?: 'campanha',
        );

        return response()->streamDownload(function () use ($campos, $consulta): void {
            $saida = fopen('php://output', 'w');
            if ($saida === false) {
                return;
            }

            // BOM de UTF-8. Sem ele o Excel abre o arquivo em Latin-1 e todo acento vira
            // caractere quebrado — "Não atendeu" fica "NÃ£o atendeu". É a mesma razão
            // pela qual a importação deixa a codificação escolhível.
            fwrite($saida, "\xEF\xBB\xBF");

            $cabecalho = ['ligacao', 'telefone', 'tentativa', 'iniciada_em', 'atendida_em',
                'duracao_seg', 'duracao_falada_seg', 'desfecho', 'tecla', 'codigo_sip',
                'amd_status', 'bina', 'gravacao'];

            foreach ($campos as $campo) {
                $cabecalho[] = $campo['nome'];
            }

            // Ponto e vírgula: é o separador que o Excel em português espera. Com vírgula
            // ele joga a linha inteira numa célula só, e o operador conclui que o
            // arquivo está corrompido. A importação detecta os dois, então o ida-e-volta
            // continua funcionando.
            fputcsv($saida, $cabecalho, ';');

            $consulta->orderBy('l.id')->chunkById(500, function (\Illuminate\Support\Collection $linhas) use ($saida, $campos): void {
                foreach ($linhas as $linha) {
                    $l = (array) $linha;
                    $respostas = self::respostas($l['respostas'] ?? null);

                    $celulas = [
                        self::inteiro($l['id'] ?? null),
                        self::texto($l['telefone'] ?? null),
                        self::inteiro($l['numero_tentativa'] ?? null),
                        self::texto($l['iniciada_em'] ?? null),
                        self::texto($l['atendida_em'] ?? null),
                        $l['duracao_seg'] ?? '',
                        $l['duracao_falada_seg'] ?? '',
                        self::texto($l['desfecho'] ?? null),
                        self::texto($l['tecla_digitada'] ?? null),
                        self::texto($l['codigo_sip'] ?? null),
                        self::texto($l['amd_status'] ?? null),
                        self::texto($l['bina'] ?? null),
                        self::texto($l['gravacao'] ?? null),
                    ];

                    // Pela DEFINIÇÃO, não pelas chaves da linha — é o que mantém a
                    // coluna alinhada quando a captura foi parcial.
                    foreach ($campos as $campo) {
                        $celulas[] = $respostas[$campo['nome']] ?? '';
                    }

                    fputcsv($saida, $celulas, ';');
                }
            }, 'l.id', 'id');

            fclose($saida);
        }, $nome, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * O JSON do banco virando array de texto.
     *
     * @return array<string,string>
     */
    public static function respostas(mixed $json): array
    {
        if (! is_string($json) || $json === '') {
            return [];
        }

        $dados = json_decode($json, true);
        if (! is_array($dados)) {
            return [];
        }

        $saida = [];
        foreach ($dados as $chave => $valor) {
            if (is_string($chave) && (is_string($valor) || is_numeric($valor))) {
                $saida[$chave] = (string) $valor;
            }
        }

        return $saida;
    }

    /**
     * O diretório onde o MixMonitor grava — o MESMO que o fastagi usa.
     *
     * A tela confere se o arquivo existe porque a coluna preenchida NÃO é prova: com o
     * diretório sem permissão o MixMonitor erra no log do Asterisk e a chamada segue
     * normal, deixando no banco um caminho que não existe. Sem essa conferência o
     * operador clica para ouvir e não entende por que não há nada.
     */
    private function diretorioDeGravacao(): string
    {
        return rtrim(self::texto(config('fastagi.gravacao_dir')), '/');
    }

    /**
     * O painel consegue atravessar o diretório de gravação?
     *
     * Verdadeiro quando o diretório não existe TAMBÉM: gravação desligada, ou instância
     * nova, não é problema de acesso, e avisar sobre permissão nesse caso seria mandar o
     * operador atrás de um defeito que não existe. Quem cuida de "a gravação está ligada e
     * o arquivo não apareceu" é a coluna, linha por linha.
     */
    private function gravacaoAcessivel(): bool
    {
        $dir = $this->diretorioDeGravacao();

        if ($dir === '' || ! is_dir($dir)) {
            return true;
        }

        return is_readable($dir) && is_executable($dir);
    }

    /**
     * 🔴 `is_file() === false` NÃO PROVA QUE O ARQUIVO NÃO EXISTE.
     *
     * Prova que este processo não consegue vê-lo. As duas causas são bem diferentes:
     *
     * · o arquivo não existe — o MixMonitor falhou, e é o defeito que esta coluna existe
     *   para denunciar;
     * · o php-fpm não atravessa o diretório. As gravações são `asterisk:asterisk` modo
     *   2770 e o painel roda como `prospecta`, que precisa estar no grupo `asterisk`.
     *   Grupo suplementar é fixado no `exec`: se a adesão foi concedida depois de o fpm
     *   subir, os workers em execução não a têm até reiniciarem.
     *
     * Reportar as duas como "não está no disco" é acusar o Asterisk de um problema que
     * pode ser nosso — e, pior, treina o operador a ignorar exatamente o aviso que um dia
     * vai estar certo. Um alarme que mente é pior que alarme nenhum.
     *
     * Então a tela responde três coisas, não duas: existe, não existe, ou não sei dizer.
     */
    public static function situacaoDaGravacao(string $caminho): string
    {
        if ($caminho === '') {
            return 'sem_gravacao';
        }

        if (is_file($caminho)) {
            return 'existe';
        }

        // A pergunta certa antes de acusar: eu conseguiria ver este arquivo se ele
        // estivesse aí? Sem travessia no diretório, `is_file` responde `false` para
        // arquivo presente — e a resposta honesta é que não há resposta.
        //
        // Diretório INEXISTENTE não entra aqui de propósito: ele é prova de que o arquivo
        // não existe, não de cegueira. Só diretório que existe e não abre é indecidível.
        $diretorio = \dirname($caminho);
        if (is_dir($diretorio) && (! is_readable($diretorio) || ! is_executable($diretorio))) {
            return 'sem_acesso';
        }

        return 'ausente';
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

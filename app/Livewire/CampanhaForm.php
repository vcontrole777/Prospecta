<?php

declare(strict_types=1);

namespace App\Livewire;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

/**
 * Criar e editar Campanha.
 *
 * 🔴 O FORMULÁRIO SÓ TEM CAMPO QUE ALGUM PROCESSO LÊ.
 *
 * A tabela `campanha` tem 25 colunas e este formulário cobre 15. As ausências são
 * medidas, não esquecimento: `texto_mensagem`, `texto_sms`, `colunas` e `fluxo`
 * não são lidos pelo dialer, pelo fastagi nem pelo worker. Campo em tela que
 * nenhum processo consome é promessa falsa — o operador preenche, confere, e nada
 * acontece.
 *
 * Quem lê o quê, e é isto que define o formulário:
 *   dialer   → max_tentativas, bina, bina_regra, chamadas_simultaneas, status
 *   fastagi  → tipo, tecla_opt_in, tecla_opt_out, tentativas_ura, os 4 audio_*_id
 *   worker   → canal_opt_in, template_nome, template_idioma, template_mapa
 *
 * 🔴 CAMPOS DE CAPTURA DEFINIDOS AQUI, não em tela separada.
 *
 * `campo_definicao` é global e reaproveitável — duas Campanhas que pedem CPF compartilham
 * a definição —, e isso sugeriria uma tela de catálogo. Mas o operador não pensa em
 * "catálogo de campos": ele pensa "esta Campanha pergunta CPF e número do contrato".
 * Definir aqui e reaproveitar por (nome, tipo, tamanho) dá o mesmo resultado no banco com
 * uma tela a menos.
 */
final class CampanhaForm extends Component
{
    public ?int $campanhaId = null;

    public string $tipo = 'opt_in';

    public string $nome = '';

    /**
     * Campos de captura, NA ORDEM em que serão perguntados.
     *
     * Mesma disciplina dos parâmetros de template: a ordem é o índice, então `ordem` não
     * pode ter lacuna — que faria a URA perguntar fora de sequência.
     *
     * @var list<array{nome:string, tipo:string, tamanho:int, audio_prompt_id:?int, obrigatorio:bool, tentativas:int, timeout_seg:int}>
     */
    public array $campos = [];

    public int $chamadas_simultaneas = 1;

    public int $max_tentativas = 3;

    public int $tentativas_ura = 2;

    public string $bina_regra = 'unica';

    public string $bina = '';

    public string $tecla_opt_in = '1';

    public string $tecla_opt_out = '9';

    public ?int $audio_mensagem_id = null;

    public ?int $audio_confirma_opt_in_id = null;

    public ?int $audio_confirma_opt_out_id = null;

    public ?int $audio_nao_entendi_id = null;

    public string $canal_opt_in = 'whatsapp';

    public string $template_nome = '';

    public string $template_idioma = 'pt_BR';

    /**
     * Nomes de coluna, NA ORDEM. A posição no template é o índice + 1.
     *
     * 🔴 Assim o estado inválido é IRREPRESENTÁVEL em vez de validado.
     *
     * `Placeholder::parametrosTemplate` lança quando o mapa tem lacuna — `{"1":…,
     * "3":…}` — porque template posicional com lacuna manda parâmetro no lugar
     * errado e o Lead recebe a mensagem de outra pessoa. Guardar uma LISTA e
     * derivar as posições dela torna a lacuna impossível de criar, o que é melhor
     * que uma regra de validação avisando sobre ela.
     *
     * @var list<string>
     */
    public array $parametros = [];

    public function mount(?int $id = null): void
    {
        if ($id === null) {
            return;
        }

        $linha = DB::table('campanha')->where('id', $id)->first();
        if ($linha === null) {
            abort(404);
        }

        $c = (array) $linha;

        $this->campanhaId = $id;
        $this->nome = self::texto($c['nome'] ?? null);
        $this->chamadas_simultaneas = max(1, self::inteiro($c['chamadas_simultaneas'] ?? null));
        $this->max_tentativas = max(1, self::inteiro($c['max_tentativas'] ?? null));
        $this->tentativas_ura = max(1, self::inteiro($c['tentativas_ura'] ?? null));
        $this->bina_regra = self::texto($c['bina_regra'] ?? null) ?: 'unica';
        $this->bina = self::texto($c['bina'] ?? null);
        // Recai no padrão quando a coluna está NULL, como em `bina_regra` e
        // `template_idioma`. Toda Campanha criada por SQL antes desta tela tem as
        // teclas nulas, e sem o padrão o formulário abriria vazio e recusaria salvar
        // — o operador veria campo obrigatório em branco num cadastro que ele não
        // fez. As teclas são obrigatórias com razão: o fastagi compara o dígito
        // contra elas, e NULL nunca casa, então opt-in seria impossível.
        $this->tecla_opt_in = self::texto($c['tecla_opt_in'] ?? null) ?: '1';
        $this->tecla_opt_out = self::texto($c['tecla_opt_out'] ?? null) ?: '9';
        $this->audio_mensagem_id = self::inteiroOuNulo($c['audio_mensagem_id'] ?? null);
        $this->audio_confirma_opt_in_id = self::inteiroOuNulo($c['audio_confirma_opt_in_id'] ?? null);
        $this->audio_confirma_opt_out_id = self::inteiroOuNulo($c['audio_confirma_opt_out_id'] ?? null);
        $this->audio_nao_entendi_id = self::inteiroOuNulo($c['audio_nao_entendi_id'] ?? null);
        $this->canal_opt_in = self::texto($c['canal_opt_in'] ?? null) ?: 'whatsapp';
        $this->template_nome = self::texto($c['template_nome'] ?? null);
        $this->template_idioma = self::texto($c['template_idioma'] ?? null) ?: 'pt_BR';

        $this->tipo = self::texto($c['tipo'] ?? null) === 'captura' ? 'captura' : 'opt_in';

        foreach (DB::select(
            'SELECT cd.nome, cd.tipo, cd.tamanho, cc.audio_prompt_id, cc.obrigatorio,
                    cc.tentativas, cc.timeout_seg
               FROM campanha_campo cc
               JOIN campo_definicao cd ON cd.id = cc.campo_definicao_id
              WHERE cc.campanha_id = ?
              ORDER BY cc.ordem',
            [$id],
        ) as $linha) {
            $cc = (array) $linha;
            $this->campos[] = [
                'nome' => self::texto($cc['nome'] ?? null),
                'tipo' => self::texto($cc['tipo'] ?? null) === 'cpf' ? 'cpf' : 'numero',
                'tamanho' => max(1, self::inteiro($cc['tamanho'] ?? null)),
                'audio_prompt_id' => self::inteiroOuNulo($cc['audio_prompt_id'] ?? null),
                'obrigatorio' => (bool) ($cc['obrigatorio'] ?? false),
                'tentativas' => max(1, self::inteiro($cc['tentativas'] ?? null)),
                'timeout_seg' => max(1, self::inteiro($cc['timeout_seg'] ?? null)),
            ];
        }

        $mapa = json_decode(self::texto($c['template_mapa'] ?? null), true);
        if (is_array($mapa)) {
            // Reordena por posição numérica: a ordem das chaves no JSON não é
            // garantia de nada, e ler na ordem errada trocaria os parâmetros.
            $porPosicao = [];
            foreach ($mapa as $pos => $coluna) {
                if (is_string($coluna)) {
                    $porPosicao[(int) $pos] = $coluna;
                }
            }
            ksort($porPosicao);
            $this->parametros = array_values($porPosicao);
        }
    }

    public function render(): View
    {
        return view('livewire.campanha-form', ['audios' => $this->audios()]);
    }

    public function adicionarParametro(): void
    {
        $this->parametros[] = '';
    }

    public function removerParametro(int $i): void
    {
        // Reconstrói em vez de `unset` + `array_values`: a posição no template vem
        // do índice, então buraco no array viraria buraco no mapa — exatamente o que
        // a lista existe para impedir. Escrito assim, a ausência de buraco é
        // evidente na leitura, e não uma consequência de duas chamadas em sequência.
        $restantes = [];
        foreach ($this->parametros as $indice => $valor) {
            if ($indice !== $i) {
                $restantes[] = $valor;
            }
        }

        $this->parametros = $restantes;
    }

    public function adicionarCampo(): void
    {
        $this->campos[] = [
            'nome' => '',
            'tipo' => 'cpf',
            // 🔴 CPF é 11 por definição: `Cpf::valido` exige exatamente 11 dígitos, e
            // campo `cpf` com outro tamanho NUNCA valida — a pessoa digitaria, erraria
            // todas as tentativas, e o campo sairia como não coletado sem explicação.
            'tamanho' => 11,
            'audio_prompt_id' => null,
            'obrigatorio' => true,
            'tentativas' => 2,
            'timeout_seg' => 15,
        ];
    }

    public function removerCampo(int $i): void
    {
        // Reconstrói: a ordem de pergunta vem do índice, e buraco no array viraria
        // `ordem` com lacuna.
        $restantes = [];
        foreach ($this->campos as $indice => $campo) {
            if ($indice !== $i) {
                $restantes[] = $campo;
            }
        }

        $this->campos = $restantes;
    }

    /**
     * Força o tamanho do CPF em 11.
     *
     * 🔴 `?string $chave`, e não `string`. O Livewire chama este gancho com a chave NULA
     * quando o array INTEIRO é atribuído — `set('campos', [...])` — e só com
     * `campos.0.tipo` quando um campo isolado muda. Declarar `string` fez os doze testes
     * quebrarem de uma vez com "Argument #2 must be of type string, null given".
     *
     * Chave nula é justamente o caso em que vale normalizar TUDO: quem atribuiu a lista
     * inteira pode ter posto vários campos `cpf` de uma vez.
     *
     * Sem isto, campo `cpf` com outro tamanho nunca valida — `Cpf::valido` exige
     * exatamente 11 dígitos —, e a pessoa digitaria, erraria todas as tentativas, e o
     * campo sairia como não coletado sem explicação.
     */
    public function updatedCampos(mixed $valor, ?string $chave = null): void
    {
        if ($chave === null) {
            foreach (array_keys($this->campos) as $i) {
                $this->normalizarCpf($i);
            }

            return;
        }

        if (! str_ends_with($chave, '.tipo')) {
            return;
        }

        $this->normalizarCpf((int) explode('.', $chave)[0]);
    }

    private function normalizarCpf(int $i): void
    {
        if (($this->campos[$i]['tipo'] ?? '') === 'cpf') {
            $this->campos[$i]['tamanho'] = 11;
        }
    }

    public function salvar(): void
    {
        $dados = $this->validate($this->regras(), $this->mensagens(), $this->rotulos());

        $mapa = [];
        foreach ($this->parametros as $i => $coluna) {
            $coluna = trim($coluna);
            if ($coluna !== '') {
                $mapa[(string) ($i + 1)] = $coluna;
            }
        }

        $valores = [
            'tipo' => $this->tipo,
            'nome' => trim($this->nome),
            'chamadas_simultaneas' => $this->chamadas_simultaneas,
            'max_tentativas' => $this->max_tentativas,
            'tentativas_ura' => $this->tentativas_ura,
            'bina_regra' => $this->bina_regra,
            // 🔴 NULL, nunca string vazia. Medido: BINA vazia pode sobrescrever o
            // que a rota ia colocar, ou sair como chamada ANÔNIMA, que tem
            // atendimento perto de zero e causa invisível. `Bina::derivar`
            // devolve null para nulo, que é "não peça CallerID nenhum".
            'bina' => trim($this->bina) === '' ? null : trim($this->bina),
            'tecla_opt_in' => $this->tecla_opt_in,
            'tecla_opt_out' => $this->tecla_opt_out,
            'audio_mensagem_id' => $this->audio_mensagem_id,
            'audio_confirma_opt_in_id' => $this->audio_confirma_opt_in_id,
            'audio_confirma_opt_out_id' => $this->audio_confirma_opt_out_id,
            'audio_nao_entendi_id' => $this->audio_nao_entendi_id,
            'canal_opt_in' => $this->canal_opt_in,
            'template_nome' => trim($this->template_nome) === '' ? null : trim($this->template_nome),
            'template_idioma' => $this->template_idioma,
            'template_mapa' => $mapa === [] ? null : json_encode($mapa, JSON_UNESCAPED_UNICODE),
        ];

        unset($dados);

        if ($this->campanhaId === null) {
            // Nasce em RASCUNHO (status 0). Criar já ativa faria o dialer começar a
            // discar no instante em que o operador clicou em salvar, antes de
            // importar um Lead — e a Campanha vazia seria concluída na hora.
            $valores['status'] = 0;
            $this->campanhaId = (int) DB::table('campanha')->insertGetId($valores);
        } else {
            DB::table('campanha')->where('id', $this->campanhaId)->update($valores);
        }

        $this->gravarCampos();

        session()->flash('recado', 'Campanha salva.');
        $this->redirectRoute('campanhas');
    }

    /**
     * Reescreve os campos da Campanha.
     *
     * 🔴 APAGA E REINSERE, em vez de casar linha por linha. A `ordem` vem do índice da
     * lista, então uma reordenação muda quase todas as linhas — casar por id daria o
     * mesmo resultado com mais código e mais chance de deixar `ordem` duplicada no meio.
     *
     * `campanha_campo` não é referenciada por nada: a `ligacao` guarda as respostas em
     * `respostas` (JSON), com o NOME do campo como chave. Então apagar não perde
     * histórico — a mesma razão pela qual a lista de BINA pode ser removida e o áudio
     * não.
     */
    private function gravarCampos(): void
    {
        if ($this->campanhaId === null) {
            return;
        }

        DB::table('campanha_campo')->where('campanha_id', $this->campanhaId)->delete();

        if ($this->tipo !== 'captura') {
            return;
        }

        foreach ($this->campos as $i => $campo) {
            $nome = trim($campo['nome']);
            if ($nome === '') {
                continue;
            }

            $tipo = $campo['tipo'] === 'cpf' ? 'cpf' : 'numero';
            // CPF é 11 por definição, e forçar aqui fecha o caminho para quem chegar por
            // outro lado que não o formulário.
            $tamanho = $tipo === 'cpf' ? 11 : max(1, $campo['tamanho']);

            // Reaproveita a definição quando já existe: duas Campanhas que pedem CPF
            // compartilham a linha, que é o propósito de `campo_definicao` ser global.
            $definicaoId = DB::table('campo_definicao')
                ->where('nome', $nome)
                ->where('tipo', $tipo)
                ->where('tamanho', $tamanho)
                ->value('id');

            if (! is_numeric($definicaoId)) {
                $definicaoId = DB::table('campo_definicao')->insertGetId([
                    'nome' => $nome,
                    'tipo' => $tipo,
                    'tamanho' => $tamanho,
                ]);
            }

            DB::table('campanha_campo')->insert([
                'campanha_id' => $this->campanhaId,
                'campo_definicao_id' => (int) $definicaoId,
                'ordem' => $i + 1,
                'audio_prompt_id' => $campo['audio_prompt_id'],
                'obrigatorio' => $campo['obrigatorio'] ? 1 : 0,
                'tentativas' => max(1, $campo['tentativas']),
                'timeout_seg' => max(3, $campo['timeout_seg']),
            ]);
        }
    }

    /** @return array<string, list<string>> */
    private function regras(): array
    {
        return [
            'nome' => ['required', 'string', 'max:255'],
            'tipo' => ['required', 'in:opt_in,captura'],

            // Captura sem campo é uma Campanha que liga, não pergunta nada e encerra.
            'campos' => [$this->tipo === 'captura' ? 'required' : 'array', 'array', 'max:10'],
            'campos.*.nome' => ['required', 'string', 'max:255'],
            'campos.*.tipo' => ['required', 'in:cpf,numero'],
            'campos.*.tamanho' => ['required', 'integer', 'min:1', 'max:30'],
            // 🔴 PROMPT OBRIGATÓRIO. Campo sem áudio fazia o `GET DATA` tocar nada: a
            // pessoa ouvia SILÊNCIO até o timeout, "errava" por não ter digitado, e o
            // campo saía como não coletado. O `Fluxo` agora recusa a captura inteira com
            // `AUDIO_NAO_CONFIGURADO`; exigir aqui evita chegar lá.
            'campos.*.audio_prompt_id' => ['required', 'integer', 'exists:audio,id'],
            'campos.*.tentativas' => ['required', 'integer', 'min:1', 'max:5'],
            'campos.*.timeout_seg' => ['required', 'integer', 'min:3', 'max:60'],

            // Sem máximo aqui: o teto real é o contratado da INSTÂNCIA, que vive no
            // .env dos processos de chamada e não é lido pelo painel. O dialer
            // aplica `min(contratado, pedido)` de qualquer forma, então validar
            // contra um número que o painel não conhece seria inventar um limite.
            'chamadas_simultaneas' => ['required', 'integer', 'min:1'],

            'max_tentativas' => ['required', 'integer', 'min:1', 'max:10'],
            'tentativas_ura' => ['required', 'integer', 'min:1', 'max:5'],

            'bina_regra' => ['required', 'in:unica,lista,espelho,espelho_fixo'],
            // Formato canônico: só dígitos, com país e DDD. `Bina::derivar` trabalha
            // por posição de dígito, e medido que BINA com `+` recebe `00` da rota e
            // chega ao Lead como +005562… na tela.
            'bina' => ['nullable', 'string', 'regex:/^[0-9]{12,13}$/'],

            'tecla_opt_in' => ['required', 'string', 'regex:/^[0-9*#]$/', 'different:tecla_opt_out'],
            'tecla_opt_out' => ['required', 'string', 'regex:/^[0-9*#]$/'],

            'audio_mensagem_id' => ['nullable', 'integer', 'exists:audio,id'],
            'audio_confirma_opt_in_id' => ['nullable', 'integer', 'exists:audio,id'],
            'audio_confirma_opt_out_id' => ['nullable', 'integer', 'exists:audio,id'],
            'audio_nao_entendi_id' => ['nullable', 'integer', 'exists:audio,id'],

            'canal_opt_in' => ['required', 'in:whatsapp,sms'],

            // 🔴 Obrigatório só quando é OPT-IN e WhatsApp — condição composta, que
            // `required_if` não expressa.
            //
            // Opt-in gera Ação de `disparo`, e o worker a recusa sem template, com motivo
            // `campanha_sem_template_ou_lead_sem_telefone`. Exigir aqui troca falha
            // silenciosa no envio por campo vermelho no cadastro.
            //
            // Captura não gera Ação nenhuma — nem de disparo, nem de evento. Exigir
            // ali recusaria uma Campanha de captura perfeitamente configurada — e foi o
            // que aconteceu: oito testes de captura quebraram pedindo um template que a
            // captura não usa.
            'template_nome' => $this->tipo === 'opt_in' && $this->canal_opt_in === 'whatsapp'
                ? ['required', 'string', 'max:255']
                : ['nullable', 'string', 'max:255'],
            'template_idioma' => ['required', 'string', 'max:10'],

            'parametros' => ['array', 'max:10'],
            'parametros.*' => ['required', 'string', 'max:255'],
        ];
    }

    /** @return array<string,string> */
    private function mensagens(): array
    {
        return [
            'bina.regex' => 'A BINA é só dígitos, com país e DDD — 5511987654321. Sem +, sem espaço.',
            'tecla_opt_in.regex' => 'Uma tecla só: 0 a 9, * ou #.',
            'tecla_opt_out.regex' => 'Uma tecla só: 0 a 9, * ou #.',
            'tecla_opt_in.different' => 'As teclas de opt-in e opt-out têm que ser diferentes.',
            'template_nome.required' => 'No WhatsApp o nome do template é obrigatório: sem ele o envio falha.',
            'parametros.*.required' => 'Parâmetro em branco — remova a linha ou preencha o nome da coluna.',
            'campos.required' => 'Campanha de captura precisa de ao menos um campo, senão ela liga e não pergunta nada.',
            'campos.*.audio_prompt_id.required' => 'Escolha o áudio que pergunta este campo: sem ele a pessoa ouve silêncio.',
            'campos.*.nome.required' => 'Dê um nome ao campo — é a chave com que a resposta é gravada.',
        ];
    }

    /** @return array<string,string> */
    private function rotulos(): array
    {
        return [
            'nome' => 'nome',
            'chamadas_simultaneas' => 'chamadas simultâneas',
            'max_tentativas' => 'máximo de tentativas',
            'tentativas_ura' => 'tentativas na URA',
            'bina_regra' => 'regra de BINA',
            'tecla_opt_in' => 'tecla de opt-in',
            'tecla_opt_out' => 'tecla de opt-out',
            'canal_opt_in' => 'canal',
            'template_nome' => 'nome do template',
            'template_idioma' => 'idioma do template',
        ];
    }

    /** @return list<array{id:int, nome:string}> */
    private function audios(): array
    {
        $saida = [];
        foreach (DB::select('SELECT id, nome FROM audio WHERE ativo = 1 ORDER BY nome') as $linha) {
            $a = (array) $linha;
            $saida[] = [
                'id' => self::inteiro($a['id'] ?? null),
                'nome' => self::texto($a['nome'] ?? null),
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

    private static function inteiroOuNulo(mixed $v): ?int
    {
        return is_numeric($v) ? (int) $v : null;
    }
}

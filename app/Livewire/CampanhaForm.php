<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Dominio\Placeholder;
use App\Seguranca\Cofre;
use App\Whatsapp\TemplatesAprovados;
use App\Worker\TransporteCurl;
use Closure;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Livewire\Component;
use Throwable;

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

    /**
     * O "recebi, obrigado" do fim da captura. SÓ vale para Campanha de captura — ver
     * `Fluxo::captura`, que o toca depois de gravar `captura_concluida` e só no sucesso.
     */
    public ?int $audio_confirma_captura_id = null;

    public ?int $audio_nao_entendi_id = null;

    public string $canal_opt_in = 'whatsapp';

    /**
     * O texto que o SMS manda, com Placeholder.
     *
     * 🔴 ENTROU NA TELA QUANDO GANHOU LEITOR. O docblock desta classe lista
     * `texto_sms` entre as ausências MEDIDAS — coluna que nenhum processo lia, e
     * campo assim é promessa falsa. `Worker\Sms` a lê agora, então ela cabe aqui.
     */
    public string $texto_sms = '';

    public string $template_nome = '';

    public string $template_idioma = 'pt_BR';

    /**
     * O template escolhido na lista, como `nome|idioma`.
     *
     * Chave COMPOSTA porque o par é que identifica: a Meta permite o mesmo `name` em
     * vários idiomas, e são templates distintos. Guardar só o nome deixaria o idioma
     * ambíguo, que é metade do erro que esta lista existe para eliminar.
     *
     * Vazio significa "digitar à mão" — o escape para quando a Meta não responde.
     */
    public string $template_escolhido = '';

    /**
     * Templates aprovados da conta, carregados uma vez no `mount`.
     *
     * 🔴 NO MOUNT, não no `render`. O Livewire re-renderiza a cada tecla digitada em
     * qualquer campo do formulário; buscar aqui seria uma chamada à Graph API por
     * caractere — lento para o operador e abuso da cota da Meta.
     *
     * @var list<array{chave:string, nome:string, idioma:string, parametros:int, nomes:list<string>, cabecalho:string, corpo:string, rotulo:string}>
     */
    public array $templates = [];

    /**
     * Por que a lista veio vazia, quando veio. Nulo quando está tudo bem.
     *
     * Existe para a tela poder dizer a diferença entre "não há template aprovado",
     * "o token não alcança a conta" e "a Meta está fora do ar" — três causas com
     * três ações diferentes do operador.
     */
    public ?string $templates_erro = null;

    /**
     * Mostrar os campos de nome e idioma para digitação.
     *
     * Falso por padrão: havendo lista, o operador não deve ver campo nenhum — escolher
     * já preenche os dois. Os campos existem para o caso em que a lista não serve, e
     * aparecem quando:
     *
     *   · não há template aprovado para oferecer (aí não há escolha possível);
     *   · a Campanha aponta para um template que saiu da lista — recusado, pausado ou
     *     apagado depois de ela ser criada — e esconder o valor gravado deixaria o
     *     operador sem saber o que está salvo;
     *   · ele pediu, pelo link "preencher à mão".
     */
    public bool $template_manual = false;

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

    /**
     * Imagem do cabecalho, quando o template exige uma.
     *
     * SO FAZ SENTIDO PARA TEMPLATE COM `HEADER format=IMAGE`, e mandar cabecalho
     * onde ele nao e pedido da o MESMO erro 132000 ao contrario -- parametro a mais.
     * Quem decide e o formato declarado pelo template, nao o operador.
     */
    public ?int $imagem_id = null;

    /**
     * As chaves do `template_mapa` como estavam gravadas, quando ele é NOMEADO.
     *
     * 🔴 EXISTE PARA NÃO CONVERTER MAPA NOMEADO EM POSICIONAL SEM QUERER.
     *
     * `salvar()` monta a chave a partir dos nomes do template. Se a lista de
     * templates não carregar — Meta fora do ar, token vencido —, esses nomes vêm
     * vazios e a chave cairia na POSIÇÃO: um `{"nome":"cliente"}` viraria
     * `{"1":"cliente"}` só por o operador ter salvo o horário de trabalho. O worker
     * então leria como posicional e mandaria sem `parameter_name`, e a Meta recusaria
     * a Campanha inteira.
     *
     * Guardando o que já estava lá, salvar sem a lista preserva o formato.
     *
     * @var list<string>
     */
    public array $nomes_do_mapa = [];

    public function mount(?int $id = null): void
    {
        // Antes do desvio de Campanha nova: a lista serve aos dois casos, e sair
        // cedo aqui era o que deixava o formulário de criação sem ela.
        $this->carregarTemplates();

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
        $this->audio_confirma_captura_id = self::inteiroOuNulo($c['audio_confirma_captura_id'] ?? null);
        $this->audio_nao_entendi_id = self::inteiroOuNulo($c['audio_nao_entendi_id'] ?? null);
        $this->canal_opt_in = self::texto($c['canal_opt_in'] ?? null) ?: 'whatsapp';
        $this->texto_sms = self::texto($c['texto_sms'] ?? null);
        $this->template_nome = self::texto($c['template_nome'] ?? null);
        $this->template_idioma = self::texto($c['template_idioma'] ?? null) ?: 'pt_BR';

        // Pré-seleciona na lista SÓ se o par salvo ainda estiver aprovado. Quando não
        // estiver — template recusado, pausado ou apagado depois de a Campanha ser
        // criada — `template_escolhido` fica vazio e a tela cai no campo manual com o
        // valor antigo visível. É melhor que selecionar em silêncio uma opção que não
        // existe mais, ou pior, apagar o que o operador tinha gravado.
        $this->template_escolhido = $this->chaveSeAprovado($this->template_nome, $this->template_idioma);

        // Campanha que já aponta para um template fora da lista abre no modo manual, com
        // o valor gravado à vista. O contrário — esconder — deixaria a tela parecendo
        // "sem template" para uma Campanha que tem um, e salvar recriaria o problema.
        if ($this->template_escolhido === '' && $this->template_nome !== '') {
            $this->template_manual = true;
        }

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
            /*
             * 🔴 A ORDEM VEM DO TEMPLATE quando ele é nomeado.
             *
             * Mapa posicional se reordena sozinho pela chave numérica. Nomeado não
             * tem ordem própria — `{"cpf":…,"nome":…}` no JSON não diz qual vem
             * primeiro —, e a ordem que importa é a do template. Ordenar nomeado
             * alfabeticamente trocaria os valores de lugar em silêncio.
             */
            $texto = [];
            foreach ($mapa as $chave => $coluna) {
                if (is_string($coluna)) {
                    $texto[(string) $chave] = $coluna;
                }
            }

            /*
             * 🔴 QUEM DECIDE É A CHAVE DO MAPA, não a lista de templates.
             *
             * Ordenar mapa NOMEADO por `(int)` colapsa tudo: `(int)'nome'` e
             * `(int)'cpf'` são ZERO, as colunas colidem e o operador reabre a
             * Campanha com o mapeamento destruído. E a lista de templates pode não
             * estar carregada — a Meta pode estar fora do ar, ou o token vencido —,
             * então ela não serve para decidir isso.
             *
             * Mapa nomeado preserva a ORDEM DO JSON aqui, e a ordem do template é
             * aplicada logo abaixo, quando ela existir. Sem a lista, a ordem do JSON
             * é a melhor informação disponível e nada se perde.
             */
            if ((new Placeholder())->mapaEhNomeado($texto)) {
                /*
                 * 🔴 A ORDEM AQUI É A DO MySQL, não a que foi gravada. A coluna é do
                 * tipo `json` e ele NORMALIZA as chaves do objeto — por tamanho e
                 * depois alfabeticamente —, então `{"documento":…,"nome":…}` volta
                 * como `{"nome":…,"documento":…}`. Medido.
                 *
                 * Não é problema: quem manda na ordem é o template, aplicado logo
                 * abaixo. Isto só garante que nenhuma coluna se perca ou colida
                 * enquanto a lista de templates não estiver disponível.
                 */
                $this->parametros = array_values($texto);
                $this->nomes_do_mapa = array_map('strval', array_keys($texto));

                $nomesDoTemplate = $this->getNomesDosParametrosProperty();
                if ($nomesDoTemplate !== [] && $this->getTemplateNomeadoProperty()) {
                    $this->parametros = [];
                    foreach ($nomesDoTemplate as $n) {
                        $this->parametros[] = $texto[$n] ?? '';
                    }
                }
            } else {
                $porPosicao = [];
                foreach ($texto as $pos => $coluna) {
                    $porPosicao[(int) $pos] = $coluna;
                }
                ksort($porPosicao);
                $this->parametros = array_values($porPosicao);
            }
        }

        $this->imagem_id = self::inteiro($c['imagem_id'] ?? null) ?: null;
    }

    /**
     * O template escolhido exige imagem no cabecalho?
     *
     * Lido do que a Meta declara, nao do que o operador acha. E esta pergunta que
     * decide se a tela cobra a imagem e se o envio manda o componente `header`.
     */
    /**
     * Os nomes dos parâmetros do template escolhido.
     *
     * Posicional devolve `['1','2']`; nomeado devolve `['nome','cpf']`. É esta lista
     * que vira a CHAVE do `template_mapa`, e é a chave que depois diz ao worker qual
     * formato usar — sem coluna nova no esquema.
     *
     * @return list<string>
     */
    public function getNomesDosParametrosProperty(): array
    {
        $t = $this->getTemplateAtualProperty();

        return is_array($t['nomes'] ?? null) ? $t['nomes'] : [];
    }

    /** O template escolhido usa `{{nome}}` em vez de `{{1}}`? */
    public function getTemplateNomeadoProperty(): bool
    {
        foreach ($this->getNomesDosParametrosProperty() as $n) {
            if (preg_match('/^\d+$/', $n) !== 1) {
                return true;
            }
        }

        return false;
    }

    public function getExigeImagemProperty(): bool
    {
        return ($this->getTemplateAtualProperty()['cabecalho'] ?? '') === 'IMAGE';
    }

    public function render(): View
    {
        return view('livewire.campanha-form', [
            'audios' => $this->audios(),
            'imagens' => $this->imagens(),
            'colunasDosLeads' => $this->colunasDosLeads(),
        ]);
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

        /*
         * 🔴 A CHAVE DO MAPA É O NOME DO PARÂMETRO, e para template posicional o
         * "nome" é a própria posição. Assim `{"1":"nome"}` continua saindo igual para
         * as Campanhas que já existem, e `{"nome":"cliente"}` sai para as nomeadas —
         * e o worker distingue as duas só de olhar a chave.
         */
        $nomes = $this->getNomesDosParametrosProperty();

        // Sem a lista de templates, cai no que JÁ estava gravado antes de cair na
        // posição — senão salvar a Campanha converteria um mapa nomeado em posicional.
        if ($nomes === [] || ! $this->getTemplateNomeadoProperty()) {
            $nomes = $this->nomes_do_mapa !== [] ? $this->nomes_do_mapa : $nomes;
        }

        $mapa = [];
        foreach ($this->parametros as $i => $coluna) {
            $coluna = trim($coluna);
            if ($coluna !== '') {
                $mapa[$nomes[$i] ?? (string) ($i + 1)] = $coluna;
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
            'audio_confirma_captura_id' => $this->audio_confirma_captura_id,
            'audio_nao_entendi_id' => $this->audio_nao_entendi_id,
            'canal_opt_in' => $this->canal_opt_in,
            'texto_sms' => trim($this->texto_sms) === '' ? null : trim($this->texto_sms),
            'template_nome' => trim($this->template_nome) === '' ? null : trim($this->template_nome),
            'template_idioma' => $this->template_idioma,
            'template_mapa' => $mapa === [] ? null : json_encode($mapa, JSON_UNESCAPED_UNICODE),
            // Nulo quando o template nao pede imagem: cabecalho a mais e recusa igual.
            'imagem_id' => $this->getExigeImagemProperty() ? $this->imagem_id : null,
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

    /** @return array<string, list<string|Closure>> */
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
            // NULLABLE de propósito: é cortesia. Campanha sem ele funciona igual, e o
            // `Fluxo::tocarCortesia` já trata ausência sem tocar no desfecho.
            'audio_confirma_captura_id' => ['nullable', 'integer', 'exists:audio,id'],
            'audio_nao_entendi_id' => ['nullable', 'integer', 'exists:audio,id'],

            'canal_opt_in' => ['required', 'in:whatsapp,sms'],

            /*
             * Obrigatório SÓ no canal SMS, e pelo mesmo motivo que o template é
             * obrigatório só no WhatsApp: exigir sempre recusaria Campanha bem
             * configurada. Sem ele o Disparo nasce condenado — o worker falha com
             * `campanha_sem_texto_sms_ou_lead_sem_telefone`, um Lead por vez.
             *
             * 1600 é o teto prático de SMS concatenado; acima disso o custo explode
             * sem o operador perceber.
             */
            'texto_sms' => $this->tipo === 'opt_in' && $this->canal_opt_in === 'sms'
                ? ['required', 'string', 'max:1600']
                : ['nullable', 'string', 'max:1600'],

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

            /*
             * A QUANTIDADE TEM QUE BATER COM O QUE O TEMPLATE PEDE.
             *
             * Sem esta regra a tela deixava salvar Campanha IMPOSSIVEL: medido em
             * 25/08/2026, a Campanha 3 tinha `template_mapa` NULL para um template
             * cujo corpo e "Ola, {{1}}!", e todo disparo morria com `(#132000)
             * Number of parameters does not match the expected number of params`.
             * O operador so descobria na primeira mensagem.
             *
             * A contagem esperada JA ESTAVA carregada na lista de templates
             * (`parametros:int`), so nao era comparada com nada. Recusar aqui e o
             * mesmo principio de `Conversas::responder`: nao deixar nascer o que se
             * sabe que vai falhar.
             *
             * Template digitado a mao (`template_manual`) nao entra na conferencia:
             * ele nao esta na lista da Meta, entao nao ha contagem para comparar, e
             * inventar uma recusaria configuracao legitima.
             */
            'parametros' => ['array', 'max:10', function (string $campo, mixed $valor, Closure $falha): void {
                $esperado = $this->getTemplateAtualProperty()['parametros'] ?? null;
                if (! is_int($esperado)) {
                    return;
                }

                $tem = is_array($valor) ? count($valor) : 0;
                if ($tem === $esperado) {
                    return;
                }

                $falha($esperado === 0
                    ? 'Este template nao usa parametro nenhum, e ha ' . $tem . ' mapeado(s). Remova.'
                    : sprintf(
                        'Este template usa %d parametro(s) e ha %d mapeado(s). A Meta recusa o '
                        . 'envio inteiro quando a conta nao bate.',
                        $esperado,
                        $tem,
                    ));
            }],
            'parametros.*' => ['required', 'string', 'max:255'],

            /*
             * Imagem obrigatoria quando o template declara `HEADER format=IMAGE`.
             *
             * Mesma familia do defeito acima e o mesmo erro da Meta: faltando o
             * componente de cabecalho, ela recusa com 132000 falando de
             * "parameters", sem mencionar imagem -- o que manda quem investiga para
             * o lado errado. Ver `Worker\Whatsapp::enviarTemplate`.
             */
            'imagem_id' => $this->getExigeImagemProperty()
                ? ['required', 'integer', 'exists:imagem,id']
                : ['nullable', 'integer'],
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
            'texto_sms.required' => 'No SMS o texto é obrigatório: sem ele o Disparo nasce condenado.',
            'parametros.*.required' => 'Parâmetro em branco — remova a linha ou preencha o nome da coluna.',
            'imagem_id.required' => 'Este template tem cabecalho de imagem: escolha uma da galeria, senao a Meta recusa o disparo inteiro.',
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

    /**
     * Busca os templates aprovados da conta e guarda em `$templates`.
     *
     * Não lança: qualquer falha vira `$templates_erro` e a tela cai no campo manual.
     * Uma Campanha não pode ficar impossível de criar porque a Graph API está fora do
     * ar — é a mesma disciplina do `Descoberta`.
     */
    private function carregarTemplates(): void
    {
        $cred = $this->credenciaisWhatsapp();

        if ($cred === null) {
            $this->templates_erro = 'Nenhuma integração de WhatsApp ativa. '
                . 'Cadastre-a em Integrações para escolher o template de uma lista.';

            return;
        }

        try {
            $r = (new TemplatesAprovados(new TransporteCurl(15)))
                ->listar($cred['token'], $cred['waba_id']);
        } catch (Throwable $e) {
            // O operador não precisa da exceção, precisa de conseguir criar a Campanha.
            Log::warning('falha ao listar templates do WhatsApp', ['erro' => $e->getMessage()]);
            $this->templates_erro = 'Não consegui consultar os templates agora. '
                . 'Preencha nome e idioma à mão.';

            return;
        }

        $this->templates = $r['templates'];
        $this->templates_erro = $r['erro'];
    }

    /**
     * Credenciais do canal de WhatsApp, ou nulo quando não há canal utilizável.
     *
     * Lê pela mesma junção que o worker usa (`FilaDeAcoes::credenciaisWhatsapp`), para
     * a tela não oferecer template de uma conta da qual o Disparo não conseguiria enviar.
     *
     * @return array{token:string, waba_id:string}|null
     */
    private function credenciaisWhatsapp(): ?array
    {
        $coluna = DB::table('configuracao as cfg')
            ->join('integracao as i', 'i.id', '=', 'cfg.integracao_whatsapp_id')
            ->where('cfg.id', 1)
            ->where('i.ativo', 1)
            ->value('i.credenciais');

        if (! is_string($coluna)) {
            return null;
        }

        try {
            $chave = config('app.key');
            $cred = (new Cofre(is_string($chave) ? $chave : ''))->decifrarDaColuna($coluna);
        } catch (Throwable $e) {
            Log::warning('credencial do WhatsApp ilegível no formulário de Campanha', ['erro' => $e->getMessage()]);

            return null;
        }

        $token = self::texto($cred['token'] ?? null);

        return $token === ''
            ? null
            : ['token' => $token, 'waba_id' => self::texto($cred['waba_id'] ?? null)];
    }

    /**
     * A chave `nome|idioma` quando esse par está entre os aprovados; string vazia se não.
     */
    private function chaveSeAprovado(string $nome, string $idioma): string
    {
        foreach ($this->templates as $t) {
            if ($t['nome'] === $nome && $t['idioma'] === $idioma) {
                return $t['chave'];
            }
        }

        return '';
    }

    /**
     * Escolher na lista preenche nome E idioma. É o ponto da mudança: o par vem colado,
     * então não há como salvar um nome certo com o idioma errado.
     *
     * O gancho do Livewire dispara com `wire:model.live` no select.
     */
    public function updatedTemplateEscolhido(string $valor): void
    {
        if (trim($valor) === '') {
            return;   // "digitar à mão": preserva o que já estiver nos campos
        }

        foreach ($this->templates as $t) {
            if ($t['chave'] === $valor) {
                $this->template_nome = $t['nome'];
                $this->template_idioma = $t['idioma'];
                $this->ajustarLinhasDeParametro($t['nomes']);

                return;
            }
        }
    }

    /**
     * Template NOMEADO tem as linhas ditadas por ele; posicional continua manual.
     *
     * 🔴 SEM ISTO O MODO NOMEADO FICA SEM SAÍDA. As linhas de parâmetro nascem do
     * botão "+ parâmetro", e no modo nomeado esse botão não existe — a quantidade e
     * os nomes vêm do template, não da escolha do operador. Sem criar as linhas aqui,
     * a tela mostraria "informe a coluna de cada um" e nenhum campo para informar.
     *
     * As colunas já preenchidas são PRESERVADAS por posição: trocar de template
     * nomeado com a mesma aridade não faz o operador redigitar tudo.
     *
     * @param list<string> $nomes
     */
    private function ajustarLinhasDeParametro(array $nomes): void
    {
        $nomeado = false;
        foreach ($nomes as $n) {
            if (preg_match('/^\d+$/', $n) !== 1) {
                $nomeado = true;
                break;
            }
        }

        if (! $nomeado) {
            return;
        }

        $novo = [];
        foreach (array_keys($nomes) as $i) {
            $novo[] = $this->parametros[$i] ?? '';
        }

        $this->parametros = $novo;
    }

    /**
     * Alterna para digitação à mão, largando a escolha da lista.
     *
     * Limpa `template_escolhido` porque os dois modos não podem valer ao mesmo tempo:
     * um select preenchido junto de campos editados seria dois valores para o mesmo
     * fato, e o próximo `updatedTemplateEscolhido` sobrescreveria o que foi digitado.
     */
    public function preencherAMao(): void
    {
        $this->template_manual = true;
        $this->template_escolhido = '';
    }

    /**
     * Volta a escolher da lista, descartando o que estiver digitado.
     */
    public function escolherDaLista(): void
    {
        $this->template_manual = false;
    }

    /**
     * O template escolhido agora, para a tela mostrar corpo e nº de parâmetros.
     *
     * @return array{chave:string, nome:string, idioma:string, parametros:int, nomes:list<string>, cabecalho:string, corpo:string, rotulo:string}|null
     */
    public function getTemplateAtualProperty(): ?array
    {
        foreach ($this->templates as $t) {
            if ($t['chave'] === $this->template_escolhido) {
                return $t;
            }
        }

        return null;
    }

    /**
     * As colunas que os Leads desta Campanha realmente têm.
     *
     * 🔴 EXISTE PORQUE O CAMPO DE PARÂMETRO ERA UM TEXTO EM BRANCO. O operador tinha
     * que ADIVINHAR o nome da coluna para mapear `{{1}}`, e errar não dá erro na
     * tela: dá `template_mapa_invalido` no worker, uma Ligação por vez, depois que a
     * Campanha já está no ar.
     *
     * A fonte é `leads.dados_extras`, e não `campanha.colunas` — essa coluna existe
     * no esquema e NINGUÉM escreve nela; estava nula em todas as Campanhas em
     * 25/08/2026. Ler dali seria oferecer uma lista sempre vazia.
     *
     * 🔴 E DENUNCIA CONJUNTO DIVERGENTE. Quando a mesma Campanha tem Leads com
     * conjuntos de colunas diferentes, é sinal de importação sem cabeçalho: o CSV
     * entrou e a primeira linha de dados virou o nome das colunas. Medido em
     * 25/08/2026 — a Campanha 3 tinha 29.554 Leads com `["cpf","nome"]` e 9.997 com
     * `["MILTON NATAL","247.942.998-93"]`, que são o nome e o CPF de uma pessoa. Um
     * mapeamento que funcionasse para uns falharia para os outros, calado.
     *
     * @return array{lista: list<string>, conjuntos: int, sem_leads: bool}
     */
    private function colunasDosLeads(): array
    {
        if ($this->campanhaId === null) {
            return ['lista' => [], 'conjuntos' => 0, 'sem_leads' => true];
        }

        // Agrupado por conjunto de chaves: é o agrupamento que revela a divergência.
        // `LIMIT 10` porque mais que isso já é caso patológico e a tela não melhora
        // listando tudo.
        $linhas = DB::select(
            'SELECT JSON_KEYS(dados_extras) AS chaves, COUNT(*) AS n
               FROM leads
              WHERE campanha_id = ? AND dados_extras IS NOT NULL
              GROUP BY chaves ORDER BY n DESC LIMIT 10',
            [$this->campanhaId],
        );

        $lista = [];
        foreach ($linhas as $linha) {
            $l = (array) $linha;
            $chaves = json_decode(self::texto($l['chaves'] ?? null), true);
            if (! is_array($chaves)) {
                continue;
            }
            foreach ($chaves as $c) {
                if (is_string($c) && ! in_array($c, $lista, true)) {
                    $lista[] = $c;
                }
            }
        }

        sort($lista);

        return [
            'lista' => $lista,
            'conjuntos' => count($linhas),
            'sem_leads' => $linhas === [],
        ];
    }

    /**
     * A galeria, para o seletor de cabecalho.
     *
     * @return list<array{id:int, nome:string}>
     */
    private function imagens(): array
    {
        $saida = [];
        foreach (DB::select('SELECT id, nome FROM imagem ORDER BY nome') as $linha) {
            $i = (array) $linha;
            $saida[] = [
                'id' => self::inteiro($i['id'] ?? null),
                'nome' => self::texto($i['nome'] ?? null),
            ];
        }

        return $saida;
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

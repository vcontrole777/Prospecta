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
 * Só `tipo = opt_in`. Campanha de captura precisa do construtor de campos
 * (`campanha_campo`), que é tela própria — e oferecer o tipo sem ele criaria
 * Campanha que liga, coleta nada e encerra.
 */
final class CampanhaForm extends Component
{
    public ?int $campanhaId = null;

    public string $nome = '';

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
            'tipo' => 'opt_in',
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

        session()->flash('recado', 'Campanha salva.');
        $this->redirectRoute('campanhas');
    }

    /** @return array<string, list<string>> */
    private function regras(): array
    {
        return [
            'nome' => ['required', 'string', 'max:255'],

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

            // Obrigatório no WhatsApp porque o worker recusa a Ação sem ele, com
            // motivo `campanha_sem_template_ou_lead_sem_telefone`. Exigir aqui troca
            // uma falha silenciosa no envio por um campo vermelho no cadastro.
            'template_nome' => ['nullable', 'required_if:canal_opt_in,whatsapp', 'string', 'max:255'],
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
            'template_nome.required_if' => 'No WhatsApp o nome do template é obrigatório: sem ele o envio falha.',
            'parametros.*.required' => 'Parâmetro em branco — remova a linha ou preencha o nome da coluna.',
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

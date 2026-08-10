<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Seguranca\Cofre;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Livewire\Component;
use RuntimeException;

/**
 * Integrações: credenciais de fornecedor, e qual está em vigor por tipo.
 *
 * 🔴 A Campanha aponta para a INTEGRAÇÃO, nunca para a chave. Sem isso, trocar de
 * fornecedor é editar N Campanhas — e o painel guarda a chave em N lugares, cada um
 * um lugar a mais para vazar e para divergir.
 *
 * 🔴 SEGREDO EM BRANCO NA EDIÇÃO MANTÉM O ATUAL. O formulário nunca recebe o valor
 * decifrado, então salvar com o campo vazio significaria apagar o token ao corrigir
 * um nome. É a forma que impede a edição de destruir o que ela não tocou.
 */
final class Integracoes extends Component
{
    public ?int $editando = null;

    public string $tipo = 'whatsapp';

    public string $provedor = 'meta';

    public string $nome = '';

    public string $voz = '';

    /** @var array<string,string> */
    public array $credenciais = [];

    /**
     * Campos de credencial por tipo e provedor, com o rótulo e se é segredo.
     *
     * `segredo` decide duas coisas: o campo aparece mascarado na lista, e em branco
     * na edição ele preserva o valor guardado.
     *
     * @var array<string, array<string, array<string, array{rotulo:string, segredo:bool, dica?:string, padrao?:string}>>>
     */
    public const CAMPOS = [
        'whatsapp' => [
            'meta' => [
                'token' => [
                    'rotulo' => 'Token de acesso',
                    'segredo' => true,
                    'dica' => 'O que a documentação chama de permanente é, no texto oficial, "de longa duração" — ele expira e devolve erro 190.',
                ],
                'phone_number_id' => [
                    'rotulo' => 'Phone Number ID',
                    'segredo' => false,
                    'dica' => 'É o ID do número, não o número. A permissão de ligar do WhatsApp fica NESTE número.',
                ],
                'versao_api' => ['rotulo' => 'Versão da API', 'segredo' => false, 'padrao' => 'v21.0'],
            ],
        ],
        'tts' => [
            'elevenlabs' => [
                'api_key' => ['rotulo' => 'Chave de API', 'segredo' => true],
                'model_id' => [
                    'rotulo' => 'Modelo',
                    'segredo' => false,
                    'padrao' => 'eleven_flash_v2_5',
                    'dica' => '~75 ms, suporta pt-BR e custa metade por caractere. Os modelos turbo estão depreciados.',
                ],
                'output_format' => [
                    'rotulo' => 'Formato de saída',
                    'segredo' => false,
                    'padrao' => 'alaw_8000',
                    'dica' => 'alaw_8000 é exatamente o que o Asterisk toca — pedindo assim, o áudio não precisa de conversão nenhuma.',
                ],
            ],
        ],
        'sms' => [
            'generico' => [
                'endpoint' => ['rotulo' => 'Endpoint', 'segredo' => false],
                'token' => ['rotulo' => 'Token', 'segredo' => true],
            ],
        ],
    ];

    /** Tipos que ALGUM processo do produto de fato consome hoje. */
    private const CONSUMIDOS = ['whatsapp'];

    public function mount(): void
    {
        $this->preencherPadroes();
    }

    public function render(): View
    {
        return view('livewire.integracoes', [
            'integracoes' => $this->lista(),
            'campos' => $this->camposDoProvedor(),
            'emVigor' => $this->emVigor(),
            'consumidos' => self::CONSUMIDOS,
        ]);
    }

    public function updatedTipo(): void
    {
        // Trocar o tipo troca o conjunto de provedores, e manter o provedor antigo
        // deixaria o formulário pedindo campos que não existem para o novo tipo.
        $this->provedor = array_key_first(self::CAMPOS[$this->tipo] ?? []) ?? '';
        $this->credenciais = [];
        $this->preencherPadroes();
    }

    public function updatedProvedor(): void
    {
        $this->credenciais = [];
        $this->preencherPadroes();
    }

    public function editar(int $id): void
    {
        $linha = DB::table('integracao')->where('id', $id)->first();
        if ($linha === null) {
            return;
        }
        $i = (array) $linha;

        $this->editando = $id;
        $this->tipo = self::texto($i['tipo'] ?? null);
        $this->provedor = self::texto($i['provedor'] ?? null);
        $this->nome = self::texto($i['nome'] ?? null);
        $this->voz = self::texto($i['voz'] ?? null);

        // 🔴 Só os campos NÃO secretos são carregados. O segredo nunca sai do banco
        // para a tela: uma vez no HTML ele está no histórico do navegador, no cache
        // do proxy e na captura de tela de quem pedir suporte.
        $guardado = $this->credenciaisGuardadas($id);
        $this->credenciais = [];
        foreach ($this->camposDoProvedor() as $chave => $def) {
            $this->credenciais[$chave] = $def['segredo'] ? '' : self::texto($guardado[$chave] ?? null);
        }
    }

    public function cancelar(): void
    {
        $this->reset(['editando', 'nome', 'voz', 'credenciais']);
        $this->preencherPadroes();
    }

    public function salvar(): void
    {
        $campos = $this->camposDoProvedor();

        $regras = ['nome' => ['required', 'string', 'max:255']];
        $rotulos = ['nome' => 'nome'];

        foreach ($campos as $chave => $def) {
            // Segredo é opcional na EDIÇÃO — em branco significa "mantenha" — e
            // obrigatório na criação, onde não há o que manter.
            $obrigatorio = $def['segredo'] && $this->editando !== null ? 'nullable' : 'required';
            $regras["credenciais.{$chave}"] = [$obrigatorio, 'string', 'max:2048'];
            $rotulos["credenciais.{$chave}"] = mb_strtolower($def['rotulo']);
        }

        $this->validate($regras, [], $rotulos);

        $guardado = $this->editando !== null ? $this->credenciaisGuardadas($this->editando) : [];

        $novas = [];
        foreach ($campos as $chave => $def) {
            $digitado = trim($this->credenciais[$chave] ?? '');

            $novas[$chave] = $digitado === '' && $def['segredo']
                ? self::texto($guardado[$chave] ?? null)   // mantém o atual
                : $digitado;
        }

        $valores = [
            'tipo' => $this->tipo,
            'provedor' => $this->provedor,
            'nome' => trim($this->nome),
            'voz' => trim($this->voz) === '' ? null : trim($this->voz),
            'credenciais' => $this->cofre()->cifrarParaColuna($novas),
        ];

        if ($this->editando === null) {
            $valores['ativo'] = 1;
            DB::table('integracao')->insert($valores);
        } else {
            DB::table('integracao')->where('id', $this->editando)->update($valores);
        }

        $this->cancelar();
        session()->flash('recado', 'Integração salva.');
    }

    /**
     * Põe esta integração em vigor para o tipo dela.
     *
     * A escolha é GLOBAL, em `configuracao`, e não por Campanha. Para o WhatsApp
     * isso é deliberado: o número é IDENTIDADE — é o nome verificado que o Lead vê —
     * enquanto a BINA é tática e muda por Campanha.
     */
    public function usar(int $id): void
    {
        $tipo = DB::table('integracao')->where('id', $id)->value('tipo');
        if (! is_string($tipo)) {
            return;
        }

        $coluna = "integracao_{$tipo}_id";
        if (! in_array($coluna, ['integracao_whatsapp_id', 'integracao_tts_id',
            'integracao_sms_id', 'integracao_automacao_id'], true)) {
            return;
        }

        // A linha única de `configuracao` é semeada por migration; `insertOrIgnore`
        // aqui é cinto de segurança para o caso de alguém a ter apagado.
        DB::table('configuracao')->insertOrIgnore(['id' => 1]);
        DB::table('configuracao')->where('id', 1)->update([$coluna => $id]);

        session()->flash('recado', 'Integração em vigor.');
    }

    public function desativar(int $id): void
    {
        DB::table('integracao')->where('id', $id)->update(['ativo' => 0]);
        session()->flash('recado', 'Integração desativada. Ela deixa de ser usada mesmo se estiver selecionada.');
    }

    public function reativar(int $id): void
    {
        DB::table('integracao')->where('id', $id)->update(['ativo' => 1]);
        session()->flash('recado', 'Integração reativada.');
    }

    /** @return array<string, array{rotulo:string, segredo:bool, dica?:string, padrao?:string}> */
    public function camposDoProvedor(): array
    {
        return self::CAMPOS[$this->tipo][$this->provedor] ?? [];
    }

    /** @return list<string> */
    public function provedoresDoTipo(): array
    {
        return array_keys(self::CAMPOS[$this->tipo] ?? []);
    }

    private function preencherPadroes(): void
    {
        foreach ($this->camposDoProvedor() as $chave => $def) {
            if (($this->credenciais[$chave] ?? '') === '') {
                $this->credenciais[$chave] = $def['padrao'] ?? '';
            }
        }
    }

    /** @return array<string,mixed> */
    private function credenciaisGuardadas(int $id): array
    {
        $coluna = DB::table('integracao')->where('id', $id)->value('credenciais');
        if (! is_string($coluna)) {
            return [];
        }

        try {
            return $this->cofre()->decifrarDaColuna($coluna);
        } catch (RuntimeException $e) {
            // Credencial ilegível não é motivo para a tela quebrar: o operador precisa
            // conseguir abrir a integração e recadastrar. A causa aparece nomeada.
            $this->addError('credenciais', $e->getMessage());

            return [];
        }
    }

    /** @return array<string,int|null> */
    private function emVigor(): array
    {
        $linha = DB::table('configuracao')->where('id', 1)->first();
        $c = $linha === null ? [] : (array) $linha;

        $saida = [];
        foreach (['whatsapp', 'tts', 'sms', 'automacao'] as $tipo) {
            $v = $c["integracao_{$tipo}_id"] ?? null;
            $saida[$tipo] = is_numeric($v) ? (int) $v : null;
        }

        return $saida;
    }

    /** @return list<array<string,mixed>> */
    private function lista(): array
    {
        $cofre = $this->cofre();
        $saida = [];

        foreach (DB::table('integracao')->orderBy('tipo')->orderBy('nome')->get() as $linha) {
            $i = (array) $linha;
            $id = self::inteiro($i['id'] ?? null);
            $tipo = self::texto($i['tipo'] ?? null);
            $provedor = self::texto($i['provedor'] ?? null);

            // 🔴 O RESUMO É MASCARADO, sempre. Segredo decifrado que chega ao HTML
            // fica no histórico do navegador e na captura de tela de quem pede
            // suporte. O operador precisa RECONHECER a chave, não lê-la.
            $resumo = [];
            $ilegivel = false;

            try {
                $cred = $cofre->decifrarDaColuna(self::texto($i['credenciais'] ?? null));
                foreach (self::CAMPOS[$tipo][$provedor] ?? [] as $chave => $def) {
                    $valor = self::texto($cred[$chave] ?? null);
                    $resumo[$def['rotulo']] = $def['segredo'] ? $cofre->mascarar($valor) : $valor;
                }
            } catch (RuntimeException) {
                $ilegivel = true;
            }

            $saida[] = [
                'id' => $id,
                'tipo' => $tipo,
                'provedor' => $provedor,
                'nome' => self::texto($i['nome'] ?? null),
                'voz' => self::texto($i['voz'] ?? null),
                'ativo' => (bool) ($i['ativo'] ?? false),
                'resumo' => $resumo,
                'ilegivel' => $ilegivel,
            ];
        }

        return $saida;
    }

    private function cofre(): Cofre
    {
        return new Cofre(self::texto(config('app.key')));
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

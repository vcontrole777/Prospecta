<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Seguranca\Cofre;
use App\Whatsapp\Descoberta;
use App\Worker\Transporte;
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
     * O número escolhido, quando a descoberta acha mais de um.
     *
     * Vazio faz o `salvar` descobrir. É por isso que a tela não pede: o caminho normal é
     * pastar o token, salvar, e o painel resolver.
     */
    public string $phone_number_id = '';

    /**
     * Números que o token alcança, quando há mais de um.
     *
     * @var list<array{id:string, numero:string, nome:string}>
     */
    public array $numerosEncontrados = [];

    /** O motivo que a Meta deu, quando a descoberta falhou. */
    public string $erroDescoberta = '';

    /**
     * Revela o campo manual do Phone Number ID.
     *
     * 🔴 AUTOMATIZAR NÃO PODE VIRAR BECO SEM SAÍDA. Token com escopo estreito, conta em
     * revisão e falha de rede são casos reais; sem esta saída, o operador ficaria preso numa
     * tela que insiste em descobrir e não consegue.
     */
    public bool $numeroManual = false;

    /**
     * Versão da API, fixa.
     *
     * Era campo de formulário com padrão `v21.0`. Perguntar a versão de uma API a quem só
     * quer mandar mensagem é perguntar o que ele não tem como decidir — e a resposta errada
     * quebra de um jeito que não menciona versão.
     */
    private const VERSAO_API = 'v21.0';

    /**
     * Campos de credencial por tipo e provedor, com o rótulo e se é segredo.
     *
     * `segredo` decide duas coisas: o campo aparece mascarado na lista, e em branco
     * na edição ele preserva o valor guardado.
     *
     * @var array<string, array<string, array<string, array{rotulo:string, segredo:bool, dica?:string, padrao?:string}>>>
     */
    public const CAMPOS = [
        /*
         * 🔴 SÓ DOIS CAMPOS PARA O WHATSAPP, e os outros três saíram por motivos diferentes.
         *
         * · `phone_number_id` é DESCOBERTO pelo token — ver `Whatsapp\Descoberta`. Era um
         *   número de 15 dígitos copiado de outro painel: erra sem avisar, e o erro aparece
         *   só na primeira mensagem que não sai.
         * · `verify_token` é GERADO. O operador o escolhia e repetia na Meta; não há nada a
         *   escolher, então o painel gera e mostra para copiar.
         * · `versao_api` tem padrão e quase nunca muda: virou constante.
         *
         * Sobram os dois que são segredo do painel da Meta e não podem ser derivados de nada.
         */
        'whatsapp' => [
            'meta' => [
                'token' => [
                    'rotulo' => 'Token de acesso',
                    'segredo' => true,
                    'dica' => 'O que a documentação chama de permanente é, no texto oficial, "de longa duração" — ele expira e devolve erro 190.',
                ],
                'app_secret' => [
                    'rotulo' => 'App Secret',
                    'segredo' => true,
                    'dica' => 'Assina o webhook. Sem ele o endpoint recusa TODO evento — é a única proteção de uma rota que precisa ser pública.',
                ],
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
        /*
         * 🔴 `generico` SAIU, e não é remoção de recurso: ele nunca funcionou.
         *
         * Era `endpoint` + `token`, sem remetente e sem formato de corpo definido —
         * nenhum código jamais o consumiu, e não havia como consumir: dois campos não
         * descrevem uma API. Era campo em tela que nada lê, exatamente o que o
         * docblock desta classe diz que não se oferece.
         *
         * `httpsms` no lugar tem semântica: os dois campos abaixo são o que a API
         * dele exige, e `Worker\Sms` os consome.
         */
        'sms' => [
            'httpsms' => [
                'api_key' => [
                    'rotulo' => 'Chave de API',
                    'segredo' => true,
                    'dica' => 'Do painel em httpsms.com/settings. Vai no cabeçalho `x-api-key`.',
                ],
                'remetente' => [
                    'rotulo' => 'Número do aparelho',
                    'segredo' => false,
                    'dica' => 'O chip do celular Android que roda o app do httpSMS — é ele que manda o SMS. Com país e DDD: 5511987654321.',
                ],
                'signing_key' => [
                    'rotulo' => 'Chave de assinatura do webhook',
                    'segredo' => true,
                    'dica' => 'A MESMA que você põe ao criar o webhook no painel do httpSMS. Sem ela o endpoint recusa TODO evento — é a única proteção de uma rota pública. Opcional se você não usar webhook.',
                ],
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
            'webhook' => $this->webhook(),
            'webhookSms' => $this->webhookSms(),
        ]);
    }

    /**
     * O que o operador precisa COLAR no painel da Meta.
     *
     * 🔴 MOSTRADO PRONTO, porque é a metade do trabalho que não dá para automatizar: a Meta
     * exige que alguém cadastre a URL e o token no painel DELA. O que dá para automatizar é
     * não fazer o operador inventar nem montar nada — a URL é derivada e o token é gerado.
     *
     * O `verify_token` aparece EM CLARO aqui, ao contrário do token de acesso. É deliberado:
     * ele é um aperto de mão combinado entre nós e a Meta, não uma credencial que dê acesso a
     * nada — quem o souber consegue, no máximo, responder ao desafio de verificação. E ele
     * não serve para nada se não puder ser copiado.
     *
     * @return array{url:string, verify:string, https:bool}|null
     */
    private function webhook(): ?array
    {
        $emVigor = $this->emVigor();
        $id = is_numeric($emVigor['whatsapp'] ?? null) ? (int) $emVigor['whatsapp'] : 0;

        if ($id === 0) {
            return null;
        }

        $url = $this->urlDoWebhook();

        return [
            'url' => $url,
            'verify' => self::texto($this->credenciaisGuardadas($id)['verify_token'] ?? null),
            // A Meta RECUSA certificado autoassinado e não aceita HTTP. Numa instância sem
            // domínio isto nunca vai funcionar, e é melhor dizer aqui do que deixar o
            // operador tentando cadastrar e levando erro no painel dela.
            'https' => str_starts_with($url, 'https://'),
        ];
    }

    /**
     * O que o operador precisa COLAR no painel do httpSMS.
     *
     * 🔴 MESMA METADE NÃO-AUTOMATIZÁVEL do webhook da Meta, com uma diferença que muda o
     * que a tela precisa dizer: a Meta responde a um desafio de verificação e AVISA na hora
     * se a URL está errada. O httpSMS não. Ele aceita o cadastro, começa a mandar evento, e
     * se a chave não bater o nosso endpoint recusa TODO evento em silêncio — `Assinatura`
     * falha FECHADA de propósito, porque a rota é pública.
     *
     * O sintoma disso é cruel: o SMS SAI normalmente, e só o status nunca volta. A Ação fica
     * em `enviado` para sempre, e ninguém desconfia da chave. Por isso a tela avisa quando a
     * `signing_key` está vazia — é a única forma de o operador saber ANTES.
     *
     * A chave NÃO aparece em claro, ao contrário do `verify_token` da Meta: aquele é um
     * aperto de mão que não dá acesso a nada, esta é o que separa evento do httpSMS de
     * evento forjado por qualquer um.
     *
     * @return array{url:string, chave:bool, https:bool}|null
     */
    private function webhookSms(): ?array
    {
        $emVigor = $this->emVigor();
        $id = is_numeric($emVigor['sms'] ?? null) ? (int) $emVigor['sms'] : 0;

        if ($id === 0) {
            return null;
        }

        $url = url('/webhook/sms');

        return [
            'url' => $url,
            'chave' => self::texto($this->credenciaisGuardadas($id)['signing_key'] ?? null) !== '',
            // O httpSMS entrega por HTTPS; sem certificado válido o evento não chega e o
            // status nunca volta — mesmo sintoma da chave errada, causa diferente.
            'https' => str_starts_with($url, 'https://'),
        ];
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

        // 🔴 O NÚMERO EM USO VOLTA PARA A TELA. Ele não é segredo e é a única forma de o
        // operador saber por qual número esta integração manda — sem isso, editar o nome
        // pareceria seguro e ele não teria como conferir se o número é o que espera.
        $this->phone_number_id = self::texto($guardado['phone_number_id'] ?? null);
        $this->numerosEncontrados = [];
        $this->erroDescoberta = '';
        $this->numeroManual = false;

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

        // 🔴 OS DERIVADOS, antes de gravar. Nenhum deles é perguntado.
        if ($this->tipo === 'whatsapp' && $this->provedor === 'meta') {
            $novas['versao_api'] = self::VERSAO_API;

            // Gerado UMA vez e preservado: o operador já o copiou para o painel da Meta, e
            // trocá-lo a cada gravação faria o webhook parar de ser aceito sem ninguém
            // mexer nele.
            $novas['verify_token'] = self::texto($guardado['verify_token'] ?? null)
                ?: bin2hex(random_bytes(16));

            $numero = $this->resolverNumero($novas['token'], self::texto($guardado['phone_number_id'] ?? null));

            if ($numero === null) {
                // A descoberta não fechou: ou há vários números para escolher, ou ela falhou
                // e o campo manual apareceu. NÃO grava pela metade — integração sem
                // `phone_number_id` é recusada pelo worker com "sem integração", que não
                // aponta para cá.
                return;
            }

            $novas['phone_number_id'] = $numero;
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
    /**
     * O Phone Number ID a usar, ou `null` quando ainda falta uma escolha do operador.
     *
     * A ordem é deliberada: o que ele escolheu, depois o que já estava gravado, e só então a
     * Meta. Consultar a API quando já se sabe o número seria gastar rede e latência para
     * confirmar o que não mudou.
     */
    private function resolverNumero(string $token, string $guardado): ?string
    {
        if (trim($this->phone_number_id) !== '') {
            return trim($this->phone_number_id);
        }

        if ($guardado !== '') {
            return $guardado;
        }

        $r = $this->descoberta()->numeros($token);

        if (! $r['ok']) {
            $this->erroDescoberta = (string) $r['erro'];
            $this->numeroManual = true;

            return null;
        }

        if (count($r['numeros']) === 1) {
            return $r['numeros'][0]['id'];
        }

        // Mais de um: o operador escolhe de uma lista com o NÚMERO visível. É pergunta
        // melhor que digitar um id — ele reconhece o número, não reconhece o id.
        $this->numerosEncontrados = $r['numeros'];
        $this->erroDescoberta = '';

        return null;
    }

    /**
     * Consulta a Meta de novo, a pedido.
     *
     * Serve para quem consertou a permissão do token, ou trocou de token, sem precisar
     * apagar e recadastrar a integração.
     */
    public function descobrir(): void
    {
        $token = trim($this->credenciais['token'] ?? '');

        if ($token === '' && $this->editando !== null) {
            $token = self::texto($this->credenciaisGuardadas($this->editando)['token'] ?? null);
        }

        $r = $this->descoberta()->numeros($token);

        $this->numerosEncontrados = $r['ok'] ? $r['numeros'] : [];
        $this->erroDescoberta = $r['ok'] ? '' : (string) $r['erro'];
        $this->numeroManual = ! $r['ok'];

        if ($r['ok'] && count($r['numeros']) === 1) {
            $this->phone_number_id = $r['numeros'][0]['id'];
        }
    }

    private function descoberta(): Descoberta
    {
        // Do contêiner, não `new`: é o que deixa o teste trocar por um dublê em vez de sair
        // para a internet com o token de alguém.
        return new Descoberta(app(Transporte::class), self::VERSAO_API);
    }

    /**
     * A URL que o operador cola no painel da Meta.
     *
     * Mostrada pronta porque é derivável e ele não deveria montá-la: errar o caminho dá um
     * webhook que a Meta valida contra outra rota e nunca entrega evento.
     */
    public function urlDoWebhook(): string
    {
        return url('/webhook/whatsapp');
    }

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
                // 🔴 O NÚMERO, PRIMEIRO. Ele saiu de `CAMPOS` porque não é perguntado — é
                // descoberto —, e sem esta linha desapareceria da lista. É o dado mais útil
                // ali: diz por qual número a conta manda, e é o que o operador reconhece.
                $numero = self::texto($cred['phone_number_id'] ?? null);
                if ($numero !== '') {
                    $resumo['Número (ID)'] = $numero;
                }

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

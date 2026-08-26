<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Dominio\ErroDeDominio;
use App\Dominio\Placeholder;
use App\Suporte\Instante;
use App\Whatsapp\JanelaDeAtendimento;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Caixa de entrada. O webhook já populava `conversa` e `conversa_mensagem` e nada
 * mostrava — o Lead respondia e ninguém lia.
 *
 * Responder JÁ FUNCIONA, e o caminho é o que a versão anterior desta classe descrevia
 * como pré-requisito: um tipo de Ação para texto livre (`acao.tipo = 'resposta_texto'`)
 * e um remetente no worker. A tela enfileira; quem fala com a Meta é o worker.
 *
 * 🔴 A RECUSA VEM ANTES DE ENFILEIRAR. O risco que mantinha a tela somente-leitura era
 * "um campo de texto que às vezes funciona": o operador escreve, a Ação nasce condenada
 * e a resposta some. Por isso `responder()` recusa na hora, com o motivo escrito, nos
 * três casos em que o envio não pode dar certo — texto vazio, número na Lista de
 * bloqueio, e janela de 24 h fechada.
 *
 * A janela continua sendo o que a tela mostra de mais importante: dentro dela texto
 * livre é ilimitado e não custa por mensagem; fora, só template pago. E o worker a
 * RECONFERE no instante do envio, porque entre digitar e enviar ela pode virar.
 */
final class Conversas extends Component
{
    /** Na URL para o operador poder mandar o link de uma conversa a alguém. */
    #[Url(as: 'c', except: null)]
    public ?int $selecionada = null;

    /** O que o operador está escrevendo. Não vai para a URL — é rascunho, não endereço. */
    public string $resposta = '';

    /**
     * Botão de link ANEXADO à resposta pendente, quando veio de uma resposta rápida.
     *
     * 🔴 SEPARADO DA CAIXA DE TEXTO porque botão não é texto: é dado estruturado, e
     * não cabe num `textarea`. Ficando aqui, o operador continua editando o texto
     * livremente e vê o botão como etiqueta, com um "remover" ao lado — a revisão
     * antes de enviar continua existindo, que é a razão de o clique preencher em vez
     * de enviar.
     */
    public string $botaoTexto = '';

    public string $botaoUrl = '';

    public function render(): View
    {
        $fuso = new DateTimeZone(self::texto(config('app.timezone')) ?: 'UTC');
        $agora = new DateTimeImmutable('now', $fuso);

        return view('livewire.conversas', [
            'conversas' => $this->lista($agora),
            'mensagens' => $this->selecionada === null ? [] : $this->mensagens($this->selecionada),
            'detalhe' => $this->selecionada === null ? null : $this->detalhe($this->selecionada, $agora),
            'respostasRapidas' => $this->respostasRapidas(),
        ]);
    }

    public function selecionar(int $id): void
    {
        $this->selecionada = $id;
        $this->resposta = '';
        // Trocar de conversa descarta o botão junto: ele pertencia ao rascunho
        // anterior, e mandá-lo para outra pessoa seria o pior tipo de sobra.
        $this->removerBotao();
        $this->resetErrorBag();
    }

    /** Tira o botão do rascunho sem mexer no texto já escrito. */
    public function removerBotao(): void
    {
        $this->botaoTexto = '';
        $this->botaoUrl = '';
    }

    /**
     * Põe uma resposta pronta na caixa. NÃO envia.
     *
     * 🔴 PREENCHE EM VEZ DE ENVIAR, e a diferença de um clique é deliberada. A
     * mensagem é irreversível e vai para uma pessoa real; clique errado não tem
     * volta. Preenchendo, o operador confere e ajusta, e o botão de enviar está logo
     * ao lado. É o que o próprio WhatsApp Business faz, e pelo mesmo motivo.
     *
     * 🔴 O PLACEHOLDER É RESOLVIDO AQUI, não no cadastro, porque só aqui existe um
     * Lead para dar valor às colunas. E é a MESMA classe que o `fastagi` usa para
     * montar a Mensagem da chamada — duas implementações da mesma substituição
     * divergiriam, que é o contra-exemplo registrado no docblock dela.
     */
    public function usarResposta(int $id): void
    {
        $this->resetErrorBag();

        $linha = DB::table('resposta_rapida')->where('id', $id)->where('ativo', 1)->first();
        if ($linha === null) {
            return;
        }

        $texto = $linha->texto;
        if (! is_string($texto)) {
            return;
        }

        // O botão é anexado ANTES da substituição: se o Placeholder falhar, o
        // `addError` sai daqui com o botão já preso a um texto que não foi
        // preenchido. Por isso ele é limpo em cada falha, logo abaixo.
        $botaoTexto = (string) ($linha->botao_texto ?? '');
        $botaoUrl = (string) ($linha->botao_url ?? '');

        $placeholder = new Placeholder();

        // Texto sem Placeholder não precisa de Lead nenhum: entra como está, e é o
        // caso da maioria das respostas prontas.
        if ($placeholder->colunasCitadas($texto) === []) {
            $this->resposta = $texto;
            $this->botaoTexto = $botaoTexto;
            $this->botaoUrl = $botaoUrl;

            return;
        }

        $dados = $this->dadosDoLead();

        /*
         * 🔴 CONVERSA SEM LEAD É CASO REAL, não defeito. `conversa.lead_id` é
         * anulável: número desconhecido que escreveu para o WhatsApp abre Conversa
         * sem Lead, e aí não há coluna nenhuma para substituir.
         *
         * Dizer isso com o motivo é melhor que preencher com lacuna — "Olá, ," saindo
         * para uma pessoa real é pior que não preencher.
         */
        if ($dados === null) {
            $this->removerBotao();
            $this->addError(
                'resposta',
                'Esta resposta usa dados do Lead e esta conversa não tem um: o número '
                . 'escreveu sem estar em nenhuma Campanha. Escolha outra ou escreva à mão.',
            );

            return;
        }

        try {
            $this->resposta = $placeholder->resolverTexto($texto, $dados);
            $this->botaoTexto = $botaoTexto;
            $this->botaoUrl = $botaoUrl;
        } catch (ErroDeDominio $e) {
            $this->removerBotao();
            // A classe falha com causa NOMEADA em vez de substituir por vazio, e é
            // essa causa que o operador precisa ler para saber qual coluna falta.
            $this->addError('resposta', $e->getMessage());
        }
    }

    /**
     * As colunas do Lead desta Conversa, ou nulo quando não há Lead.
     *
     * @return array<string,string|int|float|null>|null
     */
    private function dadosDoLead(): ?array
    {
        if ($this->selecionada === null) {
            return null;
        }

        $leadId = DB::table('conversa')->where('id', $this->selecionada)->value('lead_id');
        if (! is_numeric($leadId)) {
            return null;
        }

        $extras = DB::table('leads')->where('id', (int) $leadId)->value('dados_extras');
        $decodificado = is_string($extras) ? json_decode($extras, true) : null;

        $saida = [];
        if (is_array($decodificado)) {
            foreach ($decodificado as $k => $v) {
                // Valor não escalar é DESCARTADO em vez de convertido: `resolverTexto`
                // então falha com "coluna ausente", que é a verdade, em vez de
                // substituir por "Array".
                if (is_string($v) || is_int($v) || is_float($v) || $v === null) {
                    $saida[(string) $k] = $v;
                }
            }
        }

        return $saida;
    }

    /**
     * As respostas prontas ativas, na ordem escolhida pelo operador.
     *
     * @return list<array{id:int, titulo:string}>
     */
    private function respostasRapidas(): array
    {
        $saida = [];
        foreach (DB::table('resposta_rapida')
            ->where('ativo', 1)->orderBy('ordem')->orderBy('id')->get() as $r) {
            $saida[] = ['id' => (int) $r->id, 'titulo' => (string) $r->titulo];
        }

        return $saida;
    }

    /**
     * Enfileira a resposta do operador.
     *
     * 🔴 ENFILEIRA, NÃO ENVIA. A Ação vai para a fila e o worker manda — a mesma
     * disciplina do ADR-0002. Enviar aqui poria uma chamada HTTP a um terceiro dentro
     * de uma requisição web, e duplicaria a resolução de credencial, a política de
     * tentativas e o mapeamento de erro que o worker já faz. O preço é a mensagem
     * aparecer na próxima volta do worker, não instantaneamente.
     *
     * As três recusas abaixo acontecem ANTES de a Ação nascer, porque enfileirar algo
     * que se sabe que vai falhar é o "meia-boca" que esta tela evitava ao ficar somente
     * leitura: o operador escreveria e a resposta sumiria.
     */
    public function responder(): void
    {
        $this->validate(
            ['resposta' => ['required', 'string', 'max:4000']],
            [
                'resposta.required' => 'Escreva alguma coisa antes de enviar.',
                'resposta.max' => 'O WhatsApp corta texto acima de 4096 caracteres.',
            ],
        );

        if ($this->selecionada === null) {
            return;
        }

        $fuso = new DateTimeZone(self::texto(config('app.timezone')) ?: 'UTC');
        $detalhe = $this->detalhe($this->selecionada, new DateTimeImmutable('now', $fuso));

        if ($detalhe === null) {
            return;
        }

        // Bloqueado é quem pediu para não ser contatado. A Lista de bloqueio vale para
        // Campanha e vale aqui: um "só uma última mensagem" digitado à mão é
        // exatamente o que ela existe para impedir.
        if ($detalhe['bloqueado'] === true) {
            $this->addError('resposta', 'Este número está na Lista de bloqueio e não pode ser contatado.');

            return;
        }

        // Fora da janela a Meta recusa texto livre com 131047. Recusar aqui, com o
        // motivo escrito, é melhor que enfileirar e o operador ver "falhou" depois.
        if ($detalhe['janela_aberta'] !== true) {
            $this->addError(
                'resposta',
                'A janela de 24 h fechou. Só template pode ser enviado agora — '
                . 'e é o Disparo da Campanha que faz isso.',
            );

            return;
        }

        /*
         * 🔴 A MENSAGEM NASCE PRIMEIRO, e a Ação aponta para ELA — não para a Conversa.
         *
         * `acao` tem `unique(tipo, referencia_id)`, e a migration diz por quê:
         * "Idempotência para TODA Ação, no esquema e não no código". Apontar para a
         * Conversa fazia a segunda resposta do mesmo diálogo colidir — testado, e o erro
         * é `Duplicate entry 'resposta_texto-2'`. Uma Conversa tem muitas respostas; uma
         * mensagem tem um envio. Referenciando a mensagem, a restrição passa a dizer a
         * coisa certa em vez de atrapalhar.
         *
         * Ganho de brinde: a resposta aparece na hora na conversa, como pendente
         * (`wamid` e `status` nulos), em vez de sumir até o worker rodar.
         */
        $mensagemId = DB::table('conversa_mensagem')->insertGetId([
            'conversa_id' => $this->selecionada,
            'wamid' => null,      // chega quando a Meta aceitar
            'direcao' => 'saida',
            'tipo' => 'text',
            'texto' => trim($this->resposta),
            // O botão viaja NA MENSAGEM, não numa Ação de tipo novo: o caminho de
            // envio continua sendo um só, e o worker escolhe entre texto e interativo
            // olhando estas duas colunas.
            'botao_texto' => $this->botaoTexto === '' ? null : $this->botaoTexto,
            'botao_url' => $this->botaoUrl === '' ? null : $this->botaoUrl,
            'status' => null,     // nulo = ainda não enviada
            'criado_em' => now(),
        ]);

        DB::table('acao')->insert([
            'tipo' => 'resposta_texto',
            'referencia_id' => $mensagemId,
            'status' => 'pendente',
            'tentativas' => 0,
            'criado_em' => now(),
        ]);

        $this->resposta = '';
        $this->removerBotao();
        $this->dispatch('resposta-enfileirada');
    }

    /**
     * Conversas com entrada mais recente primeiro.
     *
     * `ultima_entrada_em DESC` e não `criado_em`: quem escreveu agora é quem espera
     * resposta, e é por quem a janela está correndo. Ordenar por criação enterraria uma
     * conversa antiga que acabou de receber mensagem.
     *
     * 🔴 SÓ QUEM RESPONDEU. `ultima_entrada_em IS NOT NULL` é exatamente "esta pessoa nos
     * escreveu pelo menos uma vez" — a coluna só é tocada por `registrarEntrada`.
     *
     * A caixa passou a criar `conversa` também na SAÍDA, para o Disparo aparecer no
     * diálogo. O efeito colateral era a lista encher de linhas de gente que só recebeu e
     * nunca respondeu: numa Campanha de 10 mil Leads, 10 mil conversas mudas enterrariam
     * as poucas que exigem atenção. Esta tela existe para quem espera resposta.
     *
     * Quem só recebeu continua visível onde faz sentido — nos Resultados da Campanha,
     * com o status de entrega ao lado do Lead.
     *
     * @return list<array<string,mixed>>
     */
    private function lista(DateTimeImmutable $agora): array
    {
        $saida = [];

        foreach (DB::select(
            "SELECT c.id, c.telefone, c.lead_id, c.ultima_entrada_em,
                    (SELECT COUNT(*) FROM conversa_mensagem m WHERE m.conversa_id = c.id) AS mensagens,
                    (SELECT m2.texto FROM conversa_mensagem m2
                      WHERE m2.conversa_id = c.id ORDER BY m2.id DESC LIMIT 1) AS ultimo_texto,
                     (SELECT m4.tipo FROM conversa_mensagem m4
                      WHERE m4.conversa_id = c.id ORDER BY m4.id DESC LIMIT 1) AS ultimo_tipo,
                    (SELECT m3.direcao FROM conversa_mensagem m3
                      WHERE m3.conversa_id = c.id ORDER BY m3.id DESC LIMIT 1) AS ultima_direcao,
                    EXISTS(SELECT 1 FROM bloqueio b WHERE b.telefone = c.telefone) AS bloqueado
               FROM conversa c
              WHERE c.ultima_entrada_em IS NOT NULL
              ORDER BY c.ultima_entrada_em DESC, c.id DESC
              LIMIT 200"
        ) as $linha) {
            $c = (array) $linha;
            $janela = new JanelaDeAtendimento($this->momento($c['ultima_entrada_em'] ?? null));

            $saida[] = [
                'id' => self::inteiro($c['id'] ?? null),
                'telefone' => self::texto($c['telefone'] ?? null),
                'lead_id' => is_numeric($c['lead_id'] ?? null) ? (int) $c['lead_id'] : null,
                'mensagens' => self::inteiro($c['mensagens'] ?? null),
                /*
                 * 🔴 O RESUMO CAI NO TIPO quando não há texto, em vez de um traço.
                 *
                 * Medido em 26/08/2026: 213 das 243 mensagens não tinham texto — o
                 * Disparo era gravado vazio —, e a lista inteira mostrava `—`. Duas
                 * conversas diferentes ficavam indistinguíveis, e a lista deixava de
                 * servir para escolher qual abrir.
                 *
                 * Mensagem nova já nasce com texto; isto é o que salva o histórico.
                 */
                'ultimo_texto' => self::texto($c['ultimo_texto'] ?? null)
                    ?: self::resumoDoTipo(self::texto($c['ultimo_tipo'] ?? null)),
                'ultima_direcao' => self::texto($c['ultima_direcao'] ?? null),
                'bloqueado' => (bool) ($c['bloqueado'] ?? false),
                'janela_aberta' => $janela->aberta($agora),
                'janela_restante' => $janela->restante($agora),
                'janela_acabando' => $janela->acabando($agora),
            ];
        }

        return $saida;
    }

    /** @return array<string,mixed>|null */
    private function detalhe(int $id, DateTimeImmutable $agora): ?array
    {
        $linha = DB::table('conversa')->where('id', $id)->first();
        if ($linha === null) {
            return null;
        }
        $c = (array) $linha;

        $telefone = self::texto($c['telefone'] ?? null);
        $janela = new JanelaDeAtendimento($this->momento($c['ultima_entrada_em'] ?? null));

        return [
            'id' => $id,
            'telefone' => $telefone,
            'lead_id' => is_numeric($c['lead_id'] ?? null) ? (int) $c['lead_id'] : null,
            'bloqueado' => DB::table('bloqueio')->where('telefone', $telefone)->exists(),
            'janela_aberta' => $janela->aberta($agora),
            'janela_restante' => $janela->restante($agora),
            'janela_acabando' => $janela->acabando($agora),
        ];
    }

    /**
     * Um resumo curto para a lista, quando a mensagem não tem texto.
     *
     * Vale para mídia — cujo conteúdo o webhook descarta — e para o histórico de
     * Disparos gravados antes de o corpo do template ser guardado.
     */
    private static function resumoDoTipo(string $tipo): string
    {
        return match ($tipo) {
            'audio' => '🎤 áudio',
            'image' => '🖼️ imagem',
            'sticker' => '💬 figurinha',
            'video' => '🎬 vídeo',
            'document' => '📄 documento',
            'location' => '📍 localização',
            'reaction' => 'reagiu à mensagem',
            'template' => 'template enviado',
            '' => '—',
            default => $tipo,
        };
    }

    /** @return list<array<string,mixed>> */
    private function mensagens(int $conversaId): array
    {
        $saida = [];

        foreach (DB::table('conversa_mensagem')
            ->where('conversa_id', $conversaId)
            ->orderBy('id')
            ->limit(500)
            ->get() as $linha) {
            $m = (array) $linha;

            $saida[] = [
                'direcao' => self::texto($m['direcao'] ?? null),
                'tipo' => self::texto($m['tipo'] ?? null),
                'texto' => self::texto($m['texto'] ?? null),
                'template' => self::texto($m['template_nome'] ?? null),
                'status' => self::texto($m['status'] ?? null),
                'erro' => is_numeric($m['erro_codigo'] ?? null) ? (int) $m['erro_codigo'] : null,
                'quando' => Instante::local($m['criado_em'] ?? null),
            ];
        }

        return $saida;
    }

    /**
     * O carimbo do MySQL vem sem fuso. Interpretá-lo no fuso da aplicação é o que faz a
     * conta da janela fechar: ler como UTC daria três horas de diferença no Brasil, e a
     * janela pareceria fechada quando ainda há tempo de responder de graça.
     */
    private function momento(mixed $v): ?DateTimeImmutable
    {
        if (! is_string($v) || $v === '') {
            return null;
        }

        $fuso = new DateTimeZone(self::texto(config('app.timezone')) ?: 'UTC');

        try {
            return new DateTimeImmutable($v, $fuso);
        } catch (\Exception) {
            return null;
        }
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

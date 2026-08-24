<?php

declare(strict_types=1);

namespace App\Livewire;

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

    public function render(): View
    {
        $fuso = new DateTimeZone(self::texto(config('app.timezone')) ?: 'UTC');
        $agora = new DateTimeImmutable('now', $fuso);

        return view('livewire.conversas', [
            'conversas' => $this->lista($agora),
            'mensagens' => $this->selecionada === null ? [] : $this->mensagens($this->selecionada),
            'detalhe' => $this->selecionada === null ? null : $this->detalhe($this->selecionada, $agora),
        ]);
    }

    public function selecionar(int $id): void
    {
        $this->selecionada = $id;
        $this->resposta = '';
        $this->resetErrorBag();
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
                'ultimo_texto' => self::texto($c['ultimo_texto'] ?? null),
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

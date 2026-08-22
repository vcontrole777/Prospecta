<?php

declare(strict_types=1);

namespace Tests\Unit\Fastagi;

use App\Fastagi\Dados;
use App\Fastagi\CanalMorto;
use App\Fastagi\Fluxo;
use App\Fastagi\Registro;
use App\Fastagi\Resultado;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * FASE 3 exercitada de ponta a ponta, sem Asterisk e sem MySQL. É a fase mais
 * complexa do produto e é justamente a que não toca banco (ADR-0001).
 */
final class FluxoTest extends TestCase
{
    private const OK = '200 result=0';

    /** @var resource */
    private $log;

    protected function setUp(): void
    {
        $l = fopen('php://memory', 'r+');
        if ($l === false) {
            throw new \RuntimeException('fluxo');
        }
        $this->log = $l;
    }

    /**
     * 🔴 A REGRESSÃO MAIS CARA DO PROJETO, achada em chamada real com `agi set debug on`.
     *
     * O Asterisk envia uma linha `HANGUP` SOZINHA, sem ser resposta a nada. O leitor lia
     * UMA linha e, quando ela não casava com a expressão, devolvia resposta vazia em
     * SILÊNCIO — deixando a resposta verdadeira na fila e deslocando o diálogo em um.
     *
     * O efeito não era erro visível: era DADO FABRICADO. Aqui o `HANGUP` chega depois da
     * resposta do `AMD`, e sem o conserto o `amdStatus` viria nulo e o `amdCause` viria
     * "HANGUP" — e o fluxo seguiria para a URA num canal morto, extraindo tecla de
     * respostas de `GET VARIABLE`.
     *
     * Com o conserto, o veredito é lido certo e o desfecho é `nao_atendeu`, que é a
     * verdade: a pessoa desligou durante a análise.
     */
    #[Test]
    public function hangup_assincrono_nao_desloca_o_dialogo(): void
    {
        [$r] = $this->rodar([
            self::OK,                          // ANSWER
            self::OK,                          // MixMonitor
            self::OK, '200 result=1 (SUCCESS)', // abertura + PLAYBACKSTATUS
            self::OK,                          // EXEC AMD
            'HANGUP',                          // 🔴 notificação ASSÍNCRONA, no meio
            '200 result=1 (HANGUP)',           // AMDSTATUS, a resposta de verdade
            '200 result=1 ()',                 // AMDCAUSE
        ]);

        $this->assertSame('nao_atendeu', $r->desfecho, 'desligou durante a análise');
        $this->assertSame('HANGUP', $r->amdStatus, 'o veredito foi lido no lugar certo');
    }

    /**
     * `511 Command Not Permitted on a dead channel` é canal morto, e antes caía no mesmo
     * silêncio: virava resposta vazia e o fluxo seguia tocando áudio e lendo dígito num
     * canal que não existe mais.
     */
    #[Test]
    public function o_511_de_canal_morto_encerra_o_fluxo(): void
    {
        $arreio = new ArreioAgi([
            self::OK,                          // ANSWER
            self::OK,                          // MixMonitor
            self::OK, '200 result=1 (SUCCESS)', // abertura
            self::OK, '200 result=1 (HUMAN)', '200 result=1 (HUMAN-800-800)', // AMD
            self::OK,                          // WaitForSilence
            '511 Command Not Permitted on a dead channel or intercept routine',
        ]);
        $r = new Resultado();

        try {
            (new Fluxo($arreio->agi, new Registro($this->log, 4242)))->executar($this->dados(), $r);
            $this->fail('o 511 devia ter virado CanalMorto');
        } catch (CanalMorto $e) {
            // É assim que o `bin/fastagi.php` trata: desligou, não é erro, e a fase 4
            // grava o que se sabe. Antes o 511 virava resposta vazia em silêncio e o
            // fluxo seguia tocando áudio e lendo dígito num canal que não existe.
            $this->assertStringContainsString('GET DATA', $e->comando);
        }

        // E NENHUMA tecla foi inventada.
        $this->assertNull($r->teclaDigitada);
        $this->assertNotSame('invalido', $r->desfecho);
    }

    /**
     * 🔴 SEM ÁUDIO DE MENSAGEM, RECUSA — não disca mudo.
     *
     * Havia um `'prospecta/mensagem'` FIXO no lugar deste nulo, apontando para um
     * arquivo que o código referenciava e o provisionamento nunca instalou. O efeito
     * era `lerDigitos` sem prompt: a pessoa ouvia SILÊNCIO por 8 segundos e o desfecho
     * virava `sem_resposta` — indistinguível de quem simplesmente não quis responder,
     * e por isso invisível em qualquer relatório.
     */
    #[Test]
    public function sem_audio_de_mensagem_recusa_com_causa_nomeada(): void
    {
        [$r, $comandos] = $this->rodar(
            $this->ate_amd('HUMAN'),
            $this->dados(audioMensagem: null),
        );

        $this->assertSame('erro', $r->desfecho);
        $this->assertSame('AUDIO_NAO_CONFIGURADO', $r->erro);

        // E NÃO tentou ler dígito: a recusa acontece ANTES da URA. Sem esta asserção,
        // o teste passaria mesmo se a recusa viesse depois de a pessoa ouvir silêncio.
        foreach ($comandos as $cmd) {
            $this->assertStringNotContainsString('GET DATA', $cmd);
        }
    }

    /** @param 'opt_in'|'captura' $tipo */
    /** @param list<string>|null $amd */
    private function dados(
        string $tipo = 'opt_in',
        int $tentativas = 2,
        bool $gravar = true,
        ?string $audioMensagem = 'prospecta/mensagem',
        bool $classificadorPosAtivo = true,
        ?array $amd = null,
    ): Dados {
        return new Dados(
            ligacaoId: 4242,
            tipo: $tipo,
            audioAbertura: 'prospecta/abertura',
            audioMensagem: $audioMensagem,
            audioConfirmaOptIn: 'prospecta/optin',
            audioConfirmaOptOut: 'prospecta/optout',
            audioConfirmaCaptura: 'prospecta/recebi',
            audioNaoEntendi: 'prospecta/naoentendi',
            teclaOptIn: '2',
            teclaOptOut: '9',
            tentativasUra: $tentativas,
            gravacaoAtiva: $gravar,
            gravacaoDir: '/var/spool/asterisk/gravacoes',
            campos: $tipo === 'captura' ? [[
                'nome' => 'cpf', 'tamanho' => 11, 'tipo' => 'cpf',
                'audio' => 'prospecta/pedecpf', 'obrigatorio' => true,
                'tentativas' => 2, 'timeoutSeg' => 15,
            ]] : [],
            classificadorPosAtivo: $classificadorPosAtivo,
            amd: $amd ?? ['6000', '4000', '800', '5000', '100', '50', '3', '256', '5000'],
        );
    }

    /**
     * @param list<string> $respostas
     * @return array{0:Resultado,1:list<string>}
     */
    private function rodar(array $respostas, ?Dados $d = null): array
    {
        $arreio = new ArreioAgi($respostas);
        $r = new Resultado();
        (new Fluxo($arreio->agi, new Registro($this->log, 4242)))->executar($d ?? $this->dados(), $r);

        return [$r, $arreio->comandos()];
    }

    /** @return list<string> ANSWER, MixMonitor, abertura ok, AMD com o status pedido, WaitForSilence */
    private function ate_amd(string $amdStatus): array
    {
        return [
            self::OK,                                  // ANSWER
            self::OK,                                  // MixMonitor
            self::OK,                                  // Playback abertura
            '200 result=1 (SUCCESS)',                  // PLAYBACKSTATUS
            self::OK,                                  // AMD
            "200 result=1 ({$amdStatus})",             // AMDSTATUS
            '200 result=1 (CAUSA-1-1)',                // AMDCAUSE
            self::OK,                                  // WaitForSilence
        ];
    }

    #[Test]
    public function opt_in_do_inicio_ao_fim(): void
    {
        [$r, $cmd] = $this->rodar([
            ...$this->ate_amd('HUMAN'),
            '200 result=2',            // GET DATA -> tecla 2
            self::OK,                  // Playback optin
            '200 result=1 (SUCCESS)',  // PLAYBACKSTATUS
        ]);

        $this->assertSame('opt_in', $r->desfecho);
        $this->assertSame('2', $r->teclaDigitada);
        $this->assertSame('HUMAN', $r->amdStatus);
        $this->assertTrue($r->atendida);
    }

    #[Test]
    public function o_caminho_da_gravacao_vem_do_id_INTEIRO_da_ligacao(): void
    {
        // ADR-0003: caminho derivado de inteiro fecha a classe de injeção por
        // construção. No MagnusBilling o CallerID cru compõe o caminho, e uma
        // barra escreve fora do diretório previsto.
        [$r, $cmd] = $this->rodar([...$this->ate_amd('HUMAN'), '200 result=2', self::OK, '200 result=1 (SUCCESS)']);

        $this->assertSame('/var/spool/asterisk/gravacoes/4242.gsm', $r->gravacao);
        $this->assertContains('EXEC MixMonitor "/var/spool/asterisk/gravacoes/4242.gsm"', $cmd);
    }

    #[Test]
    public function mixmonitor_NUNCA_leva_a_opcao_b(): void
    {
        // MEDIDO: `b` significa "só gravar em ponte", e a Ligação do produto
        // nunca tem ponte. Com `b`, arquivo de 44 bytes e falha silenciosa.
        [, $cmd] = $this->rodar([...$this->ate_amd('HUMAN'), '200 result=2', self::OK, '200 result=1 (SUCCESS)']);

        foreach ($cmd as $c) {
            if (str_contains($c, 'MixMonitor')) {
                $this->assertStringNotContainsString(',b', $c);
            }
        }
    }

    #[Test]
    public function amd_MACHINE_encerra_e_NAO_le_tecla(): void
    {
        [$r, $cmd] = $this->rodar([
            self::OK, self::OK, self::OK, '200 result=1 (SUCCESS)',
            self::OK, '200 result=1 (MACHINE)', '200 result=1 (LONGGREETING-2200-2200)',
        ]);

        $this->assertSame('maquina', $r->desfecho);
        $this->assertNull($r->teclaDigitada);
        foreach ($cmd as $c) {
            $this->assertStringNotContainsString('GET DATA', $c, 'não deve tocar a Mensagem para máquina');
        }
    }

    #[Test]
    public function amd_HANGUP_nao_e_humano(): void
    {
        // O MagnusBilling joga todo o não-MACHINE no ramo humano, grava
        // "atendida por humano" e toca áudio em canal morto.
        [$r, $cmd] = $this->rodar([
            self::OK, self::OK, self::OK, '200 result=1 (SUCCESS)',
            self::OK, '200 result=1 (HANGUP)', '200 result=1 (HANGUP-0-0)',
        ]);

        $this->assertSame('nao_atendeu', $r->desfecho);
        foreach ($cmd as $c) {
            $this->assertStringNotContainsString('GET DATA', $c);
        }
    }

    #[Test]
    public function amd_NOTSURE_segue_como_humano_de_proposito(): void
    {
        // Falso-HUMANO gasta segundos de áudio numa secretária. Falso-MÁQUINA
        // desliga na cara de quem atendeu, e é irreversível.
        [$r] = $this->rodar([
            ...$this->ate_amd('NOTSURE'),
            '200 result=2', self::OK, '200 result=1 (SUCCESS)',
        ]);

        $this->assertSame('opt_in', $r->desfecho);
    }

    #[Test]
    public function audio_ausente_vira_desfecho_NOMEADO_em_vez_de_silencio(): void
    {
        // MEDIDO: Playback em arquivo ausente devolve FAILED e o dialplan
        // CONTINUA. O Lead ouviria silêncio e o fluxo seguiria.
        [$r, $cmd] = $this->rodar([
            self::OK, self::OK, self::OK, '200 result=1 (FAILED)',
        ]);

        $this->assertSame('erro', $r->desfecho);
        $this->assertSame('AUDIO_MISSING', $r->erro);
        foreach ($cmd as $c) {
            $this->assertStringNotContainsString('EXEC AMD', $c, 'não deve classificar sem ter tocado a abertura');
        }
    }

    #[Test]
    public function opt_out_entra_no_desfecho(): void
    {
        [$r] = $this->rodar([
            ...$this->ate_amd('HUMAN'),
            '200 result=9', self::OK, '200 result=1 (SUCCESS)',
        ]);

        $this->assertSame('opt_out', $r->desfecho);
        $this->assertSame('9', $r->teclaDigitada);
    }

    #[Test]
    public function tecla_invalida_repete_e_aceita_na_segunda(): void
    {
        [$r] = $this->rodar([
            ...$this->ate_amd('HUMAN'),
            '200 result=7',            // inválida
            self::OK, '200 result=1 (SUCCESS)',   // naoentendi
            '200 result=2',            // opt-in na segunda
            self::OK, '200 result=1 (SUCCESS)',   // optin
        ]);

        $this->assertSame('opt_in', $r->desfecho);
    }

    #[Test]
    public function sem_tecla_nenhuma_da_sem_resposta(): void
    {
        [$r] = $this->rodar([
            ...$this->ate_amd('HUMAN'),
            '200 result=',                        // timeout
            self::OK, '200 result=1 (SUCCESS)',   // naoentendi
            '200 result=',                        // timeout de novo
        ]);

        $this->assertSame('sem_resposta', $r->desfecho);
    }

    #[Test]
    public function captura_com_cpf_valido_conclui(): void
    {
        [$r] = $this->rodar([
            ...$this->ate_amd('HUMAN'),
            // 🔴 A TECLA DE OPT-IN VEM ANTES DO CPF. A captura passou a exigir o mesmo
            // portão do opt_in: Mensagem → tecla → só então as perguntas. Pedir documento
            // a quem não ouviu a oferta e não concordou é pedir sem autorização.
            '200 result=2',            // GET DATA -> tecla de opt-in
            self::OK,                  // Playback do áudio de confirmação
            '200 result=1 (SUCCESS)',  // PLAYBACKSTATUS
            '200 result=52998224725',  // GET DATA -> o CPF
            self::OK,                  // Playback do "recebi, obrigado"
            '200 result=1 (SUCCESS)',  // PLAYBACKSTATUS
        ], $this->dados('captura'));

        $this->assertSame('captura_concluida', $r->desfecho);
        $this->assertSame(['cpf' => '52998224725'], $r->respostas);
        // O Opt-in é ETAPA na captura, não desfecho — mas a tecla fica registrada.
        $this->assertSame('2', $r->teclaDigitada);
    }

    /**
     * Chave desligada: o `AMD` não é executado, `amd_status` fica NULO e o fluxo segue
     * como humano. Nulo é "não classificamos", distinto de `NOTSURE`, que é
     * "classificamos e não deu para decidir" — misturar os dois na mesma coluna estragaria
     * a comparação entre baldes, que é a análise que o classificador existe para alimentar.
     */
    #[Test]
    public function classificador_desligado_nao_executa_o_AMD(): void
    {
        [$r, $comandos] = $this->rodar([
            self::OK,                  // ANSWER
            self::OK,                  // MixMonitor
            self::OK,                  // Playback abertura
            '200 result=1 (SUCCESS)',  // PLAYBACKSTATUS
            self::OK,                  // WaitForSilence — direto, sem AMD no meio
            '200 result=2',            // GET DATA -> tecla de opt-in
            self::OK,
            '200 result=1 (SUCCESS)',
        ], $this->dados(classificadorPosAtivo: false));

        $this->assertSame('opt_in', $r->desfecho);
        $this->assertNull($r->amdStatus);
        $this->assertNull($r->amdCause);

        foreach ($comandos as $c) {
            $this->assertStringNotContainsString('AMD', $c);
        }
    }

    #[Test]
    public function classificador_ligado_executa_o_AMD_com_os_parametros_recebidos(): void
    {
        // Os nove parâmetros são posicionais e vêm da `configuracao`. Trocar duas posições
        // não dá erro, dá classificação errada em silêncio — por isso o teste confere a
        // linha inteira, não só a presença do comando.
        [, $comandos] = $this->rodar(
            $this->ate_amd('MACHINE'),
            $this->dados(amd: ['2500', '1500', '800', '5000', '100', '50', '2', '256', '5000']),
        );

        $this->assertContains('EXEC AMD "2500,1500,800,5000,100,50,2,256,5000"', $comandos);
    }

    #[Test]
    public function desligar_no_meio_da_captura_NAO_vira_opt_in(): void
    {
        // 🔴 Regressão medida em produção 21/08/2026: o portão grava `opt_in` como valor
        // transitório, e o `CanalMorto` do desligamento pulava por cima da sobrescrita do
        // fim de `captura()`. O desfecho ficava `opt_in` numa Campanha de captura, e o
        // `Banco::gravar` — que decide tudo por `desfecho === 'opt_in'` — criava Disparo
        // numa Campanha sem template e carimbava `leads.opt_in_em`, aposentando para
        // sempre quem não entregou dado nenhum. Foram 5 Leads perdidos para 1 captura boa.
        $arreio = new ArreioAgi([
            ...$this->ate_amd('HUMAN'),
            '200 result=2',            // tecla de opt-in: o portão abre
            self::OK,
            '200 result=1 (SUCCESS)',
            '511 Command Not Permitted on a dead channel',   // desligou no prompt do CPF
        ]);
        $r = new Resultado();

        try {
            (new Fluxo($arreio->agi, new Registro($this->log, 4242)))
                ->executar($this->dados('captura'), $r);
            $this->fail('devia ter lançado CanalMorto');
        } catch (\App\Fastagi\CanalMorto) {
            // É assim que o `fastagi.php` recebe o desligamento: exceção capturada, e o
            // `Resultado` parcial segue para a FASE 4. O que a FASE 4 vê é o que importa.
        }

        $this->assertSame('captura_parcial', $r->desfecho);
        $this->assertNotSame('opt_in', $r->desfecho);
        $this->assertSame([], $r->respostas);
        // A tecla continua registrada: ela aconteceu, e é o que diz que a pessoa ouviu a
        // oferta e concordou antes de desistir na hora do documento.
        $this->assertSame('2', $r->teclaDigitada);
    }

    #[Test]
    public function captura_com_cpf_invalido_esgota_e_da_parcial(): void
    {
        // O dígito verificador é validado AQUI, antes de gastar rede. O Lead
        // está na linha, e consulta que ia falhar não deveria custar o tempo
        // dele nem o custo da chamada de API.
        [$r] = $this->rodar([
            ...$this->ate_amd('HUMAN'),
            '200 result=2',             // tecla de opt-in
            self::OK,
            '200 result=1 (SUCCESS)',
            '200 result=11111111111',   // repetido: passa o módulo 11 mas não é CPF
            '200 result=52998224724',   // dígito verificador errado
        ], $this->dados('captura'));

        $this->assertSame('captura_parcial', $r->desfecho);
        $this->assertSame([], $r->respostas);

        // 🔴 O QUE FOI DIGITADO FICA. Antes o valor reprovado era descartado e a tela
        // mostrava célula vazia: o operador não sabia se a pessoa errou o dígito
        // verificador, digitou pouco, ou digitou o documento de outra pessoa.
        $this->assertSame(
            ['cpf' => ['11111111111', '52998224724']],
            $r->tentativasInvalidas,
        );
    }

    #[Test]
    public function lead_desligando_no_meio_preserva_o_estado_parcial(): void
    {
        // O caso comum do produto: aperta a tecla de opt-in e desliga no mesmo
        // instante. Se o estado se perdesse, perderíamos o registro de quem
        // pediu contato explicitamente — a pior falha possível (ADR-0002).
        $arreio = new ArreioAgi([self::OK, self::OK]);
        $r = new Resultado();
        $arreio->matarCanal();

        try {
            (new Fluxo($arreio->agi, new Registro($this->log, 4242)))->executar($this->dados(), $r);
            $this->fail('devia ter lançado CanalMorto');
        } catch (\App\Fastagi\CanalMorto) {
            // O que já se sabe sobreviveu para a FASE 4 gravar.
            $this->assertFalse($r->atendida);
            $this->assertNull($r->desfecho);
        }
    }
}

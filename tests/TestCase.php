<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /**
     * 🔴 DEVOLVE A LINHA ÚNICA DE `configuracao` DEPOIS DE TRUNCAR.
     *
     * `DatabaseTruncation` limpa toda tabela e não roda seeder nenhum. A linha `id = 1`
     * de `configuracao` é semeada por MIGRATION, porque sem ela toda chamada falha — é
     * pressuposto do esquema, não dado de teste. Truncar deixa o banco num estado em que
     * nenhuma instância de produção pode estar.
     *
     * O estrago não aparece no teste que truncou; aparece no próximo que usa a linha, e
     * já custou uma investigação: `FilaTest` truncou e, três arquivos depois, o
     * `WebhookWhatsappTest` passou a devolver 403 — porque o `update` do `setUp` dele
     * casava ZERO linhas em silêncio, o webhook não achava a integração e recusava a
     * assinatura. Nada no sintoma aponta para truncagem.
     *
     * Condicional ao trait DE PROPÓSITO: fazer isso em todo teste tornaria
     * `ConfiguracaoSingletonTest` vazio, porque ele afirma que a MIGRATION semeia a linha.
     */
    protected function setUp(): void
    {
        parent::setUp();

        if ($this->trunca()) {
            DB::table('configuracao')->insertOrIgnore(['id' => 1]);
        }
    }

    /**
     * 🔴 LIMPA O QUE COMITOU, na saída.
     *
     * `DatabaseTruncation` trunca na ENTRADA de cada teste e nunca na saída. O último
     * teste de uma classe que trunca deixa suas linhas COMITADAS no banco — e teste com
     * `RefreshDatabase` não as limpa, porque ele só desfaz a própria transação.
     *
     * O resultado é dependência de ORDEM: `PainelTest` afirma que sem Campanha a tela
     * mostra o estado vazio, e passou a falhar porque `FilaTest` — que roda antes por
     * ordem alfabética — deixou Campanhas comitadas. O teste está certo; o vizinho é que
     * sujou. Antes de existir uma classe que truncasse depois de `BancoConfiguracaoTest`,
     * isso funcionava por acidente.
     *
     * Truncar na saída torna a suíte independente de ordem, que é a propriedade que
     * importa: um teste não deve conseguir quebrar outro.
     */
    protected function tearDown(): void
    {
        if ($this->trunca()) {
            $this->limparTudo();
        }

        parent::tearDown();
    }

    private function trunca(): bool
    {
        return in_array(DatabaseTruncation::class, class_uses_recursive(static::class), true);
    }

    /**
     * Esvazia o banco e devolve a linha única de `configuracao`.
     *
     * SQL direto e não `truncateDatabaseTables()` do trait: aquele método só existe na
     * classe que usa o trait, e chamá-lo daqui seria chamar método que este arquivo não
     * declara. `migrations` fica de fora — apagá-la faria o `RefreshDatabase` do próximo
     * arquivo remigrar tudo.
     */
    private function limparTudo(): void
    {
        $banco = config('database.connections.mysql.database');
        $banco = is_string($banco) ? $banco : '';
        if ($banco === '') {
            return;
        }

        DB::statement('SET FOREIGN_KEY_CHECKS = 0');
        try {
            foreach (DB::select(
                'SELECT table_name AS t FROM information_schema.tables
                  WHERE table_schema = ? AND table_type = ?',
                [$banco, 'BASE TABLE'],
            ) as $linha) {
                $tabela = ((array) $linha)['t'] ?? null;
                if (! is_string($tabela) || $tabela === 'migrations') {
                    continue;
                }
                DB::statement('TRUNCATE TABLE `' . $tabela . '`');
            }
        } finally {
            DB::statement('SET FOREIGN_KEY_CHECKS = 1');
        }

        // Devolve o pressuposto do esquema: o próximo arquivo pode não truncar, e sem
        // esta linha toda chamada dele falharia com "Ligação não encontrada".
        DB::table('configuracao')->insertOrIgnore(['id' => 1]);
    }

    /**
     * 🔴 RECUSA RODAR CONTRA BANCO QUE NÃO SEJA O DE TESTE.
     *
     * Isto não é zelo — é o conserto de um estrago que já quase aconteceu.
     *
     * Um `set -a; . ./.env; set +a` no shell, feito para executar `bin/dialer.php` ou
     * `bin/worker.php` à mão, exporta `DB_DATABASE=prospecta`. E o PHPUnit NÃO ganha
     * dessa variável: mesmo com `force="true"` nas entradas de `<env>`, a suíte foi
     * medida rodando com `banco=prospecta env=local sessao=database`.
     *
     * O dano possível é real: `BancoConfiguracaoTest` usa `DatabaseTruncation`, que
     * TRUNCA E COMITA. Uma execução assim apaga as tabelas do banco de produção — e o
     * único sinal foi um teste de CSRF falhando com 419, que não aponta para nada
     * disso. Levei três execuções e uma bissecção para achar a causa.
     *
     * A guarda vive aqui, e não na configuração, porque precedência de variável de
     * ambiente é justamente o mecanismo que falhou. Uma checagem explícita no início
     * de cada teste não depende de quem ganha de quem.
     */
    protected function refreshApplication(): void
    {
        parent::refreshApplication();

        // 🔴 AQUI, e não em `setUp`. A aplicação já existe — então `config()` responde
        // — e `setUpTraits()` ainda NÃO rodou, então a truncagem ainda não aconteceu.
        // Em `setUp` seria tarde: o trait roda dentro do `parent::setUp()`, antes de
        // qualquer linha nossa. Verificado na ordem de
        // InteractsWithTestCaseLifecycle::setUpTheTestEnvironment.
        $this->exigirBancoDeTeste();
    }

    private function exigirBancoDeTeste(): void
    {
        $banco = config('database.connections.mysql.database');
        $banco = is_string($banco) ? $banco : '';

        if (! str_ends_with($banco, '_test')) {
            throw new RuntimeException(sprintf(
                "ABORTADO: os testes iriam rodar contra o banco \"%s\", que não termina em _test.\n\n"
                . "Causa quase certa: o .env está exportado neste shell. Rode `env -u DB_DATABASE -u APP_ENV "
                . "vendor/bin/phpunit`, ou abra um shell limpo.\n\n"
                . 'Sem esta guarda, DatabaseTruncation truncaria e comitaria no banco real.',
                $banco === '' ? '(vazio)' : $banco,
            ));
        }
    }
}

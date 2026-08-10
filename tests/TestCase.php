<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
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

<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A linha única de `configuracao` é pressuposto do esquema, no mesmo nível de uma
 * coluna existir — e nada a criava. Numa instância recém-migrada, TODA chamada
 * falhava.
 *
 * Este teste usa a conexão do framework, que é a que vê o resultado das migrations.
 * O comportamento do `Banco` diante da linha está em `BancoConfiguracaoTest`, que
 * precisa de outra estratégia de banco — ver o comentário lá.
 */
final class ConfiguracaoSingletonTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_migration_semeia_a_linha_unica(): void
    {
        $this->assertSame(1, DB::table('configuracao')->count(), 'banco novo sem esta linha = produto morto');
        $this->assertSame(1, (int) DB::table('configuracao')->value('id'));
    }

    /** O default da coluna é gravar, e é o que a linha semeada herda. */
    #[Test]
    public function a_linha_semeada_vem_com_gravacao_ativa(): void
    {
        $this->assertSame(1, (int) DB::table('configuracao')->value('gravacao_ativa'));
    }
}

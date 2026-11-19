<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Campanha\Reinicio;
use Illuminate\Console\Command;

/**
 * Reinicia as Campanhas que o painel marcou. Roda a cada minuto pelo systemd
 * (`prospecta-reiniciar-campanhas.timer`); sem marca, é uma consulta e sai.
 *
 * Comando do framework, e não script de bootstrap mínimo como `bin/apagar-campanhas.php`:
 * o fim do reinício põe a Campanha no ar pelas guardas de `App\Campanha\Ativacao`, que leem
 * configuração e conferem áudio no disco. Reescrevê-las fora do Laravel seria a segunda
 * cópia de regras que só valem se forem uma.
 *
 * Instância única de graça: o timer não dispara uma unidade `oneshot` que ainda roda.
 */
final class ReiniciarCampanhas extends Command
{
    /** @var string */
    protected $signature = 'prospecta:campanhas-reiniciar';

    /** @var string */
    protected $description = 'Reinicia, em lotes, as Campanhas marcadas no painel';

    public function handle(): int
    {
        $resumo = (new Reinicio())->executar();

        if (array_sum($resumo) > 0) {
            $this->info(sprintf('reiniciadas: %d · com erro: %d', $resumo['reiniciadas'], $resumo['com_erro']));
        }

        // Não-zero quando alguma recusou, para aparecer em `systemctl --failed` além da tela.
        return $resumo['com_erro'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}

<?php

declare(strict_types=1);

namespace App\Worker;

/**
 * Pedido de parada, vindo de sinal.
 *
 * Existe como objeto e não como `bool` por referência num closure porque o worker
 * checa a parada NO MEIO do lote, e precisa checar de verdade: um lote de 20 envios
 * com timeout de 15 s pode levar cinco minutos, e o systemd não deveria esperar
 * isso por um SIGTERM.
 *
 * O `bool` capturado por referência — padrão do `bin/dialer.php`, que só o checa na
 * condição do laço — não serve aqui: a análise estática o vê como sempre `true`
 * dentro do laço, porque a mutação acontece dentro de um manipulador de sinal que
 * ela não pode enxergar. O aviso está certo sobre o que é visível, e trocar por um
 * objeto torna o pedido de parada uma coisa nomeada em vez de um efeito colateral.
 */
final class Interrupcao
{
    private bool $pedida = false;

    public function pedir(): void
    {
        $this->pedida = true;
    }

    /**
     * 🔴 DESPACHA os sinais pendentes e SÓ ENTÃO responde.
     *
     * Despachar aqui dentro não é conveniência: é o que torna impossível checar a
     * parada sem antes dar ao PHP a chance de executar o manipulador. Com o
     * despacho do lado de fora, esquecer uma chamada faz o pedido de parada só ser
     * notado na volta seguinte — que num lote de 20 envios com timeout de 15 s
     * pode ser cinco minutos depois.
     *
     * O `@phpstan-impure` fica verdadeiro por consequência: o método tem efeito
     * real, que é executar manipulador de sinal. Sem ele a análise memoriza o
     * retorno e conclui que a segunda checagem é sempre falsa — e concluiria
     * certo, se o método fosse puro.
     *
     * @phpstan-impure
     */
    public function pedida(): bool
    {
        pcntl_signal_dispatch();

        return $this->pedida;
    }

    /**
     * Dorme em fatias de 1 s. Um `sleep(300)` faria o systemd esperar até cinco
     * minutos por uma parada.
     */
    public function dormir(int $segundos): void
    {
        for ($i = 0; $i < $segundos; $i++) {
            if ($this->pedida()) {
                return;
            }
            sleep(1);
        }
    }
}

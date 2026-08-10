<?php

declare(strict_types=1);

namespace App\Dialer;

/**
 * O que fazer com o Lead depois de uma tentativa de originação.
 *
 * Existe `Aposentar` separado de `Retentar` porque a bateria de chamadas de
 * 2026-08-09 mostrou que os códigos distinguem condição PERMANENTE de
 * TEMPORÁRIA — e antes de medir isso não se sabia.
 */
enum Acao
{
    /** Número não atribuído: NUNCA vai funcionar. Gastar as tentativas
     *  restantes nele é desperdício em toda rodada da Campanha. */
    case Aposentar;

    /** Condição temporária: volta para a Fila de discagem. */
    case Retentar;

    /** Bloqueio DELIBERADO de quem recebe (não perturbe). Retentar em seguida
     *  é inútil e discutível — merece recuo longo. */
    case RecuoLongo;

    /** Atendida: o `fastagi` já gravou o Desfecho. */
    case Finalizado;
}

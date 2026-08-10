<?php

declare(strict_types=1);

namespace App\Fastagi;

/**
 * O Lead desligou e o socket fechou.
 *
 * NÃO é erro fatal. O caso comum do produto é o Lead apertar a tecla de opt-in e
 * desligar no mesmo instante — se o processo morresse aqui, perderíamos
 * justamente o registro de quem pediu contato explicitamente, que é a pior falha
 * possível (ADR-0002).
 *
 * Por isso esta exceção interrompe a FASE 3 e deixa a FASE 4 acontecer.
 */
final class CanalMorto extends \RuntimeException
{
    public function __construct(public readonly string $comando)
    {
        parent::__construct("Canal fechado ao enviar: {$comando}");
    }
}

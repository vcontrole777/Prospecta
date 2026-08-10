{{--
    Estado da Campanha. Vocabulário do domínio, então é NOSSO componente — nenhum
    kit sabe o que "pausada pela proteção" significa (ADR-0008).

    Codificado por PESO e RÓTULO; cor é reforço escasso. Duas das quatro faixas
    recebem tinta, e as duas por um motivo nomeável:
      · ativa   → acento, o único significado do acento é "isto está vivo"
      · pausada → aviso, é o único estado que EXIGE ação do operador; a proteção
                  do tronco desarmou e a lista parou
    Rascunho e concluída são estados de repouso e não competem por atenção.

    🔴 NÃO usar a classe `.rotulo` aqui. Ela define `color: var(--ink-muted)` e
    vive FORA de `@layer`, enquanto as utilidades do Tailwind vivem dentro — CSS
    sem layer vence qualquer layer, então `.rotulo` sobrescreveria `text-primary`
    e todo badge sairia cinza. O tamanho e o tracking vêm dos mesmos tokens por
    valor arbitrário, o que mantém uma fonte de verdade sem herdar a cor.
--}}
@props(['status'])

@php
    /** @var array<int, array{0:string, 1:string}> */
    $mapa = [
        0 => ['rascunho',  'text-base-content/50 border-[var(--rule)]'],
        1 => ['ativa',     'text-primary border-primary font-semibold'],
        2 => ['pausada',   'text-warning border-warning font-semibold'],
        3 => ['concluída', 'text-base-content/70 border-[var(--rule)]'],
    ];

    // Fallback vermelho de propósito: status fora do mapa significa que o código
    // e o banco discordam sobre o domínio. É falha, e falha é o único uso do
    // vermelho neste registro.
    [$rotulo, $tinta] = $mapa[(int) $status] ?? ['status ' . $status, 'text-error border-error font-semibold'];
@endphp

<span
    class="inline-flex items-center border px-1.5 py-px uppercase whitespace-nowrap
           text-[length:var(--text-2xs)] tracking-[var(--tracking-label)] {{ $tinta }}"
>{{ $rotulo }}</span>

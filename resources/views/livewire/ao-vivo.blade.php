{{--
    🔴 O RELÓGIO CORRE NO NAVEGADOR. É a decisão que torna esta tela pagável.

    O servidor manda o instante de início de cada Ligação em epoch, UMA vez por poll
    de 3 s. A duração — o único campo que muda a cada segundo — é calculada aqui, e
    cada tique custa zero requisição e zero renderização no servidor. Ver AoVivo.php.

    `desvio` corrige o relógio DO OPERADOR contra o do servidor. Máquina de escritório
    erra por minutos com frequência, e sem esta correção a tela mostraria duração
    negativa ou inflada — que pareceria defeito do discador, não do relógio.

    🔴 O FORMATO ESTÁ SÓ AQUI, de propósito. Duração formatada em PHP também seria a
    mesma regra escrita em dois lugares, e este projeto já viu o que isso dá. Como o
    contador tem que existir em JS de qualquer forma, o PHP não formata nenhuma.
--}}
<div
    @if ($rodando) wire:poll.3s @endif
    x-data="{
        desvio: {{ $agora }} - Math.floor(Date.now() / 1000),
        agora: {{ $agora }},
        tique() { this.agora = Math.floor(Date.now() / 1000) + this.desvio },
        /* Zerado à esquerda: '2m04s', nunca '2m4s'. É o que de fato alinha a coluna,
           e é a decisão registrada em design/tokens.css junto de `.num`. */
        dur(desde) {
            let s = this.agora - desde
            if (s < 0) s = 0
            const m = Math.floor(s / 60)
            if (m < 1) return s + 's'
            if (m < 60) return m + 'm' + String(s % 60).padStart(2, '0') + 's'
            return Math.floor(m / 60) + 'h' + String(m % 60).padStart(2, '0') + 'm'
        },
    }"
    x-init="setInterval(() => tique(), 1000)"
>
    <div class="flex items-baseline justify-between border-b border-[var(--rule)] pb-3">
        <div class="flex items-baseline gap-3">
            <h1 class="text-lg font-semibold tracking-tight">Ao vivo</h1>
            <span class="rotulo">chamadas no ar agora</span>
        </div>

        <button type="button" wire:click="alternar" class="btn btn-ghost btn-sm">
            {{ $rodando ? 'Congelar' : 'Retomar' }}
        </button>
    </div>

    @unless ($rodando)
        <p class="mt-2 text-sm text-warning">
            Tela congelada — os números abaixo são de quando você congelou.
        </p>
    @endunless

    {{-- 🔴 O AGREGADO VEM PRIMEIRO. A pergunta de quem abre esta tela quase sempre é
         "estou usando o que contratei?", e isso são seis números que não dependem de
         linha nenhuma. A regra do ADR-0008: antes de otimizar a tela densa, ver se a
         pergunta não é agregada. --}}
    <div class="mt-4 grid grid-cols-2 gap-px border border-[var(--rule)] bg-[var(--rule)] sm:grid-cols-4">
        <div class="bg-base-100 p-3">
            <span class="rotulo">No ar</span>
            <p class="mt-1 text-2xl font-semibold">{{ $total }}</p>
        </div>
        <div class="bg-base-100 p-3">
            <span class="rotulo">Contratado</span>
            <p class="mt-1 text-2xl font-semibold">
                {{ $contratado > 0 ? $contratado : '—' }}
                @if ($ocupacao !== null)
                    {{-- Passar de 100% recebe tinta: é o defeito de 26/08/2026 ficando
                         visível em vez de ser deduzido do 503 do tronco. --}}
                    <span class="text-sm font-normal {{ $ocupacao > 100 ? 'text-error' : 'text-base-content/50' }}">
                        {{ $ocupacao }}%
                    </span>
                @endif
            </p>
        </div>
        <div class="bg-base-100 p-3">
            <span class="rotulo">Chamando</span>
            <p class="mt-1 text-2xl font-semibold">{{ $chamando }}</p>
        </div>
        <div class="bg-base-100 p-3">
            <span class="rotulo">Na URA</span>
            {{-- Acento: o único significado do acento neste registro é "isto está
                 vivo", e uma pessoa ouvindo a mensagem é o mais vivo que a tela tem. --}}
            <p class="mt-1 text-2xl font-semibold {{ $naUra > 0 ? 'text-primary' : '' }}">{{ $naUra }}</p>
        </div>
    </div>

    @if ($campanhas !== [])
        <table class="mt-4 w-full border border-[var(--rule)] text-sm">
            <thead>
                <tr class="border-b border-[var(--rule)] text-left">
                    <th class="p-2 font-normal"><span class="rotulo">Campanha</span></th>
                    <th class="p-2 font-normal"><span class="rotulo">Estado</span></th>
                    <th class="p-2 text-right font-normal"><span class="rotulo">No ar</span></th>
                    <th class="p-2 text-right font-normal"><span class="rotulo">Simultâneas</span></th>
                    <th class="p-2 text-right font-normal"><span class="rotulo">Uso</span></th>
                </tr>
            </thead>
            <tbody>
                @foreach ($campanhas as $c)
                    <tr class="border-b border-[var(--rule)] last:border-0">
                        <td class="p-2">{{ $c['nome'] }}</td>
                        <td class="p-2"><x-status-campanha :status="$c['status']" /></td>
                        <td class="num p-2">{{ $c['emVoo'] }}</td>
                        <td class="num p-2 text-base-content/50">{{ $c['teto'] > 0 ? $c['teto'] : '—' }}</td>
                        <td class="num p-2 {{ ($c['percentual'] ?? 0) > 100 ? 'text-error' : 'text-base-content/50' }}">
                            {{ $c['percentual'] !== null ? $c['percentual'] . '%' : '—' }}
                        </td>
                    </tr>

                    @if ($c['status'] === 2 && $c['emVoo'] > 0)
                        {{-- 🔴 Pausar NÃO desliga o que já está no ar. Quem pausou para
                             aliviar o tronco precisa ver que o alívio ainda não chegou,
                             ou pausa a segunda Campanha e a terceira atrás dela. --}}
                        <tr class="border-b border-[var(--rule)] last:border-0">
                            <td colspan="5" class="px-2 pb-2 text-xs text-warning">
                                Pausada, mas ainda com {{ $c['emVoo'] }}
                                {{ $c['emVoo'] === 1 ? 'chamada' : 'chamadas' }} no ar — elas
                                seguem ocupando canal até desligarem sozinhas.
                            </td>
                        </tr>
                    @endif
                @endforeach
            </tbody>
        </table>
    @endif

    @if ($linhas === [])
        <div class="mt-4 border border-[var(--rule)] p-8 text-center text-sm">
            <p class="text-base-content/70">Nenhuma chamada no ar.</p>
            <p class="mt-1 text-base-content/50">
                Ou não há Campanha ativa, ou o discador não está rodando.
            </p>
        </div>
    @else
        <table class="mt-4 w-full border border-[var(--rule)] text-sm">
            <thead>
                <tr class="border-b border-[var(--rule)] text-left">
                    <th class="p-2 font-normal"><span class="rotulo">Telefone</span></th>
                    <th class="p-2 font-normal"><span class="rotulo">Campanha</span></th>
                    <th class="p-2 font-normal"><span class="rotulo">Estado</span></th>
                    <th class="p-2 text-right font-normal"><span class="rotulo">No ar há</span></th>
                    <th class="p-2 text-right font-normal"><span class="rotulo">Falando há</span></th>
                    <th class="p-2 text-right font-normal"><span class="rotulo">Tent.</span></th>
                    <th class="p-2 font-normal"><span class="rotulo">BINA</span></th>
                </tr>
            </thead>
            <tbody>
                @foreach ($linhas as $l)
                    {{-- `wire:key` no id da Ligação: sem ele o morph do Livewire reaproveita
                         a linha errada quando o conjunto muda, e a duração de uma chamada
                         aparece na linha de outra por um instante. --}}
                    <tr wire:key="l{{ $l['id'] }}" class="border-b border-[var(--rule)] last:border-0">
                        <td class="identificador p-2">{{ $l['telefone'] }}</td>
                        <td class="p-2 text-base-content/70">{{ $l['campanha'] }}</td>
                        <td class="p-2">
                            {{-- Estado por PESO e RÓTULO, não por tinta (ADR-0008): só
                                 "na URA" recebe acento, porque é o único em que há
                                 alguém do outro lado ouvindo. --}}
                            <span class="{{ $l['estado'] === 'na URA' ? 'font-semibold text-primary' : 'text-base-content/50' }}">
                                {{ $l['estado'] }}
                            </span>
                        </td>
                        <td class="identificador num p-2" x-text="dur({{ $l['inicio'] }})">—</td>
                        <td class="identificador num p-2 text-base-content/70">
                            @if ($l['atendimento'] !== null)
                                <span x-text="dur({{ $l['atendimento'] }})">—</span>
                            @else
                                <span class="text-base-content/30">—</span>
                            @endif
                        </td>
                        <td class="num p-2 text-base-content/50">{{ $l['tentativa'] }}</td>
                        <td class="identificador p-2 text-base-content/50">{{ $l['bina'] }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        @if ($cortadas > 0)
            {{-- Corte silencioso lê-se como "está tudo aqui". Nunca cortar sem dizer. --}}
            <p class="mt-2 text-sm text-warning">
                Mostrando {{ count($linhas) }} de {{ $total }} — {{ $cortadas }} não
                cabem na tela. Ligação aberta há muito tempo costuma ser linha órfã, de
                chamada cujo desligamento se perdeu.
            </p>
        @endif
    @endif

    {{-- 🔴 O QUE ESTA TELA NÃO MOSTRA, e por quê — para ninguém tentar "consertar".

         AMD, tecla digitada e desfecho NÃO aparecem aqui. Não é omissão: essas colunas
         são escritas por `Fastagi\Banco::gravar()`, que roda UMA vez, no desligamento,
         no mesmo UPDATE que preenche `encerrada_em`. Numa Ligação aberta elas são NULL
         por construção. Uma coluna "AMD" estaria vazia em 100% das linhas e pareceria
         que o classificador parou de funcionar. --}}
</div>

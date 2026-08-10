<div class="max-w-3xl">
    <div class="flex items-baseline justify-between border-b border-[var(--rule)] pb-3">
        <div class="flex items-baseline gap-3">
            <h1 class="text-lg font-semibold tracking-tight">Lista de BINA</h1>
            <span class="rotulo">{{ $ativos }} de {{ $total }} ativos</span>
        </div>
        <a href="{{ route('campanhas') }}" class="rotulo hover:text-primary">Campanhas</a>
    </div>

    {{-- 🔴 O AVISO VEM PRIMEIRO, antes da lista e antes do formulário.
         DDD sem BINA ativa faz a regra `lista` cair no número único da Campanha — ou em
         nenhum CallerID — sem erro nenhum. A Campanha continua discando e o atendimento
         despenca. É a informação que esta tela existe para dar. --}}
    @if ($descobertos !== [])
        <div class="mt-4 border border-warning/50 p-4">
            <p class="text-sm text-warning">
                DDDs com Lead esperando e sem BINA ativa
            </p>
            <p class="mt-1 text-sm text-base-content/70">
                Nestes DDDs a regra <span class="identificador">lista</span> cai no número
                único da Campanha, e se ele estiver vazio a chamada sai sem CallerID —
                que tem atendimento perto de zero. Não gera erro; só cai a taxa.
            </p>

            <div class="mt-3 flex flex-wrap gap-2">
                @foreach ($descobertos as $d)
                    <span class="border border-warning/50 px-2 py-1 text-sm">
                        <span class="identificador text-warning">{{ $d['ddd'] }}</span>
                        <span class="rotulo ml-1">{{ number_format($d['leads'], 0, ',', '.') }} leads</span>
                    </span>
                @endforeach
            </div>
        </div>
    @endif

    {{-- ---------- adicionar ---------- --}}
    <form wire:submit="adicionar" class="mt-6 flex flex-col gap-4 border border-[var(--rule)] p-4">
        <p class="rotulo">Adicionar números</p>

        <x-textarea
            wire:model="entrada"
            rows="5"
            placeholder="5511987654321&#10;11 98765-4322&#10;(62) 9876-5433"
            class="identificador"
        />

        <p class="text-sm text-base-content/60">
            Um por linha, ou separados por vírgula. Aceita máscara e número sem o país —
            a normalização é a mesma da importação de lista, e é ela que faz o DDD casar
            com o destino.
        </p>

        <div>
            <x-button type="submit" label="Adicionar" class="btn-primary" spinner="adicionar" />
        </div>

        @if ($adicionados > 0 || $repetidos > 0 || $recusados !== [])
            <div class="border border-[var(--rule)] p-3 text-sm">
                <span class="text-primary">{{ $adicionados }} adicionados</span>
                @if ($repetidos > 0)
                    {{-- Repetido não é erro: é a unicidade impedindo o mesmo número
                         duas vezes na lista. --}}
                    <span class="ml-3 text-base-content/60">{{ $repetidos }} já estavam</span>
                @endif
                @if ($recusados !== [])
                    <span class="ml-3 text-warning">{{ count($recusados) }} recusados</span>
                    <p class="mt-2 text-base-content/70">
                        Recusados por não formarem telefone brasileiro válido:
                    </p>
                    <ul class="identificador mt-1 flex flex-wrap gap-x-4 text-base-content/60">
                        @foreach ($recusados as $r)
                            <li>{{ $r }}</li>
                        @endforeach
                    </ul>
                @endif
            </div>
        @endif
    </form>

    {{-- ---------- por DDD ---------- --}}
    @if ($porDdd === [])
        <div class="mt-6 border border-[var(--rule)] p-8 text-center text-sm">
            <p class="text-base-content/70">Nenhum número cadastrado.</p>
            <p class="mt-2 text-base-content/50">
                Sem lista, a regra de BINA <span class="identificador">lista</span> não
                tem de onde sortear.
            </p>
        </div>
    @else
        <div class="mt-6 flex flex-col gap-3">
            @foreach ($porDdd as $g)
                <div class="border border-[var(--rule)] p-4">
                    <div class="flex flex-wrap items-baseline justify-between gap-2 border-b border-[var(--rule)] pb-2">
                        <div class="flex items-baseline gap-3">
                            <span class="identificador text-base font-semibold">DDD {{ $g['ddd'] }}</span>
                            <span class="rotulo {{ $g['ativos'] === 0 ? 'text-warning' : '' }}">
                                {{ $g['ativos'] }} de {{ $g['total'] }} ativos
                            </span>
                        </div>

                        <div class="flex gap-1">
                            @if ($g['ativos'] < $g['total'])
                                <button wire:click="ativarDdd('{{ $g['ddd'] }}')"
                                        class="btn btn-ghost btn-xs text-primary">ativar todos</button>
                            @endif
                            @if ($g['ativos'] > 0)
                                <button wire:click="desativarDdd('{{ $g['ddd'] }}')"
                                        class="btn btn-ghost btn-xs">desativar todos</button>
                            @endif
                        </div>
                    </div>

                    <div class="mt-2 flex flex-col">
                        @foreach ($g['numeros'] as $n)
                            <div class="flex items-center justify-between gap-3 border-b border-[var(--rule)] py-1 last:border-b-0">
                                <span class="identificador text-sm {{ $n['ativo'] ? '' : 'text-base-content/40 line-through' }}">
                                    {{ $n['numero'] }}
                                </span>
                                <div class="flex gap-1 whitespace-nowrap">
                                    <button wire:click="alternar({{ $n['id'] }})" class="btn btn-ghost btn-xs">
                                        {{ $n['ativo'] ? 'desativar' : 'ativar' }}
                                    </button>
                                    <button wire:click="remover({{ $n['id'] }})"
                                            wire:confirm="Remover este número da lista?"
                                            class="btn btn-ghost btn-xs">remover</button>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endforeach
        </div>

        {{-- Por que aqui se remove e no áudio não. A assimetria é real e vale explicar,
             senão parece inconsistência. --}}
        <p class="mt-4 text-sm text-base-content/50">
            Remover é seguro: a Ligação guarda qual BINA saiu como texto, então o
            histórico não se perde. Áudio é diferente — lá a Campanha aponta por
            identificador, e por isso só se desativa.
        </p>
    @endif
</div>

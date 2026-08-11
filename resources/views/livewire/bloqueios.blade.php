<div class="max-w-4xl">
    <div class="flex items-baseline justify-between border-b border-[var(--rule)] pb-3">
        <div class="flex items-baseline gap-3">
            <h1 class="text-lg font-semibold tracking-tight">Lista de bloqueio</h1>
            <span class="rotulo">{{ number_format($total, 0, ',', '.') }} números</span>
        </div>
        <a href="{{ route('campanhas') }}" class="rotulo hover:text-primary">Campanhas</a>
    </div>

    @if (session('recado'))
        <div class="mt-4 border border-primary/50 p-3 text-sm text-primary">{{ session('recado') }}</div>
    @endif

    {{-- ---------- o que a lista é ---------- --}}
    <p class="mt-4 text-sm text-base-content/70">
        A lista é <strong>global</strong>: vale para toda Campanha, atual e futura. Quem
        pediu para não ser contatado pediu ao negócio, não à Campanha — aplicar por
        Campanha faria a próxima voltar a ligar.
    </p>

    <div class="mt-4 grid gap-px bg-[var(--rule)] sm:grid-cols-2">
        @foreach (\App\Livewire\Bloqueios::ORIGENS as $chave => $rotulo)
            <div class="bg-[var(--bg-surface)] p-4">
                <div class="rotulo">{{ $rotulo }}</div>
                <div class="num mt-1 text-2xl">{{ number_format($porOrigem[$chave] ?? 0, 0, ',', '.') }}</div>
            </div>
        @endforeach
    </div>

    {{-- ---------- registrar à mão ---------- --}}
    <div class="mt-6 border border-[var(--rule)] p-4">
        <h2 class="text-sm font-semibold">Registrar um pedido</h2>
        <p class="mt-1 text-sm text-base-content/70">
            {{-- É o caso que faltava, e o mais comum: a pessoa liga de volta, ou fala com
                 alguém, e pede para não ser mais chamada. A tecla 9 e o "SAIR" no
                 WhatsApp já entravam sozinhos; este pedido dependia de alguém lembrar. --}}
            Para quem pediu por fora da chamada — ligou de volta, mandou mensagem, falou
            com alguém. A tecla 9 na URA e o “SAIR” no WhatsApp já entram sozinhos.
        </p>

        <form wire:submit="adicionar" class="mt-3">
            <textarea wire:model="entrada" rows="4"
                      placeholder="5511955554444&#10;(11) 95555-3333"
                      class="identificador w-full border border-[var(--rule)] bg-[var(--bg-surface)] p-2 text-sm"></textarea>
            @error('entrada')
                <p class="mt-1 text-sm text-error">{{ $message }}</p>
            @enderror

            <div class="mt-2 flex items-center justify-between">
                <span class="rotulo">
                    {{-- Dito porque o operador vai colar do jeito que tem. Se a
                         normalização não fosse a mesma da importação, o bloqueio
                         apareceria na tela e não valeria na discagem. --}}
                    aceita com ou sem máscara, um por linha ou por vírgula
                </span>
                <button type="submit" class="btn btn-sm btn-primary">Bloquear</button>
            </div>
        </form>

        @if ($adicionados > 0 || $repetidos > 0 || $recusados !== [])
            <div class="mt-3 border-t border-[var(--rule)] pt-3 text-sm">
                <p>
                    <span class="text-primary">{{ $adicionados }}</span> bloqueados,
                    <span class="text-base-content/70">{{ $repetidos }}</span> já estavam na lista.
                </p>

                @if ($recusados !== [])
                    {{-- Recusado é MOSTRADO, nunca contado em silêncio. Número que não
                         virou telefone válido não entrou — e se o operador não vê qual,
                         ele acha que bloqueou alguém que continuará sendo chamado. --}}
                    <p class="mt-2 text-warning">Não entraram, por não serem telefone válido:</p>
                    <p class="identificador mt-1 text-xs text-warning/80">{{ implode(' · ', $recusados) }}</p>
                @endif
            </div>
        @endif

        <p class="mt-3 text-xs text-base-content/50">
            {{-- Honesto sobre o momento em que passa a valer. O dialer confere o bloqueio
                 ao reivindicar o Lead, ANTES de originar, e o aposenta sem gastar
                 ligação. Chamada já em curso segue até o fim: não há como cancelá-la sem
                 desligar na cara de quem atendeu. --}}
            O dialer confere a lista antes de cada chamada e aposenta o Lead sem gastar
            ligação. Chamada já em andamento segue até o fim.
        </p>
    </div>

    {{-- ---------- consultar ---------- --}}
    <div class="mt-6 flex flex-wrap items-end gap-4">
        <label class="block">
            <span class="rotulo">Procurar número</span>
            <input wire:model.live.debounce.300ms="busca" type="text" placeholder="98765"
                   class="identificador mt-1 block border border-[var(--rule)] bg-[var(--bg-surface)] px-2 py-1 text-sm">
        </label>

        <label class="block">
            <span class="rotulo">Origem</span>
            <select wire:model.live="origem"
                    class="mt-1 block border border-[var(--rule)] bg-[var(--bg-surface)] px-2 py-1 text-sm">
                <option value="">todas</option>
                @foreach (\App\Livewire\Bloqueios::ORIGENS as $chave => $rotulo)
                    <option value="{{ $chave }}">{{ $rotulo }}</option>
                @endforeach
            </select>
        </label>
    </div>

    {{-- ---------- a lista ---------- --}}
    @if ($bloqueios->total() === 0)
        <div class="mt-4 border border-[var(--rule)] p-8 text-center text-sm">
            <p class="text-base-content/70">
                {{ $busca !== '' || $origem !== '' ? 'Nenhum número com esse filtro.' : 'A lista está vazia.' }}
            </p>
            @if ($busca !== '')
                {{-- A resposta explícita para a pergunta que a busca existe para
                     responder. "Nenhum resultado" é ambíguo entre "não está bloqueado" e
                     "procurei errado". --}}
                <p class="mt-2 text-base-content/50">Este número não está bloqueado.</p>
            @endif
        </div>
    @else
        <div class="mt-4 overflow-x-auto border border-[var(--rule)]">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-[var(--rule)] bg-[var(--bg-raised)]">
                        <th class="p-2 text-left"><span class="rotulo">Número</span></th>
                        <th class="p-2 text-left"><span class="rotulo">Origem</span></th>
                        <th class="p-2 text-left"><span class="rotulo">Quando</span></th>
                        <th class="p-2 text-left"><span class="rotulo">Veio de</span></th>
                        <th class="p-2"></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($bloqueios as $b)
                        <tr class="border-b border-[var(--rule)] last:border-b-0 hover:bg-[var(--bg-hover)]">
                            <td class="identificador p-2">{{ $b->telefone }}</td>
                            <td class="p-2">
                                @if ($b->origem === 'opt_out')
                                    {{-- Origem em destaque porque ela muda o peso de
                                         desbloquear: `opt_out` é pedido explícito de uma
                                         pessoa, `manual` pode ser dígito errado numa
                                         colagem. --}}
                                    <span class="text-warning">pediu para sair</span>
                                @else
                                    <span class="text-base-content/70">à mão</span>
                                @endif
                            </td>
                            <td class="identificador p-2 text-xs">{{ substr((string) $b->criado_em, 0, 16) }}</td>
                            <td class="p-2 text-xs">
                                @if ($b->campanha !== null)
                                    <span class="text-base-content/70">{{ $b->campanha }}</span>
                                    @if ($b->ligacao_id !== null)
                                        <span class="rotulo ml-1">lig. {{ $b->ligacao_id }}</span>
                                    @endif
                                @else
                                    <span class="text-base-content/40">—</span>
                                @endif
                            </td>
                            <td class="p-2 text-right">
                                {{-- A confirmação diz o que acontece, não "tem certeza?".
                                     Para `opt_out` ela nomeia o que se está desfazendo:
                                     um pedido de uma pessoa de verdade. --}}
                                <button wire:click="remover('{{ $b->telefone }}')"
                                        wire:confirm="{{ $b->origem === 'opt_out'
                                            ? 'Este número PEDIU para não ser contatado. Tirar da lista permite ligar de novo. Confirma?'
                                            : 'Tirar ' . $b->telefone . ' da lista de bloqueio?' }}"
                                        class="btn btn-ghost btn-xs">desbloquear</button>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="mt-4">{{ $bloqueios->links() }}</div>
    @endif
</div>

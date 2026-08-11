{{--
    `wire:poll.2s` sobre um AGREGADO, não sobre linhas de Ligação.

    2 s e não 1 s porque nada aqui muda mais rápido que isso de forma útil: o
    operador está lendo totais, não perseguindo um canal. E o custo é fixo — uma
    linha por Campanha, independente de serem 10 ou 200 canais contratados. É a
    razão pela qual esta tela existe antes da tabela ao vivo (ver Campanhas.php).
--}}
<div wire:poll.2s>
    <div class="flex items-baseline justify-between border-b border-[var(--rule)] pb-3">
        <div class="flex items-baseline gap-3">
            <h1 class="text-lg font-semibold tracking-tight">Campanhas</h1>
            <span class="rotulo">atualiza a cada 2s</span>
        </div>
        <a href="{{ route('campanha.nova') }}" class="btn btn-primary btn-sm">Nova Campanha</a>
    </div>

    {{-- A recusa de iniciar diz O QUE falta, não "erro". Uma Campanha que não pode
         rodar tem causa nomeável, e nomeá-la é a diferença entre o operador
         consertar e o operador abrir ticket. --}}
    @if (session('recusa'))
        <div class="mt-4 border border-warning/50 p-3 text-sm text-warning">{{ session('recusa') }}</div>
    @endif
    @if (session('recado'))
        <div class="mt-4 border border-[var(--rule)] p-3 text-sm text-base-content/70">{{ session('recado') }}</div>
    @endif

    @if ($campanhas === [])
        <div class="mt-10 border border-[var(--rule)] p-8 text-center">
            <p class="text-base-content/70">Nenhuma Campanha.</p>
            {{-- Honesto sobre o estado do produto: sem tela de criação, dizer
                 "crie uma Campanha" mandaria o operador procurar um botão que
                 não existe. --}}
            <p class="mt-2 text-sm text-base-content/50">
                Crie a primeira e depois importe a lista de Leads.
            </p>
            <a href="{{ route('campanha.nova') }}" class="btn btn-primary btn-sm mt-4">Nova Campanha</a>
        </div>
    @else
        <div class="mt-4 overflow-x-auto">
            <table class="w-full border-collapse text-sm">
                <thead>
                    <tr class="border-b border-[var(--rule-strong)] text-left">
                        <th class="py-2 pr-4"><span class="rotulo">Campanha</span></th>
                        <th class="py-2 pr-4"><span class="rotulo">Estado</span></th>
                        <th class="py-2 pr-4"><span class="rotulo">Progresso</span></th>
                        <th class="py-2 pr-4 text-right"><span class="rotulo">Em voo</span></th>
                        <th class="py-2 pr-4 text-right"><span class="rotulo">Discadas</span></th>
                        <th class="py-2 pr-4 text-right"><span class="rotulo">Atendidas</span></th>
                        <th class="py-2 pr-4 text-right"><span class="rotulo">Taxa</span></th>
                        <th class="py-2 pr-4 text-right"><span class="rotulo">Opt-in</span></th>
                        <th class="py-2 pr-4 text-right"><span class="rotulo">Erros</span></th>
                        <th class="py-2 text-right"><span class="rotulo">Ações</span></th>
                    </tr>
                </thead>

                <tbody>
                    @foreach ($campanhas as $c)
                        <tr class="border-b border-[var(--rule)] hover:bg-[var(--bg-hover)]">
                            <td class="py-2.5 pr-4">
                                <div class="font-medium">{{ $c['nome'] }}</div>
                                <div class="rotulo mt-0.5">
                                    {{-- Rodada e tentativas NÃO são uma fração.
                                         Rodada conta quantas vezes a Fila foi
                                         reaberta; tentativas é o teto por Lead.
                                         Escrito como "5/2" a tela dizia rodada 5
                                         de no máximo 2, que não quer dizer nada. --}}
                                    {{ $c['tipo'] === 'opt_in' ? 'opt-in' : 'captura' }}
                                    · bina {{ $c['bina_regra'] ?: 'do fornecedor' }}
                                    · rodada {{ $c['rodada'] }}
                                    · máx {{ $c['max_tentativas'] }} tent.
                                </div>
                            </td>

                            <td class="py-2.5 pr-4">
                                <x-status-campanha :status="$c['status']" />
                            </td>

                            <td class="py-2.5 pr-4">
                                <div class="identificador text-xs whitespace-nowrap">
                                    {{ $c['finalizados'] }}/{{ $c['leads'] }}
                                </div>
                                {{-- Barra fina em vez de componente de progresso:
                                     o registro é régua e espaço, não volume. --}}
                                <div class="mt-1 h-px w-28 bg-[var(--rule)]">
                                    <div
                                        class="h-px bg-primary"
                                        style="width: {{ $c['leads'] > 0 ? round($c['finalizados'] / $c['leads'] * 100) : 0 }}%"
                                    ></div>
                                </div>
                            </td>

                            {{-- `em voo` é o único número verdadeiramente ao vivo
                                 aqui, então recebe o acento quando não é zero. --}}
                            <td class="num py-2.5 pr-4 {{ $c['em_voo'] > 0 ? 'text-primary font-semibold' : 'text-base-content/40' }}">
                                {{ $c['em_voo'] }}
                            </td>

                            <td class="num py-2.5 pr-4">{{ $c['discadas'] }}</td>
                            <td class="num py-2.5 pr-4">{{ $c['atendidas'] }}</td>

                            {{-- `null` é "ainda não discou", diferente de 0%. Um
                                 zero aqui numa Campanha nova diria que a lista é
                                 ruim antes de existir qualquer chamada. --}}
                            <td class="num py-2.5 pr-4 {{ $c['taxa'] === null ? 'text-base-content/40' : '' }}">
                                {{ $c['taxa'] === null ? '—' : $c['taxa'] . '%' }}
                            </td>

                            <td class="num py-2.5 pr-4 {{ $c['opt_in'] > 0 ? 'text-primary font-semibold' : 'text-base-content/40' }}">
                                {{ $c['opt_in'] }}
                            </td>

                            {{-- Vermelho SÓ quando há erro. Zero em vermelho
                                 gastaria o significado da cor na tabela toda. --}}
                            <td class="num py-2.5 pr-4 {{ $c['erros'] > 0 ? 'text-error font-semibold' : 'text-base-content/40' }}">
                                {{ $c['erros'] }}
                            </td>

                            <td class="py-2.5 text-right whitespace-nowrap">
                                {{-- Só as transições que o dialer respeita:
                                     rascunho e pausada podem ir ao ar; no ar pode
                                     pausar; concluída não volta. --}}
                                @if ($c['status'] === 0 || $c['status'] === 2)
                                    <button wire:click="iniciar({{ $c['id'] }})"
                                            wire:confirm="Pôr a Campanha no ar e começar a discar?"
                                            class="btn btn-ghost btn-xs text-primary">iniciar</button>
                                @elseif ($c['status'] === 1)
                                    <button wire:click="pausar({{ $c['id'] }})"
                                            class="btn btn-ghost btn-xs">pausar</button>
                                @endif

                                {{-- Importar aparece com destaque enquanto a
                                     Campanha não tem Lead: é a peça que falta para
                                     ela poder ir ao ar, e a guarda de iniciar não
                                     deixa passar sem ela. --}}
                                <a href="{{ route('campanha.importar', $c['id']) }}"
                                   class="btn btn-ghost btn-xs {{ $c['leads'] === 0 ? 'text-primary' : '' }}">importar</a>

                                {{-- Resultados só depois da primeira ligação. Antes disso o
                                     link levaria a uma tabela vazia e a contadores zerados,
                                     o que não informa nada e ensina a não clicar. --}}
                                @if ($c['discadas'] > 0)
                                    <a href="{{ route('campanha.resultados', $c['id']) }}"
                                       class="btn btn-ghost btn-xs">resultados</a>
                                @endif

                                <a href="{{ route('campanha.editar', $c['id']) }}"
                                   class="btn btn-ghost btn-xs">editar</a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>

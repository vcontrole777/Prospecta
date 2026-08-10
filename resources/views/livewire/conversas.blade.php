{{-- `wire:poll.10s` e não 2 s: conversa não é monitor ao vivo. O operador está lendo,
     e uma tela que se redesenha a cada 2 s enquanto ele lê é hostil. --}}
<div wire:poll.10s>
    <div class="flex items-baseline justify-between border-b border-[var(--rule)] pb-3">
        <div class="flex items-baseline gap-3">
            <h1 class="text-lg font-semibold tracking-tight">Conversas</h1>
            <span class="rotulo">atualiza a cada 10s</span>
        </div>
        <a href="{{ route('campanhas') }}" class="rotulo hover:text-primary">Campanhas</a>
    </div>

    @if ($conversas === [])
        <div class="mt-10 border border-[var(--rule)] p-8 text-center text-sm">
            <p class="text-base-content/70">Nenhuma conversa.</p>
            <p class="mt-2 text-base-content/50">
                Elas aparecem quando um Lead responde no WhatsApp. Isso exige o webhook
                cadastrado no painel da Meta, com HTTPS válido — a Meta recusa
                certificado autoassinado.
            </p>
        </div>
    @else
        <div class="mt-4 grid gap-6 lg:grid-cols-[22rem_1fr]">

            {{-- ---------- lista ---------- --}}
            <div class="flex flex-col border border-[var(--rule)]">
                @foreach ($conversas as $c)
                    <button
                        wire:click="selecionar({{ $c['id'] }})"
                        class="border-b border-[var(--rule)] p-3 text-left last:border-b-0
                               hover:bg-[var(--bg-hover)]
                               {{ $selecionada === $c['id'] ? 'bg-[var(--bg-raised)]' : '' }}"
                    >
                        <div class="flex items-baseline justify-between gap-2">
                            <span class="identificador text-sm">{{ $c['telefone'] }}</span>

                            {{-- A janela é a informação que decide se dá para responder,
                                 então ela tem o destaque. Acabando ganha aviso; fechada
                                 fica apagada, porque não há ação possível ali. --}}
                            @if ($c['janela_aberta'])
                                <span class="rotulo {{ $c['janela_acabando'] ? 'text-warning' : 'text-primary' }}">
                                    {{ $c['janela_restante'] }}
                                </span>
                            @else
                                <span class="rotulo">fechada</span>
                            @endif
                        </div>

                        <p class="mt-1 truncate text-sm {{ $c['ultima_direcao'] === 'entrada' ? 'text-base-content' : 'text-base-content/50' }}">
                            {{ $c['ultima_direcao'] === 'saida' ? '→ ' : '' }}{{ $c['ultimo_texto'] ?: '—' }}
                        </p>

                        <div class="rotulo mt-1 flex gap-3">
                            <span>{{ $c['mensagens'] }} msg</span>
                            @if ($c['lead_id'] !== null)<span>lead #{{ $c['lead_id'] }}</span>@endif
                            @if ($c['bloqueado'])<span class="text-error">bloqueado</span>@endif
                        </div>
                    </button>
                @endforeach
            </div>

            {{-- ---------- detalhe ---------- --}}
            <div>
                @if ($detalhe === null)
                    <div class="border border-[var(--rule)] p-8 text-center text-sm text-base-content/50">
                        Escolha uma conversa.
                    </div>
                @else
                    <div class="border border-[var(--rule)] p-4">
                        <div class="flex flex-wrap items-baseline justify-between gap-2 border-b border-[var(--rule)] pb-3">
                            <span class="identificador">{{ $detalhe['telefone'] }}</span>

                            <div class="flex items-center gap-3 text-sm">
                                @if ($detalhe['bloqueado'])
                                    {{-- Vermelho porque muda o que se pode fazer: quem
                                         está na lista não é mais discado. --}}
                                    <span class="text-error">na lista de bloqueio</span>
                                @endif

                                @if ($detalhe['janela_aberta'])
                                    <span class="{{ $detalhe['janela_acabando'] ? 'text-warning' : 'text-primary' }}">
                                        janela aberta — restam {{ $detalhe['janela_restante'] }}
                                    </span>
                                @else
                                    <span class="text-base-content/60">janela fechada</span>
                                @endif
                            </div>
                        </div>

                        {{-- Explica a consequência ECONÔMICA, que é o motivo de a janela
                             estar na tela. Sem isto, "janela aberta" é jargão. --}}
                        <p class="mt-3 text-sm text-base-content/60">
                            @if ($detalhe['janela_aberta'])
                                Dentro da janela de 24 h, texto livre é ilimitado e não
                                custa por mensagem. Ela reabre a cada mensagem que a
                                pessoa manda.
                            @else
                                Fora da janela, só template aprovado — e template é pago.
                                A janela reabre quando a pessoa mandar qualquer mensagem,
                                inclusive tocando um botão.
                            @endif
                        </p>

                        <div class="mt-4 flex flex-col gap-3">
                            @foreach ($mensagens as $m)
                                <div class="flex {{ $m['direcao'] === 'entrada' ? 'justify-start' : 'justify-end' }}">
                                    <div class="max-w-[85%] border p-3 text-sm
                                                {{ $m['direcao'] === 'entrada'
                                                    ? 'border-[var(--rule)]'
                                                    : 'border-[var(--rule-strong)] bg-[var(--bg-raised)]' }}">
                                        @if ($m['texto'] !== '')
                                            <p class="whitespace-pre-wrap">{{ $m['texto'] }}</p>
                                        @else
                                            <p class="text-base-content/50">({{ $m['tipo'] ?: 'sem texto' }})</p>
                                        @endif

                                        <div class="rotulo mt-2 flex flex-wrap gap-2">
                                            <span>{{ $m['quando'] }}</span>
                                            @if ($m['template'] !== '')<span>template {{ $m['template'] }}</span>@endif

                                            @if ($m['direcao'] === 'saida' && $m['status'] !== '')
                                                {{-- Os CINCO estados. Só `falhou` recebe
                                                     tinta: o resto é progresso normal. --}}
                                                <span class="{{ $m['status'] === 'falhou' ? 'text-error' : '' }}">
                                                    {{ $m['status'] }}
                                                </span>
                                            @endif

                                            @if ($m['erro'] !== null)
                                                <span class="text-error identificador">erro {{ $m['erro'] }}</span>
                                            @endif
                                        </div>
                                    </div>
                                </div>
                            @endforeach
                        </div>

                        {{-- Declara o limite em vez de deixar o operador procurar o campo
                             de resposta. Meia-boca aqui seria pior: ele escreveria e a
                             mensagem sumiria. --}}
                        <div class="mt-6 border-t border-[var(--rule)] pt-4 text-sm text-base-content/60">
                            Esta tela é somente leitura. Responder exige um remetente de
                            texto livre no worker, que ainda não existe.
                        </div>
                    </div>
                @endif
            </div>
        </div>
    @endif
</div>

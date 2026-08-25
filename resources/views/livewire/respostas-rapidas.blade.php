<div class="max-w-3xl">
    <div class="flex items-baseline justify-between border-b border-[var(--rule)] pb-3">
        <h1 class="text-lg font-semibold tracking-tight">Respostas rápidas</h1>
        <a href="{{ route('conversas') }}" class="rotulo hover:text-primary">Conversas</a>
    </div>

    @if (session('recado'))
        <div class="mt-4 border border-[var(--rule)] p-3 text-sm text-base-content/70">{{ session('recado') }}</div>
    @endif

    <form wire:submit="salvar" class="mt-6 flex flex-col gap-4 border border-[var(--rule)] p-4">
        <p class="rotulo">{{ $editando === null ? 'Nova resposta' : 'Editando' }}</p>

        <x-input label="Título" wire:model="titulo" placeholder="Confirmar interesse" />
        <x-textarea label="Mensagem" wire:model="texto" rows="4"
                    placeholder="Olá {Nome}, tudo bem? Consegui localizar sua consulta." />

        {{-- O Placeholder é a mesma sintaxe do resto do produto. Dizer aqui o que
             acontece quando a coluna falta evita o operador descobrir na hora do uso. --}}
        <p class="text-sm text-base-content/60">
            Pode usar <span class="identificador">{Nome da Coluna}</span> para puxar dado do
            Lead — a mesma escrita da Mensagem da Campanha. Ao usar a resposta, se a coluna
            não existir naquela conversa, a tela avisa e não preenche: melhor que sair
            <span class="identificador">"Olá , tudo bem?"</span> para uma pessoa real.
        </p>
        <p class="text-sm text-base-content/60">
            Texto sem placeholder funciona em qualquer conversa, inclusive nas de número
            desconhecido, que não têm Lead.
        </p>

        <div class="border-t border-[var(--rule)] pt-4">
            <p class="rotulo">Botão de link (opcional)</p>
            <div class="mt-2 grid gap-3 sm:grid-cols-2">
                <x-input label="Texto do botão" wire:model="botao_texto" placeholder="Ver meus pontos" />
                <x-input label="Endereço" wire:model="botao_url" placeholder="https://…" />
            </div>
            <p class="mt-2 text-sm text-base-content/60">
                Vira um botão destacado abaixo da mensagem. O WhatsApp mostra até
                <strong>20 caracteres</strong> dentro dele, e a Meta só aceita
                <span class="identificador">https://</span>. Preencha os dois ou nenhum.
            </p>
            <p class="mt-1 text-sm text-base-content/60">
                O toque no botão <strong>não volta para cá</strong> — abre o navegador da pessoa
                e o WhatsApp não avisa ninguém. Ele não renova a janela de 24 h nem conta como
                resposta.
            </p>
        </div>

        <div class="flex gap-2">
            <x-button type="submit" label="{{ $editando === null ? 'Cadastrar' : 'Salvar' }}"
                      class="btn-primary" spinner="salvar" />
            @if ($editando !== null)
                <x-button label="Cancelar" wire:click="cancelar" class="btn-ghost" />
            @endif
        </div>
    </form>

    @if ($respostas === [])
        <div class="mt-6 border border-[var(--rule)] p-8 text-center text-sm">
            <p class="text-base-content/70">Nenhuma resposta rápida cadastrada.</p>
            <p class="mt-2 text-base-content/50">
                Elas aparecem como botões acima da caixa de resposta, nas Conversas.
            </p>
        </div>
    @else
        <div class="mt-6 flex flex-col gap-3">
            @foreach ($respostas as $r)
                <div class="border border-[var(--rule)] p-3 {{ $r['ativo'] ? '' : 'opacity-50' }}">
                    <div class="flex items-baseline justify-between gap-3">
                        <p class="font-medium">
                            {{ $r['titulo'] }}
                            @unless ($r['ativo'])
                                <span class="rotulo">— desativada</span>
                            @endunless
                        </p>

                        <div class="flex shrink-0 gap-3 text-xs">
                            <button type="button" wire:click="subir({{ $r['id'] }})"
                                    class="hover:text-primary">Subir</button>
                            <button type="button" wire:click="editar({{ $r['id'] }})"
                                    class="hover:text-primary">Editar</button>
                            <button type="button" wire:click="alternar({{ $r['id'] }})"
                                    class="hover:text-primary">{{ $r['ativo'] ? 'Desativar' : 'Reativar' }}</button>
                            <button type="button" wire:click="apagar({{ $r['id'] }})"
                                    wire:confirm="Apagar esta resposta rápida?"
                                    class="text-error hover:underline">Apagar</button>
                        </div>
                    </div>

                    <p class="mt-2 whitespace-pre-line text-sm text-base-content/70">{{ $r['texto'] }}</p>

                    @if ($r['botao_texto'] !== '')
                        <p class="rotulo mt-2">
                            Botão: <span class="identificador">{{ $r['botao_texto'] }}</span>
                            → <span class="identificador">{{ $r['botao_url'] }}</span>
                        </p>
                    @endif

                    @if ($r['colunas'] !== [])
                        <p class="rotulo mt-2">
                            Exige do Lead:
                            @foreach ($r['colunas'] as $coluna)
                                <span class="identificador">{{ $coluna }}</span>@if (! $loop->last), @endif
                            @endforeach
                        </p>
                    @endif
                </div>
            @endforeach
        </div>
    @endif
</div>

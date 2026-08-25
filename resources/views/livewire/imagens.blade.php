<div class="max-w-3xl">
    <div class="flex items-baseline justify-between border-b border-[var(--rule)] pb-3">
        <h1 class="text-lg font-semibold tracking-tight">Imagens</h1>
        <a href="{{ route('campanhas') }}" class="rotulo hover:text-primary">Campanhas</a>
    </div>

    @if (session('recado'))
        <div class="mt-4 border border-[var(--rule)] p-3 text-sm text-base-content/70">{{ session('recado') }}</div>
    @endif

    <form wire:submit="salvar" class="mt-6 flex flex-col gap-4 border border-[var(--rule)] p-4">
        <p class="rotulo">Nova imagem</p>

        <x-input label="Nome" wire:model="nome" placeholder="Banner Livelo — agosto" />
        <x-file label="Arquivo" wire:model="arquivo" accept=".jpg,.jpeg,.png" />

        {{-- Para que serve, e a única condição que importa: a imagem fica acessível
             por uma URL sem login, porque quem a busca é a Meta. Dizer isso aqui é o
             que impede alguém subir documento interno achando que é área privada. --}}
        <p class="text-sm text-base-content/60">
            Usada no cabeçalho de template que exige imagem. JPG ou PNG, até 5 MB — que
            é o teto da Meta.
        </p>
        <p class="text-sm text-base-content/60">
            <strong>A imagem fica acessível por um endereço sem login</strong>, porque quem
            a busca para montar a mensagem é a Meta. O endereço não é adivinhável, mas
            quem tiver o link vê a imagem. Não suba aqui nada que não possa sair.
        </p>

        <div>
            <x-button type="submit" label="Cadastrar" class="btn-primary" spinner="salvar" />
        </div>
    </form>

    @if ($imagens === [])
        <div class="mt-6 border border-[var(--rule)] p-8 text-center text-sm">
            <p class="text-base-content/70">Nenhuma imagem cadastrada.</p>
            <p class="mt-2 text-base-content/50">
                Template com cabeçalho de imagem não consegue ser enviado sem uma delas —
                a Meta recusa o disparo inteiro.
            </p>
        </div>
    @else
        <div class="mt-6 grid grid-cols-2 gap-4 sm:grid-cols-3">
            @foreach ($imagens as $i)
                <div class="border border-[var(--rule)] p-3">
                    <img src="{{ $i['url'] }}" alt="{{ $i['nome'] }}"
                         class="mb-2 h-32 w-full object-contain" />
                    <p class="truncate text-sm font-medium" title="{{ $i['nome'] }}">{{ $i['nome'] }}</p>
                    <p class="rotulo mt-1">{{ $i['kb'] }} KB</p>

                    @if ($i['usada'] > 0)
                        <p class="mt-1 text-xs text-base-content/60">
                            Em uso por {{ $i['usada'] }} Campanha{{ $i['usada'] > 1 ? 's' : '' }}
                        </p>
                    @endif

                    <button type="button"
                            wire:click="apagar({{ $i['id'] }})"
                            wire:confirm="Apagar esta imagem? Campanha que a usa fica sem cabeçalho e para de enviar até você escolher outra."
                            class="mt-2 text-xs text-error hover:underline">
                        Apagar
                    </button>
                </div>
            @endforeach
        </div>
    @endif
</div>

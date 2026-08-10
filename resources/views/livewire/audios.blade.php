<div class="max-w-3xl">
    <div class="flex items-baseline justify-between border-b border-[var(--rule)] pb-3">
        <h1 class="text-lg font-semibold tracking-tight">Áudios</h1>
        <a href="{{ route('campanhas') }}" class="rotulo hover:text-primary">Campanhas</a>
    </div>

    @if (session('recado'))
        <div class="mt-4 border border-[var(--rule)] p-3 text-sm text-base-content/70">{{ session('recado') }}</div>
    @endif

    <form wire:submit="salvar" class="mt-6 flex flex-col gap-4 border border-[var(--rule)] p-4">
        <p class="rotulo">Novo áudio</p>

        <x-input label="Nome" wire:model="nome" placeholder="Mensagem — consórcio agosto" />
        <x-file label="Arquivo" wire:model="arquivo" accept=".wav,.mp3,.ogg,.m4a,.aac,.flac" />

        {{-- O que acontece com o arquivo, dito sem jargão. O operador precisa saber
             que não é upload direto: há conversão, e ela pode recusar. --}}
        <p class="text-sm text-base-content/60">
            O arquivo é convertido para o formato do PBX — 8 kHz, mono, A-law — e o
            resultado é conferido. Se a conversão sair fora do formato, o áudio é
            recusado com o motivo, em vez de ser aceito e sair silêncio na chamada.
        </p>

        <div>
            <x-button type="submit" label="Cadastrar" class="btn-primary" spinner="salvar" />
        </div>

        <div wire:loading wire:target="salvar" class="text-sm text-primary">Convertendo e conferindo…</div>
    </form>

    @if ($audios === [])
        <div class="mt-6 border border-[var(--rule)] p-8 text-center text-sm">
            <p class="text-base-content/70">Nenhum áudio cadastrado.</p>
            <p class="mt-2 text-base-content/50">
                Sem áudio de mensagem uma Campanha não pode ir ao ar: a chamada sairia muda.
            </p>
        </div>
    @else
        <div class="mt-6 overflow-x-auto">
            <table class="w-full border-collapse text-sm">
                <thead>
                    <tr class="border-b border-[var(--rule-strong)] text-left">
                        <th class="py-2 pr-4"><span class="rotulo">Áudio</span></th>
                        <th class="py-2 pr-4 text-right"><span class="rotulo">Duração</span></th>
                        <th class="py-2 pr-4 text-right"><span class="rotulo">Em uso</span></th>
                        <th class="py-2 pr-4"><span class="rotulo">Estado</span></th>
                        <th class="py-2 text-right"><span class="rotulo">Ações</span></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($audios as $a)
                        <tr class="border-b border-[var(--rule)] hover:bg-[var(--bg-hover)]">
                            <td class="py-2.5 pr-4">
                                <div class="font-medium">{{ $a['nome'] }}</div>
                                <div class="rotulo identificador mt-0.5">{{ $a['arquivo'] }}</div>
                            </td>

                            {{-- Duração vem do TAMANHO do arquivo: a 8 kHz mono A-law
                                 são 8000 bytes por segundo, exatos. Num formato sem
                                 cabeçalho, o tamanho é a única fonte que existe. --}}
                            <td class="num identificador py-2.5 pr-4">{{ $a['duracao'] }}s</td>

                            <td class="num py-2.5 pr-4 {{ $a['em_uso'] > 0 ? '' : 'text-base-content/40' }}">
                                {{ $a['em_uso'] }}
                            </td>

                            <td class="py-2.5 pr-4">
                                @if (! $a['no_disco'])
                                    {{-- Vermelho porque É falha: a linha existe e o
                                         arquivo não. Toda Campanha que usa este áudio
                                         sai muda, e o dialplan continua sem erro. --}}
                                    <span class="text-error font-semibold">arquivo ausente</span>
                                @elseif ($a['ativo'])
                                    <span class="text-primary">disponível</span>
                                @else
                                    <span class="text-base-content/50">desativado</span>
                                @endif
                            </td>

                            <td class="py-2.5 text-right whitespace-nowrap">
                                @if ($a['ativo'])
                                    <button wire:click="desativar({{ $a['id'] }})"
                                            class="btn btn-ghost btn-xs">desativar</button>
                                @else
                                    <button wire:click="reativar({{ $a['id'] }})"
                                            class="btn btn-ghost btn-xs text-primary">reativar</button>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        {{-- Diz por que não há botão de apagar. Sem isto, a ausência lê como
             recurso faltando em vez de decisão. --}}
        <p class="mt-4 text-sm text-base-content/50">
            Áudio não é apagado, só desativado: Campanha antiga continua apontando
            para o que de fato tocou. Desativar apenas o remove das escolhas novas.
        </p>
    @endif
</div>

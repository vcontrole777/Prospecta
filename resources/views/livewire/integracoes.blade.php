<div class="max-w-4xl">
    <div class="flex items-baseline justify-between border-b border-[var(--rule)] pb-3">
        <h1 class="text-lg font-semibold tracking-tight">Integrações</h1>
        <a href="{{ route('campanhas') }}" class="rotulo hover:text-primary">Campanhas</a>
    </div>

    @if (session('recado'))
        <div class="mt-4 border border-[var(--rule)] p-3 text-sm text-base-content/70">{{ session('recado') }}</div>
    @endif

    <form wire:submit="salvar" class="mt-6 flex flex-col gap-4 border border-[var(--rule)] p-4">
        <p class="rotulo">{{ $editando === null ? 'Nova integração' : 'Editar integração' }}</p>

        <div class="grid gap-4 sm:grid-cols-2">
            <x-select label="Tipo" wire:model.live="tipo"
                :options="collect(array_keys(App\Livewire\Integracoes::CAMPOS))
                    ->map(fn ($t) => ['id' => $t, 'name' => $t === 'tts' ? 'Síntese de voz (TTS)' : ucfirst($t)])
                    ->values()->all()" />

            <x-select label="Provedor" wire:model.live="provedor"
                :options="collect($this->provedoresDoTipo())->map(fn ($p) => ['id' => $p, 'name' => $p])->values()->all()" />
        </div>

        @unless (in_array($tipo, $consumidos, true))
            {{-- Honesto sobre o que o produto ainda não consome. Sem isto o operador
                 cadastraria uma integração, veria "salva", e nada aconteceria. --}}
            <p class="text-sm text-warning">
                Nenhum processo do produto usa integração de
                <span class="identificador">{{ $tipo }}</span> ainda. O cadastro é
                guardado e fica pronto, mas não produz efeito hoje.
            </p>
        @endunless

        <x-input label="Nome" wire:model="nome"
                 placeholder="{{ $tipo === 'tts' ? 'ElevenLabs — voz Camila' : 'WhatsApp — conta principal' }}" />

        <p class="text-sm text-base-content/60">
            O nome é como a Campanha vai se referir a esta integração. Vale ter mais de
            uma do mesmo provedor — vozes diferentes são linhas diferentes.
        </p>

        @if ($tipo === 'tts')
            <x-input label="Voz" wire:model="voz" class="identificador"
                     placeholder="voice_id da voz escolhida" />
        @endif

        @foreach ($campos as $chave => $def)
            <div>
                <x-input
                    label="{{ $def['rotulo'] }}"
                    wire:model="credenciais.{{ $chave }}"
                    type="{{ ($def['segredo'] ?? false) ? 'password' : 'text' }}"
                    class="identificador"
                    autocomplete="off"
                    placeholder="{{ ($def['segredo'] ?? false) && $editando !== null ? 'em branco mantém o atual' : '' }}"
                />
                @if (isset($def['dica']))
                    <p class="mt-1 text-sm text-base-content/60">{{ $def['dica'] }}</p>
                @endif
            </div>
        @endforeach

        {{-- Isto não é aviso genérico de segurança: é a consequência operacional de
             a APP_KEY virar chave de dados, e ela é irreversível na prática. --}}
        <div class="border border-[var(--rule)] p-3 text-sm text-base-content/60">
            A credencial é cifrada antes de ir para o banco e nunca volta para esta
            tela — a lista mostra só os últimos caracteres, o bastante para você
            reconhecer qual chave está ali. A cifra usa a
            <span class="identificador">APP_KEY</span> da instância: trocá-la torna
            toda credencial ilegível e exige recadastrar cada integração.
        </div>

        <div class="flex items-center gap-3">
            <x-button type="submit" label="{{ $editando === null ? 'Cadastrar' : 'Salvar' }}"
                      class="btn-primary" spinner="salvar" />
            @if ($editando !== null)
                <button type="button" wire:click="cancelar" class="btn btn-ghost">Cancelar</button>
            @endif
        </div>
    </form>

    @if ($integracoes === [])
        <div class="mt-6 border border-[var(--rule)] p-8 text-center text-sm">
            <p class="text-base-content/70">Nenhuma integração cadastrada.</p>
            <p class="mt-2 text-base-content/50">
                Sem uma integração de WhatsApp em vigor, o opt-in é registrado e a
                mensagem nunca sai.
            </p>
        </div>
    @else
        <div class="mt-6 flex flex-col gap-3">
            @foreach ($integracoes as $i)
                <div class="border border-[var(--rule)] p-4">
                    <div class="flex flex-wrap items-baseline justify-between gap-2">
                        <div>
                            <span class="font-medium">{{ $i['nome'] }}</span>
                            <span class="rotulo ml-2">{{ $i['tipo'] }} · {{ $i['provedor'] }}</span>

                            @if (($emVigor[$i['tipo']] ?? null) === $i['id'])
                                <span class="ml-2 border border-primary px-1.5 py-px text-[length:var(--text-2xs)]
                                             uppercase tracking-[var(--tracking-label)] text-primary">em vigor</span>
                            @endif

                            @unless ($i['ativo'])
                                <span class="rotulo ml-2">desativada</span>
                            @endunless
                        </div>

                        <div class="flex items-center gap-1 whitespace-nowrap">
                            @if (($emVigor[$i['tipo']] ?? null) !== $i['id'] && $i['ativo'])
                                <button wire:click="usar({{ $i['id'] }})"
                                        class="btn btn-ghost btn-xs text-primary">usar esta</button>
                            @endif
                            <button wire:click="editar({{ $i['id'] }})" class="btn btn-ghost btn-xs">editar</button>
                            @if ($i['ativo'])
                                <button wire:click="desativar({{ $i['id'] }})" class="btn btn-ghost btn-xs">desativar</button>
                            @else
                                <button wire:click="reativar({{ $i['id'] }})" class="btn btn-ghost btn-xs">reativar</button>
                            @endif
                        </div>
                    </div>

                    @if ($i['ilegivel'])
                        {{-- Vermelho porque É falha, e nomeada: a credencial existe e
                             não abre. Quase sempre a APP_KEY mudou. --}}
                        <p class="mt-2 text-sm text-error">
                            Credencial ilegível — foi cifrada com outra
                            <span class="identificador">APP_KEY</span>. Recadastre.
                        </p>
                    @elseif ($i['resumo'] !== [])
                        <dl class="mt-3 grid gap-x-6 gap-y-1 text-sm sm:grid-cols-2">
                            @foreach ($i['resumo'] as $rotulo => $valor)
                                <div class="flex justify-between gap-3 border-b border-[var(--rule)] pb-1">
                                    <dt class="text-base-content/60">{{ $rotulo }}</dt>
                                    <dd class="identificador">{{ $valor === '' ? '—' : $valor }}</dd>
                                </div>
                            @endforeach
                            @if ($i['voz'] !== '')
                                <div class="flex justify-between gap-3 border-b border-[var(--rule)] pb-1">
                                    <dt class="text-base-content/60">Voz</dt>
                                    <dd class="identificador">{{ $i['voz'] }}</dd>
                                </div>
                            @endif
                        </dl>
                    @endif
                </div>
            @endforeach
        </div>

        <p class="mt-4 text-sm text-base-content/50">
            "Em vigor" é escolha global, não por Campanha. Para o WhatsApp isso é
            deliberado: o número é identidade — é o nome verificado que o Lead vê —
            enquanto a BINA é tática e muda por Campanha.
        </p>
    @endif
</div>

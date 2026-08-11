<div class="max-w-3xl">
    <div class="flex items-baseline justify-between border-b border-[var(--rule)] pb-3">
        <h1 class="text-lg font-semibold tracking-tight">
            {{ $campanhaId === null ? 'Nova Campanha' : 'Editar Campanha' }}
        </h1>
        <a href="{{ route('campanhas') }}" class="rotulo hover:text-primary">voltar</a>
    </div>

    <form wire:submit="salvar" class="mt-6 flex flex-col gap-8">

        {{-- ---------------------------------------------------------------- --}}
        <section class="flex flex-col gap-4">
            <h2 class="rotulo border-b border-[var(--rule)] pb-1">Identificação</h2>

            <x-input label="Nome" wire:model="nome" />

            <x-select
                label="Tipo"
                wire:model.live="tipo"
                :options="[
                    ['id' => 'opt_in',  'name' => 'Opt-in — a pessoa aperta uma tecla para aceitar'],
                    ['id' => 'captura', 'name' => 'Captura — a pessoa digita dados no teclado'],
                ]"
            />

            <p class="text-sm text-base-content/60">
                @if ($tipo === 'captura')
                    A URA pergunta os campos na ordem definida abaixo e grava o que for
                    digitado. Não há tecla de opt-in nesse tipo.
                @else
                    A URA toca a mensagem e espera uma tecla.
                @endif
            </p>
        </section>

        {{-- ---------------------------------------------------------------- --}}
        <section class="flex flex-col gap-4">
            <h2 class="rotulo border-b border-[var(--rule)] pb-1">Discagem</h2>

            <div class="grid gap-4 sm:grid-cols-3">
                <x-input label="Chamadas simultâneas" wire:model="chamadas_simultaneas" type="number" min="1" />
                <x-input label="Máx. de tentativas" wire:model="max_tentativas" type="number" min="1" max="10" />
                <x-input label="Tentativas na URA" wire:model="tentativas_ura" type="number" min="1" max="5" />
            </div>

            <p class="text-sm text-base-content/60">
                O teto de canais contratados da instância se aplica de qualquer
                forma — a Campanha pode pedir menos, nunca mais.
            </p>
        </section>

        {{-- ---------------------------------------------------------------- --}}
        <section class="flex flex-col gap-4">
            <h2 class="rotulo border-b border-[var(--rule)] pb-1">BINA</h2>

            <x-select
                label="Regra"
                wire:model.live="bina_regra"
                :options="[
                    ['id' => 'unica',        'name' => 'Única — usa o número abaixo'],
                    ['id' => 'lista',        'name' => 'Lista — sorteia da lista com o mesmo DDD do destino'],
                    ['id' => 'espelho',      'name' => 'Espelho — número do Lead com os 4 últimos dígitos trocados'],
                    ['id' => 'espelho_fixo', 'name' => 'Espelho fixo — país e DDD do Lead, com fixo gerado'],
                ]"
            />

            @if ($bina_regra === 'unica')
                <x-input label="Número" wire:model="bina" placeholder="5511987654321" class="identificador" />

                {{-- Isto é comportamento REAL, verificado em `Bina::derivar`:
                     regra `unica` com número vazio devolve null, e null significa
                     "não peça CallerID nenhum" — quem resolve é o fornecedor. --}}
                <p class="text-sm text-base-content/60">
                    Em branco, nenhum CallerID é pedido e quem decide é o
                    fornecedor. Alguns já entregam BINA própria; este campo só
                    serve para fornecedor que permite trocar.
                </p>
            @endif

            @if ($bina_regra === 'lista')
                {{-- Este texto dizia que a tela "ainda não existe". Ela existe desde
                     10/08, e o aviso passou a mandar o operador não usar um recurso que
                     funciona — texto desatualizado mente com a mesma eficácia de um
                     defeito. --}}
                <p class="text-sm text-base-content/70">
                    Os números são cadastrados na tela <a href="{{ route('binas') }}"
                    class="text-primary hover:underline">BINA</a>, que mostra a cobertura
                    por DDD. Com a lista vazia, esta regra cai no número único da Campanha
                    e, se ele também estiver vazio, em nenhum CallerID.
                </p>
            @endif

            @if ($bina_regra === 'espelho' || $bina_regra === 'espelho_fixo')
                <p class="text-sm text-base-content/60">
                    Derivado do número do destino, sem cadastro. Só funciona com
                    fornecedor que aceita CallerID arbitrário.
                </p>
            @endif
        </section>

        {{-- ---------------------------------------------------------------- --}}
        @if ($tipo === 'captura')
            <section class="flex flex-col gap-4">
                <div class="flex items-baseline justify-between border-b border-[var(--rule)] pb-1">
                    <h2 class="rotulo">Campos a coletar</h2>
                    <button type="button" wire:click="adicionarCampo" class="btn btn-ghost btn-xs">+ campo</button>
                </div>

                @if ($campos === [])
                    <p class="text-sm text-warning">
                        Nenhum campo. Campanha de captura sem campo liga, não pergunta
                        nada e encerra.
                    </p>
                @endif

                @foreach ($campos as $i => $campo)
                    <div class="border border-[var(--rule)] p-4">
                        <div class="flex items-baseline justify-between border-b border-[var(--rule)] pb-2">
                            {{-- A ordem da pergunta é a ordem da lista. Numerar deixa isso
                                 explícito em vez de implícito na posição. --}}
                            <span class="rotulo">Pergunta {{ $i + 1 }}</span>
                            <button type="button" wire:click="removerCampo({{ $i }})"
                                    class="btn btn-ghost btn-xs">remover</button>
                        </div>

                        <div class="mt-3 grid gap-4 sm:grid-cols-2">
                            <x-input label="Nome" wire:model="campos.{{ $i }}.nome"
                                     placeholder="CPF" />

                            <x-select label="Tipo" wire:model.live="campos.{{ $i }}.tipo"
                                :options="[
                                    ['id' => 'cpf',    'name' => 'CPF — dígito verificador conferido'],
                                    ['id' => 'numero', 'name' => 'Número — só a quantidade de dígitos'],
                                ]" />
                        </div>

                        <div class="mt-4 grid gap-4 sm:grid-cols-3">
                            <div>
                                <x-input label="Dígitos" type="number" min="1" max="30" class="num"
                                         wire:model="campos.{{ $i }}.tamanho"
                                         :disabled="$campo['tipo'] === 'cpf'" />
                                @if ($campo['tipo'] === 'cpf')
                                    {{-- Não é limite arbitrário: `Cpf::valido` exige
                                         exatamente 11, e outro tamanho NUNCA validaria —
                                         a pessoa digitaria e erraria todas as tentativas. --}}
                                    <p class="rotulo mt-1">CPF é sempre 11</p>
                                @endif
                            </div>

                            <x-input label="Tentativas" type="number" min="1" max="5" class="num"
                                     wire:model="campos.{{ $i }}.tentativas" />

                            <x-input label="Timeout (s)" type="number" min="3" max="60" class="num"
                                     wire:model="campos.{{ $i }}.timeout_seg" />
                        </div>

                        <div class="mt-4">
                            @if ($audios === [])
                                <p class="text-sm text-warning">
                                    Nenhum áudio cadastrado — sem o áudio que faz a
                                    pergunta, a pessoa ouve silêncio.
                                </p>
                            @else
                                <x-select label="Áudio que faz a pergunta"
                                          wire:model="campos.{{ $i }}.audio_prompt_id"
                                          :options="$audios" option-label="nome"
                                          placeholder="— escolha —" />
                            @endif
                        </div>

                        <div class="mt-4">
                            <x-checkbox label="Obrigatório" wire:model="campos.{{ $i }}.obrigatorio" />
                            <p class="mt-1 text-sm text-base-content/60">
                                Obrigatório não coletado encerra como captura parcial.
                                Opcional é apenas pulado.
                            </p>
                        </div>
                    </div>
                @endforeach
            </section>
        @endif

        {{-- ---------------------------------------------------------------- --}}
        <section class="flex flex-col gap-4">
            <h2 class="rotulo border-b border-[var(--rule)] pb-1">URA</h2>

            @if ($tipo === 'opt_in')
                <div class="grid gap-4 sm:grid-cols-2">
                    <x-input label="Tecla de opt-in" wire:model="tecla_opt_in" maxlength="1" class="identificador" />
                    <x-input label="Tecla de opt-out" wire:model="tecla_opt_out" maxlength="1" class="identificador" />
                </div>
            @endif

            @if ($audios === [])
                {{-- Estado vazio honesto: sem áudio a Campanha liga e não fala
                     nada, e não há tela de cadastro de áudio ainda. Dizer isso é
                     melhor que oferecer um seletor vazio sem explicação. --}}
                <div class="border border-warning/50 p-4 text-sm">
                    <p class="text-warning">Nenhum áudio cadastrado.</p>
                    <p class="mt-1 text-base-content/70">
                        Sem áudio de mensagem a Campanha liga e não fala nada. A tela
                        de cadastro de áudio ainda não existe; por enquanto os áudios
                        entram por SQL na tabela <span class="identificador">audio</span>.
                    </p>
                </div>
            @else
                <x-select
                    label="Áudio da mensagem"
                    wire:model="audio_mensagem_id"
                    :options="$audios"
                    option-label="nome"
                    placeholder="— nenhum —"
                />

                <div class="grid gap-4 sm:grid-cols-3">
                    <x-select label="Confirmação de opt-in" wire:model="audio_confirma_opt_in_id"
                              :options="$audios" option-label="nome" placeholder="— nenhum —" />
                    <x-select label="Confirmação de opt-out" wire:model="audio_confirma_opt_out_id"
                              :options="$audios" option-label="nome" placeholder="— nenhum —" />
                    <x-select label="Não entendi" wire:model="audio_nao_entendi_id"
                              :options="$audios" option-label="nome" placeholder="— nenhum —" />
                </div>
            @endif
        </section>

        {{-- ---------------------------------------------------------------- --}}
        <section class="flex flex-col gap-4">
            <h2 class="rotulo border-b border-[var(--rule)] pb-1">Mensagem de opt-in</h2>

            <x-select
                label="Canal"
                wire:model.live="canal_opt_in"
                :options="[
                    ['id' => 'whatsapp', 'name' => 'WhatsApp'],
                    ['id' => 'sms',      'name' => 'SMS'],
                ]"
            />

            @if ($canal_opt_in === 'sms')
                <p class="text-sm text-warning">
                    O envio por SMS ainda não tem remetente implementado. A Ação é
                    registrada e fica aguardando — não é perdida, mas também não sai.
                </p>
            @else
                <div class="grid gap-4 sm:grid-cols-2">
                    <x-input label="Nome do template" wire:model="template_nome" class="identificador" />
                    <x-input label="Idioma" wire:model="template_idioma" class="identificador" />
                </div>

                <p class="text-sm text-base-content/60">
                    Os botões de resposta rápida são definidos na criação do template,
                    no gerenciador da Meta — não aqui. É o toque no botão que abre a
                    janela de 24 h em que a conversa deixa de custar por mensagem.
                </p>

                {{-- Parâmetros POSICIONAIS. A posição é o índice na lista, então
                     não existe forma de criar mapa com lacuna — que é o caso em
                     que o Lead receberia a mensagem de outra pessoa. --}}
                <div class="border border-[var(--rule)] p-4">
                    <div class="flex items-baseline justify-between">
                        <span class="rotulo">Parâmetros do template, na ordem</span>
                        <button type="button" wire:click="adicionarParametro"
                                class="btn btn-ghost btn-xs">+ parâmetro</button>
                    </div>

                    @if ($parametros === [])
                        <p class="mt-2 text-sm text-base-content/50">
                            {{-- `@` escapa a chave dupla. Escrito como
                                 `{{ '…' }}` com a chave dentro da string, o Blade
                                 tenta interpretar a de DENTRO e falha a compilação
                                 com "Unclosed '(' does not match '}'" — mensagem
                                 que não aponta para a linha culpada. --}}
                            Nenhum. Use se o template tem <span class="identificador">@{{1}}</span>,
                            <span class="identificador">@{{2}}</span> e assim por diante.
                        </p>
                    @else
                        <div class="mt-3 flex flex-col gap-2">
                            @foreach ($parametros as $i => $p)
                                <div class="flex items-end gap-2">
                                    <span class="num identificador w-8 pb-3 text-sm text-base-content/50">{{ $i + 1 }}</span>
                                    <div class="flex-1">
                                        <x-input
                                            wire:model="parametros.{{ $i }}"
                                            placeholder="nome da coluna da lista importada"
                                        />
                                    </div>
                                    <button type="button" wire:click="removerParametro({{ $i }})"
                                            class="btn btn-ghost btn-sm">remover</button>
                                </div>
                            @endforeach
                        </div>

                        <p class="mt-3 text-sm text-base-content/60">
                            A posição vem da ordem desta lista. O nome tem que bater com
                            a coluna da planilha importada.
                        </p>
                    @endif
                </div>
            @endif
        </section>

        {{-- ---------------------------------------------------------------- --}}
        <div class="flex items-center gap-3 border-t border-[var(--rule)] pt-6">
            <x-button type="submit" label="Salvar" class="btn-primary" spinner="salvar" />
            <a href="{{ route('campanhas') }}" class="btn btn-ghost">Cancelar</a>

            @if ($campanhaId === null)
                <span class="rotulo ml-auto">nasce em rascunho; iniciar é na lista</span>
            @endif
        </div>
    </form>
</div>

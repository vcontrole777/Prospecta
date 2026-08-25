<div class="max-w-3xl">
    <div class="flex items-baseline justify-between border-b border-[var(--rule)] pb-3">
        <div>
            <h1 class="text-lg font-semibold tracking-tight">Importar lista</h1>
            <p class="rotulo mt-1">{{ $campanhaNome }}</p>
        </div>
        <a href="{{ route('campanhas') }}" class="rotulo hover:text-primary">voltar</a>
    </div>

    {{-- ================= ETAPA 1: ENVIAR ================= --}}
    @if ($etapa === 'enviar')
        <div class="mt-6 flex flex-col gap-4">
            <x-file label="Arquivo CSV" wire:model="arquivo" accept=".csv,.txt" />

            <div wire:loading wire:target="arquivo" class="text-sm text-primary">
                Lendo o arquivo…
            </div>

            <div class="border border-[var(--rule)] p-4 text-sm text-base-content/70">
                <p class="rotulo">O que o arquivo precisa ter</p>
                <ul class="mt-2 flex flex-col gap-1">
                    <li>Uma linha de cabeçalho com o nome das colunas.</li>
                    <li>
                        Uma coluna de telefone. As outras são livres e ficam
                        disponíveis para substituição na mensagem.
                    </li>
                </ul>

                {{-- Isto não é enfeite: o Excel em português salva com `;` e em
                     cp1252, e sem tolerar os dois o operador veria "0 importados"
                     ou o nome do Lead corrompido dentro da mensagem. --}}
                <p class="mt-3">
                    Separador e acentuação são detectados sozinhos — inclusive o
                    <span class="identificador">;</span> e a acentuação do Excel em
                    português.
                </p>
            </div>
        </div>
    @endif

    {{-- ================= ETAPA 2: CONFERIR ================= --}}
    @if ($etapa === 'mapear')
        <div class="mt-6 flex flex-col gap-6">
            <div class="flex flex-wrap items-center gap-x-6 gap-y-1 text-sm text-base-content/60">
                <span>separador <span class="identificador text-base-content">{{ $delimitador === "\t" ? 'TAB' : $delimitador }}</span></span>
                <span>acentuação <span class="identificador text-base-content">{{ $codificacao }}</span></span>
                <span>{{ count($colunas) }} colunas</span>
            </div>

            {{-- 🔴 A PRIMEIRA LINHA PARECE DADO?
                 O importador SEMPRE trata a primeira linha como cabeçalho, e não tem
                 opção de "arquivo sem cabeçalho". Quando o CSV vem sem ela, a primeira
                 PESSOA vira o nome das colunas e é perdida — e todo Lead do arquivo
                 fica com colunas chamadas, por exemplo, `247.942.998-93`.

                 Aviso e não recusa: cabeçalho legítimo pode ter nome estranho, e quem
                 sabe é quem exportou a planilha. --}}
            @if ($cabecalhoSuspeito !== [])
                <div class="border border-warning p-4">
                    <p class="font-medium text-warning">
                        ⚠ A primeira linha do arquivo parece ser <strong>dado</strong>, não cabeçalho
                    </p>

                    <div class="mt-2 flex flex-wrap gap-2">
                        @foreach ($cabecalhoSuspeito as $sus)
                            <span class="border border-[var(--rule)] px-2 py-1 text-xs">
                                <span class="identificador">{{ $sus['coluna'] }}</span>
                                <span class="text-base-content/60">— {{ $sus['motivo'] }}</span>
                            </span>
                        @endforeach
                    </div>

                    <p class="mt-3 text-sm text-base-content/70">
                        Se for mesmo dado, <strong>duas coisas acontecem em silêncio</strong>: essa
                        primeira pessoa é perdida — ela vira o nome das colunas e nunca é discada —
                        e todos os Leads ficam com colunas de nome errado. O Placeholder do template
                        deixa de achar <span class="identificador">nome</span>, e o disparo falha
                        depois, com a Campanha já no ar.
                    </p>
                    <p class="mt-2 text-sm text-base-content/70">
                        Nesse caso, cancele, acrescente uma linha de cabeçalho na planilha e importe
                        de novo. Se a primeira linha for cabeçalho mesmo — CRM às vezes exporta
                        código de campo como nome —, pode seguir.
                    </p>
                </div>
            @endif

            {{-- A conferência é o motivo de existirem duas etapas. Importar direto
                 trocaria um clique por uma lista de 50 mil Leads com a coluna errada
                 no telefone — que só aparece depois de o dialer discar. --}}
            <div>
                <x-select
                    label="Qual coluna é o telefone?"
                    wire:model.live="colunaTelefone"
                    :options="collect($colunas)->map(fn ($nome, $i) => ['id' => $i, 'name' => ($nome ?: 'coluna ' . ($i + 1))])->values()->all()"
                />
                <p class="mt-2 text-sm text-base-content/60">
                    Confira na prévia abaixo antes de importar.
                </p>
            </div>

            <div class="overflow-x-auto border border-[var(--rule)]">
                <table class="w-full border-collapse text-sm">
                    <thead>
                        <tr class="border-b border-[var(--rule-strong)] text-left">
                            @foreach ($colunas as $i => $nome)
                                <th class="px-3 py-2 whitespace-nowrap
                                           {{ $i === $colunaTelefone ? 'text-primary' : '' }}">
                                    <span class="rotulo {{ $i === $colunaTelefone ? 'text-primary' : '' }}">
                                        {{ $nome ?: 'coluna ' . ($i + 1) }}
                                    </span>
                                    @if ($i === $colunaTelefone)
                                        <span class="rotulo block text-primary">telefone</span>
                                    @endif
                                </th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($amostra as $linha)
                            <tr class="border-b border-[var(--rule)]">
                                @foreach ($colunas as $i => $nome)
                                    <td class="px-3 py-2 whitespace-nowrap
                                               {{ $i === $colunaTelefone ? 'identificador text-primary' : '' }}">
                                        {{ $linha[$i] ?? '' }}
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="flex items-center gap-3 border-t border-[var(--rule)] pt-6">
                <x-button label="Importar" class="btn-primary" wire:click="importar" spinner="importar" />
                <button type="button" wire:click="outroArquivo" class="btn btn-ghost">Outro arquivo</button>
            </div>

            <div wire:loading wire:target="importar" class="text-sm text-primary">
                Importando… não feche a página.
            </div>
        </div>
    @endif

    {{-- ================= ETAPA 3: RESULTADO ================= --}}
    @if ($etapa === 'pronto')
        <div class="mt-6 flex flex-col gap-6">
            <div class="grid gap-4 sm:grid-cols-3">
                <div class="border border-[var(--rule)] p-4">
                    <div class="rotulo">Importados</div>
                    <div class="num identificador mt-1 text-2xl {{ $inseridos > 0 ? 'text-primary' : 'text-base-content/40' }}">
                        {{ number_format($inseridos, 0, ',', '.') }}
                    </div>
                </div>

                {{-- Duplicata NÃO é erro: é o sistema impedindo duas chamadas para a
                     mesma pessoa na mesma Campanha, que é a coisa que mais rápido
                     gera reclamação. Por isso é neutra, não vermelha. --}}
                <div class="border border-[var(--rule)] p-4">
                    <div class="rotulo">Já estavam na lista</div>
                    <div class="num identificador mt-1 text-2xl text-base-content/60">
                        {{ number_format($duplicados, 0, ',', '.') }}
                    </div>
                </div>

                <div class="border {{ $rejeitados > 0 ? 'border-warning/50' : 'border-[var(--rule)]' }} p-4">
                    <div class="rotulo">Recusados</div>
                    <div class="num identificador mt-1 text-2xl {{ $rejeitados > 0 ? 'text-warning' : 'text-base-content/40' }}">
                        {{ number_format($rejeitados, 0, ',', '.') }}
                    </div>
                </div>
            </div>

            @if ($duplicados > 0)
                <p class="text-sm text-base-content/60">
                    Número repetido não é erro: a Campanha não disca duas vezes para a
                    mesma pessoa. Entre Campanhas diferentes, duplicar é permitido —
                    são ofertas diferentes.
                </p>
            @endif

            @if ($rejeitados > 0)
                <div class="border border-warning/50 p-4">
                    <p class="text-sm text-warning">
                        Recusados por não formarem um telefone brasileiro válido.
                    </p>
                    <p class="mt-1 text-sm text-base-content/70">
                        Recusar aqui evita chamada paga: DDD que não existe volta erro
                        de tronco, e número não atribuído aposenta o Lead na hora.
                    </p>

                    @if ($exemplosRejeitados !== [])
                        <table class="mt-3 w-full border-collapse text-sm">
                            <thead>
                                <tr class="border-b border-[var(--rule)] text-left">
                                    <th class="py-1 pr-4"><span class="rotulo">Linha do arquivo</span></th>
                                    <th class="py-1"><span class="rotulo">Valor</span></th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($exemplosRejeitados as $r)
                                    <tr class="border-b border-[var(--rule)]">
                                        <td class="num identificador py-1 pr-4">{{ $r['linha'] }}</td>
                                        <td class="identificador py-1">{{ $r['valor'] === '' ? '(vazio)' : $r['valor'] }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>

                        @if ($rejeitados > count($exemplosRejeitados))
                            {{-- Diz que a lista é parcial. Uma tabela de 10 linhas
                                 sem esta frase leria como "foram só estes". --}}
                            <p class="mt-2 text-sm text-base-content/50">
                                Mostrando {{ count($exemplosRejeitados) }} de
                                {{ number_format($rejeitados, 0, ',', '.') }}.
                            </p>
                        @endif
                    @endif
                </div>
            @endif

            <div class="flex items-center gap-3 border-t border-[var(--rule)] pt-6">
                <a href="{{ route('campanhas') }}" class="btn btn-primary">Ir para as Campanhas</a>
                <button type="button" wire:click="outroArquivo" class="btn btn-ghost">Importar outro arquivo</button>
            </div>
        </div>
    @endif
</div>

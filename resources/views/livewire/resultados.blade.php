{{-- Sem `wire:poll`. Esta tela é para conferir e exportar, não para monitorar: quem
     está lendo uma tabela de 50 linhas não quer que ela se redesenhe embaixo do cursor,
     e a paginação faria o polling trocar o conteúdo da página sob quem está lendo.
     Monitoramento ao vivo é a lista de Campanhas, que é agregada e barata. --}}
<div>
    <div class="flex flex-wrap items-baseline justify-between gap-3 border-b border-[var(--rule)] pb-3">
        <div class="flex items-baseline gap-3">
            <h1 class="text-lg font-semibold tracking-tight">{{ $campanhaNome }}</h1>
            <span class="rotulo">resultados · {{ $campanhaTipo === 'captura' ? 'captura' : 'opt-in' }}</span>
        </div>
        <div class="flex items-baseline gap-4">
            <a href="{{ route('campanha.importar', $campanhaId) }}" class="rotulo hover:text-primary">Importar lista</a>
            <a href="{{ route('campanhas') }}" class="rotulo hover:text-primary">Campanhas</a>
        </div>
    </div>

    @unless ($gravacaoAcessivel)
        {{-- 🔴 O AVISO É SOBRE NÓS, não sobre o Asterisk.
             As gravações são `asterisk:asterisk` modo 2770 e o painel roda como
             `prospecta`, que precisa estar no grupo `asterisk`. Grupo suplementar é
             fixado no `exec`: adesão concedida depois de o php-fpm subir não vale para os
             workers em execução. Sem este aviso, a coluna de gravação diria "não está no
             disco" em toda linha, acusando o Asterisk de um problema de configuração
             nosso — e ensinando o operador a ignorar o aviso que um dia estará certo. --}}
        <div class="mt-4 border border-warning/50 p-4 text-sm">
            <p class="text-warning">O painel não consegue ler o diretório de gravações</p>
            <p class="mt-1 text-base-content/70">
                <span class="identificador">{{ $gravacaoDir }}</span> existe, mas este
                processo não o atravessa. A coluna de gravação abaixo não pode afirmar nada
                enquanto isso durar. O usuário do php-fpm precisa estar no grupo
                <span class="identificador">asterisk</span> — e o serviço precisa ser
                <strong>reiniciado</strong> depois de entrar no grupo, porque grupo
                suplementar só é lido quando o processo começa.
            </p>
        </div>
    @endunless

    {{-- ---------- os números ---------- --}}
    <div class="mt-5 grid gap-px bg-[var(--rule)] sm:grid-cols-2 lg:grid-cols-4">
        <div class="bg-[var(--bg-surface)] p-4">
            <div class="rotulo">Ligações</div>
            <div class="num mt-1 text-2xl">{{ number_format($contagens['total'], 0, ',', '.') }}</div>
        </div>
        <div class="bg-[var(--bg-surface)] p-4">
            <div class="rotulo">Atendidas</div>
            <div class="num mt-1 text-2xl">{{ number_format($contagens['atendidas'], 0, ',', '.') }}</div>
            {{-- A taxa é sobre ligações, não sobre Leads: um Lead retentado três vezes
                 conta três ligações, e é o número que diz se vale continuar discando. --}}
            @if ($contagens['total'] > 0)
                <div class="rotulo mt-1">{{ number_format($contagens['atendidas'] / $contagens['total'] * 100, 1, ',', '.') }}% das ligações</div>
            @endif
        </div>

        @php
            $conversao = $campanhaTipo === 'captura'
                ? ($contagens['desfechos']['captura_concluida'] ?? 0)
                : ($contagens['desfechos']['opt_in'] ?? 0);
        @endphp
        <div class="bg-[var(--bg-surface)] p-4">
            <div class="rotulo">{{ $campanhaTipo === 'captura' ? 'Capturas concluídas' : 'Opt-in' }}</div>
            <div class="num mt-1 text-2xl text-primary">{{ number_format($conversao, 0, ',', '.') }}</div>
            @if ($contagens['atendidas'] > 0)
                <div class="rotulo mt-1">{{ number_format($conversao / $contagens['atendidas'] * 100, 1, ',', '.') }}% das atendidas</div>
            @endif
        </div>

        <div class="bg-[var(--bg-surface)] p-4">
            <div class="rotulo">Erro</div>
            @php $erros = $contagens['desfechos']['erro'] ?? 0; @endphp
            <div class="num mt-1 text-2xl {{ $erros > 0 ? 'text-error' : '' }}">{{ number_format($erros, 0, ',', '.') }}</div>
            {{-- Erro merece destaque próprio: é o único desfecho que aponta para NÓS. Os
                 outros descrevem o Lead ou a rota; este descreve áudio ausente, canal
                 morto ou configuração faltando, e tem conserto. --}}
            @if ($erros > 0)
                <div class="rotulo mt-1 text-error">áudio ou configuração — veja a coluna erro</div>
            @endif
        </div>
    </div>

    {{-- ---------- filtro ---------- --}}
    <div class="mt-5 flex flex-wrap items-end justify-between gap-4">
        <div class="flex flex-wrap items-end gap-4">
            <label class="block">
                <span class="rotulo">Desfecho</span>
                <select wire:model.live="desfecho"
                        class="mt-1 block border border-[var(--rule)] bg-[var(--bg-surface)] px-2 py-1 text-sm">
                    <option value="">todos</option>
                    @foreach (\App\Livewire\Resultados::DESFECHOS as $chave => $nome)
                        <option value="{{ $chave }}">{{ $nome }} ({{ $contagens['desfechos'][$chave] ?? 0 }})</option>
                    @endforeach
                </select>
            </label>

            <label class="flex items-center gap-2 pb-1 text-sm">
                <input type="checkbox" wire:model.live="somenteAtendidas" class="checkbox checkbox-sm">
                <span>só atendidas</span>
            </label>

            @if ($contagens['sem_desfecho'] > 0)
                {{-- Nomeado em vez de escondido: a diferença entre o total e a soma dos
                     desfechos é Ligação em curso ou que morreu antes de concluir. Sem
                     esta linha, os números "não fecham" e ninguém sabe por quê. --}}
                <span class="rotulo pb-1">{{ $contagens['sem_desfecho'] }} sem desfecho (em curso ou interrompida)</span>
            @endif
        </div>

        <div class="text-right">
            {{-- 🔴 SÓ APARECE QUANDO HÁ O QUE REENVIAR. Botão sempre presente convida
                 clique sem efeito; e a falha em massa é o caso real — um 131042 (moeda
                 não configurada) ou um token vencido derruba a Campanha inteira de uma
                 vez, e aí clicar linha a linha em mil Leads não é operação. --}}
            @if ($disparosFalhados > 0)
                <button wire:click="reenviarFalhados"
                        wire:confirm="Recolocar na fila os {{ $disparosFalhados }} Disparos que falharam?"
                        class="btn btn-sm mb-2">
                    Reenviar {{ $disparosFalhados }} {{ $disparosFalhados === 1 ? 'Disparo falhado' : 'Disparos falhados' }}
                </button>
                <div class="rotulo mb-3">Conserte a causa antes — reenviar sem isso repete a mesma falha</div>
            @endif

            <button wire:click="exportar" class="btn btn-sm btn-primary">Exportar CSV</button>
            {{-- Dito na tela porque o operador vai abrir no Excel e, se o CPF começar com
                 zero, o Excel o remove ao converter para número. O arquivo está certo; a
                 conversão é que não. --}}
            <div class="rotulo mt-1">UTF-8, separado por ponto e vírgula · importe CPF como texto</div>
        </div>
    </div>

    {{-- ---------- a tabela ---------- --}}
    @if ($ligacoes->total() === 0)
        <div class="mt-6 border border-[var(--rule)] p-8 text-center text-sm">
            <p class="text-base-content/70">Nenhuma ligação com esse filtro.</p>
            <p class="mt-2 text-base-content/50">
                As linhas aparecem conforme o dialer disca. Se a Campanha está no ar e não
                há nada aqui, confira o Horário de Trabalho na Configuração.
            </p>
        </div>
    @else
        <div class="mt-4 overflow-x-auto border border-[var(--rule)]">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-[var(--rule)] bg-[var(--bg-raised)]">
                        <th class="p-2 text-left"><span class="rotulo">Ligação</span></th>
                        <th class="p-2 text-left"><span class="rotulo">Telefone</span></th>
                        <th class="p-2 text-left"><span class="rotulo">Quando</span></th>
                        <th class="p-2 text-right"><span class="rotulo">Tent.</span></th>
                        <th class="p-2 text-right"><span class="rotulo">Falado</span></th>
                        <th class="p-2 text-left"><span class="rotulo">Desfecho</span></th>
                        @foreach ($campos as $campo)
                            <th class="p-2 text-left"><span class="rotulo">{{ $campo['nome'] }}</span></th>
                        @endforeach
                        <th class="p-2 text-left"><span class="rotulo">Causa</span></th>
                        {{-- O Disparo é o que acontece DEPOIS do Opt-in. Sem esta coluna o
                             operador via "opt_in" e não tinha como saber se a mensagem
                             chegou — e não chegar é o modo de falha caro. --}}
                        <th class="p-2 text-left"><span class="rotulo">Disparo</span></th>
                        <th class="p-2 text-left"><span class="rotulo">Gravação</span></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($ligacoes as $l)
                        @php
                            $respostas = \App\Livewire\Resultados::respostas($l->respostas);
                            $invalidas = \App\Livewire\Resultados::tentativasInvalidas($l->tentativas_invalidas);
                            $gravacao = is_string($l->gravacao) ? $l->gravacao : '';
                        @endphp
                        <tr class="border-b border-[var(--rule)] last:border-b-0 hover:bg-[var(--bg-hover)]">
                            <td class="identificador p-2">{{ $l->id }}</td>
                            <td class="identificador p-2">{{ $l->telefone ?? '—' }}</td>
                            <td class="identificador p-2 text-xs">{{ \App\Suporte\Instante::local($l->iniciada_em) }}</td>
                            <td class="num p-2">{{ $l->numero_tentativa }}</td>
                            {{-- `duracao_falada_seg` e não `duracao_seg`: o que interessa é
                                 quanto tempo houve alguém na linha depois do ANSWER. A
                                 duração total inclui o toque, e uma chamada de 40 s que
                                 nunca foi atendida pareceria conversa. --}}
                            <td class="num p-2">{{ $l->duracao_falada_seg === null ? '—' : $l->duracao_falada_seg . 's' }}</td>
                            <td class="p-2">
                                @php
                                    $d = is_string($l->desfecho) ? $l->desfecho : '';
                                    $tinta = match ($d) {
                                        'opt_in', 'captura_concluida' => 'text-primary font-semibold',
                                        'erro' => 'text-error font-semibold',
                                        'captura_parcial' => 'text-warning',
                                        '' => 'text-base-content/50',
                                        default => 'text-base-content/70',
                                    };
                                @endphp
                                <span class="{{ $tinta }}">
                                    {{ $d === '' ? 'em curso' : (\App\Livewire\Resultados::DESFECHOS[$d] ?? $d) }}
                                </span>
                                @if ($l->tecla_digitada !== null)
                                    <span class="rotulo ml-1">tecla {{ $l->tecla_digitada }}</span>
                                @endif
                            </td>

                            @foreach ($campos as $campo)
                                {{-- Célula vazia quando a captura não chegou até aqui. Isso é
                                     dado, não falha de tela: mostra ONDE a URA perdeu a
                                     pessoa, e é o que diz qual pergunta está longa demais. --}}
                                <td class="identificador p-2">
                                    @if (isset($respostas[$campo['nome']]))
                                        {{ $respostas[$campo['nome']] }}
                                    @else
                                        @php $tentado = $invalidas[$campo['nome']] ?? []; @endphp

                                        {{-- 🔴 O QUE A PESSOA DIGITOU, mesmo reprovado.
                                             Antes era descartado e a célula ficava vazia: o
                                             operador via a captura falhar sem saber se foi
                                             dígito verificador errado, número curto, ou
                                             documento de outra pessoa — três causas com três
                                             correções diferentes.

                                             Marcado como inválido de propósito. Este valor NÃO
                                             sai no CSV: `respostas` é o que vai para o sistema
                                             do cliente, e CPF reprovado ali entraria como
                                             bom. --}}
                                        @foreach ($tentado as $v)
                                            <div class="text-warning" title="Digitado e reprovado na validação">
                                                {{ $v }} <span class="rotulo">inválido</span>
                                            </div>
                                        @endforeach
                                    @endif
                                </td>
                            @endforeach

                            <td class="identificador p-2 text-xs">
                                {{-- O código SIP é o que separa "a lista é ruim" de "a rota
                                     está ruim": 404 é número inexistente e aposenta o Lead;
                                     503 é o fornecedor. AMD entra junto porque `maquina` sem
                                     a causa não é auditável. --}}
                                {{ $l->codigo_sip ?? '' }}
                                @if ($l->amd_status !== null)
                                    <span class="rotulo">{{ $l->amd_status }}</span>
                                @endif
                            </td>

                            {{-- Estado do Disparo, e o botão só onde ele resolve algo. --}}
                            <td class="p-2 text-xs">
                                @if ($l->disparo_id === null)
                                    <span class="text-base-content/40">—</span>
                                @else
                                    @php
                                        $falhou = in_array($l->disparo_status, ['falhou', 'pausado', 'limite_destinatario'], true);
                                    @endphp

                                    <span class="{{ $falhou ? 'text-warning' : 'text-base-content/70' }}">
                                        {{ $l->disparo_status }}
                                    </span>

                                    @if ($l->disparo_tentativas > 1)
                                        <span class="rotulo ml-1">{{ $l->disparo_tentativas }} tent.</span>
                                    @endif

                                    {{-- A mensagem que a META escreveu, não uma paráfrase nossa:
                                         é ela que diz "moeda não configurada" em vez de "erro". --}}
                                    @if ($falhou && $l->disparo_erro !== null)
                                        <p class="mt-1 max-w-xs text-base-content/50">{{ \Illuminate\Support\Str::limit($l->disparo_erro, 120) }}</p>
                                    @endif

                                    @if ($falhou)
                                        <button type="button" wire:click="reenviar({{ $l->disparo_id }})"
                                                wire:confirm="Reenviar o Disparo deste Lead?"
                                                class="btn btn-ghost btn-xs mt-1">reenviar</button>
                                    @endif
                                @endif
                            </td>

                            <td class="p-2 text-xs">
                                {{-- Três respostas, não duas: existe, não existe, ou não
                                     sei dizer. `is_file() === false` não prova ausência —
                                     prova que este processo não conseguiu ver. --}}
                                @switch (\App\Livewire\Resultados::situacaoDaGravacao($gravacao))
                                    @case (\App\Dominio\SituacaoDoArquivo::SEM_CAMINHO)
                                        <span class="text-base-content/40">—</span>
                                        @break

                                    @case (\App\Dominio\SituacaoDoArquivo::EXISTE)
                                        {{-- 🔴 `preload="none"` é obrigatório, não enfeite.
                                             Cada play converte o GSM para WAV no servidor; com
                                             o padrão `metadata` o navegador buscaria as 50
                                             gravações da página ao abri-la, disparando 50
                                             conversões que ninguém pediu. Com `none` o servidor
                                             só trabalha quando alguém aperta play. --}}
                                        <audio controls preload="none" class="h-8 w-44 align-middle">
                                            <source src="{{ route('gravacao', ['ligacao' => $l->id]) }}" type="audio/wav">
                                        </audio>
                                        <div class="identificador mt-0.5 text-base-content/50">{{ basename($gravacao) }}</div>
                                        @break

                                    @case (\App\Dominio\SituacaoDoArquivo::SEM_ACESSO)
                                        {{-- Não acusa o Asterisk de um problema que pode ser
                                             nosso: o painel roda como `prospecta`, e as
                                             gravações são `asterisk:asterisk` 2770. Grupo
                                             suplementar é fixado no `exec` — adesão concedida
                                             depois de o php-fpm subir não vale até reiniciar. --}}
                                        <span class="text-base-content/50"
                                              title="O painel não consegue atravessar {{ dirname($gravacao) }}. Confira se o usuário do php-fpm está no grupo asterisk e reinicie o serviço.">
                                            não consigo verificar
                                        </span>
                                        @break

                                    @default
                                        {{-- 🔴 AQUI SIM É DEFEITO, e agora o aviso é confiável.
                                             Com o diretório sem permissão o MixMonitor erra no
                                             log do Asterisk e a chamada segue normal, deixando
                                             um caminho para arquivo que nunca existiu. --}}
                                        <span class="text-warning" title="{{ $gravacao }}">não está no disco</span>
                                @endswitch
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="mt-4">
            {{ $ligacoes->links() }}
        </div>
    @endif
</div>

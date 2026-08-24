<div class="max-w-2xl">
    <div class="flex items-baseline justify-between border-b border-[var(--rule)] pb-3">
        <h1 class="text-lg font-semibold tracking-tight">Configuração</h1>
        <a href="{{ route('campanhas') }}" class="rotulo hover:text-primary">Campanhas</a>
    </div>

    @if (session('recado'))
        <div class="mt-4 border border-[var(--rule)] p-3 text-sm text-base-content/70">{{ session('recado') }}</div>
    @endif

    <form wire:submit="salvar" class="mt-6 flex flex-col gap-8">

        <section class="flex flex-col gap-4">
            <h2 class="rotulo border-b border-[var(--rule)] pb-1">Horário de Trabalho</h2>

            {{-- 🔴 Mostra o RELÓGIO DA APLICAÇÃO, não o do navegador. O servidor pode
                 rodar em UTC — esta máquina roda — e uma janela de 09h às 18h aplicada
                 em UTC vira 06h às 15h no Brasil. Ver o fuso na tela é o que evita o
                 operador configurar certo e a discadora agir errado. --}}
            <div class="flex flex-wrap items-baseline gap-x-6 gap-y-1 border border-[var(--rule)] p-3 text-sm">
                <span class="text-base-content/60">
                    Agora, no relógio da aplicação:
                    <span class="identificador text-base-content">{{ $agora }}</span>
                    <span class="rotulo">{{ $fuso }}</span>
                </span>
                <span class="{{ $abertaAgora ? 'text-primary' : 'text-warning' }}">
                    {{ $abertaAgora ? 'discando agora' : 'fora do horário agora' }}
                </span>
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <x-input label="Início" wire:model.live="horario_inicio" type="time" class="identificador" />
                <x-input label="Fim" wire:model.live="horario_fim" type="time" class="identificador" />
            </div>

            <div>
                <p class="rotulo mb-2">Dias</p>
                <div class="flex flex-wrap gap-x-5 gap-y-2">
                    @foreach (App\Livewire\Configuracao::DIAS as $n => $nome)
                        <x-checkbox label="{{ $nome }}" wire:model.live="dias_semana" value="{{ $n }}" />
                    @endforeach
                </div>
                <p class="mt-2 text-sm text-base-content/60">
                    Nenhum dia marcado significa todos os dias.
                </p>
            </div>

            <div class="border border-[var(--rule)] p-3 text-sm">
                <span class="rotulo">Em vigor</span>
                <p class="mt-1 identificador">{{ $descricao }}</p>
            </div>

            {{-- Isto é comportamento real e vale dizer: o operador precisa saber que
                 fechar a janela não corta quem está falando. --}}
            <p class="text-sm text-base-content/60">
                O horário governa a <span class="text-base-content">originação</span>.
                Chamada em andamento quando o horário fecha não é cortada — quem atendeu
                termina a conversa. Os dois campos em branco significam sem restrição.
            </p>
        </section>

        <section class="flex flex-col gap-4">
            <h2 class="rotulo border-b border-[var(--rule)] pb-1">Gravação</h2>

            <x-checkbox label="Gravar as chamadas" wire:model="gravacao_ativa" />

            <x-input label="Apagar a gravação depois de (dias)" wire:model="gravacao_retencao_dias"
                     type="number" min="1" max="3650" class="num" />

            <p class="text-sm text-base-content/60">
                A gravação existe porque o falso positivo do classificador só é
                mensurável ouvindo. Apagar remove o arquivo, nunca a linha da chamada —
                o histórico permanece.
            </p>
        </section>

        <section class="flex flex-col gap-4">
            <h2 class="rotulo border-b border-[var(--rule)] pb-1">Pós-atendimento</h2>

            <x-checkbox label="Classificar quem atendeu (humano ou máquina)"
                        wire:model.live="classificador_pos_ativo" />

            <p class="text-sm text-base-content/60">
                A classificação acontece DEPOIS que a linha atende e decide se a Mensagem é
                entregue. Desligada, toda Ligação atendida recebe a Mensagem inteira —
                inclusive as que caem em secretária eletrônica — e o desfecho
                <span class="identificador">maquina</span> deixa de existir.
            </p>

            {{-- 🔴 O aviso do defeito JÁ GRAVADO. A validação de `salvar()` não bloqueia
                 combinação herdada — travaria a tela inteira por causa de um número que
                 outra pessoa deixou ali —, então quem denuncia é este bloco, toda vez que
                 a tela abre, com o valor e a consequência. --}}
            @if ($posMortos !== [])
                <div class="border border-error/60 p-4 text-sm">
                    <p class="text-error font-semibold">
                        Critério que nunca é alcançado: {{ implode(', ', $posMortos) }}.
                    </p>
                    <p class="mt-1 text-base-content/70">
                        A análise total está em {{ $posAnaliseTotalGravado }} ms, e um critério
                        que pede tempo igual ou maior que ela jamais dispara — o relógio para
                        antes. O efeito não aparece como erro em lugar nenhum: a classificação
                        volta sem decisão, a Mensagem toca do mesmo jeito e o canal fica ocupado
                        durante toda a análise inútil. Corrija abaixo e salve.
                    </p>
                </div>
            @endif

            @if ($classificador_pos_ativo)
                {{-- 🔴 Rótulo, mínimo e máximo vêm de `App\Dominio\PosAtendimento`, os
                     MESMOS que a validação usa. Escritos à mão aqui, o input aceitava um
                     teto e a regra outro, e o MySQL truncava no meio — o operador via um
                     número na tela e o classificador recebia outro.

                     A ORDEM da tela não é a ordem posicional do AMD, e é de propósito: a
                     análise total e os critérios medidos contra ela ficam juntos na
                     primeira linha, para a relação ser visível ANTES de alguém salvar. --}}
                @php
                    $pp = [];
                    foreach ($posParametros as $p) { $pp[$p->coluna] = $p; }
                    $campo = fn (string $c) => $pp[$c];
                @endphp

                <div class="grid gap-4 sm:grid-cols-2">
                    @foreach (['pos_analise_total_ms', 'pos_silencio_inicial_ms', 'pos_saudacao_max_ms', 'pos_palavra_max_ms'] as $c)
                        <x-input :label="\App\Dominio\PosAtendimento::etiqueta($campo($c))"
                                 wire:model="{{ $c }}" type="number"
                                 min="{{ $campo($c)->minimo }}" max="{{ $campo($c)->maximo }}" class="num" />
                    @endforeach
                </div>

                <p class="text-sm text-warning">
                    {{ implode(' e ', $posRotulosDaAnalise) }} precisam ser MENORES que a
                    análise total. Um critério que pede mais tempo do que o orçamento da
                    análise nunca é alcançado — e isso não gera erro nenhum: a classificação
                    volta sem decisão, a Mensagem toca do mesmo jeito, e a causa fica
                    invisível. A palavra máxima fica FORA desta conta de propósito: ela não
                    é um prazo a vencer, e o próprio Asterisk a entrega igual à análise
                    total no arquivo de fábrica.
                </p>

                <div class="grid gap-4 sm:grid-cols-3">
                    @foreach (['pos_silencio_apos_saudacao_ms', 'pos_palavra_min_ms', 'pos_silencio_entre_palavras_ms', 'pos_palavras_max', 'pos_limiar_silencio'] as $c)
                        <x-input :label="\App\Dominio\PosAtendimento::etiqueta($campo($c))"
                                 wire:model="{{ $c }}" type="number"
                                 min="{{ $campo($c)->minimo }}" max="{{ $campo($c)->maximo }}" class="num" />
                    @endforeach
                </div>

                <p class="text-sm text-base-content/60">
                    Acima do máximo de palavras, quem atendeu é tratado como máquina — uma
                    saudação longa é a assinatura da secretária eletrônica. O limiar de
                    silêncio é o nível de ruído abaixo do qual a linha conta como calada, e
                    depende da rota: linha chiada precisa de um valor mais alto.
                </p>
            @endif
        </section>

        {{-- Honestidade sobre o que a tela NÃO cobre. Sem isto, a ausência lê como
             recurso faltando em vez de decisão, e alguém procura o campo. --}}
        <section class="border border-[var(--rule)] p-4 text-sm text-base-content/60">
            <span class="rotulo">O que não está aqui</span>
            <p class="mt-2">
                A Rota tem tela própria, porque mexer nela recarrega o Asterisk. As colunas
                de áudio de espera e áudio do classificador existem no banco mas nenhum
                processo as lê — por isso não aparecem: campo que ninguém consome é promessa
                falsa. A mensagem genérica é o contrário e está pendente: o handler já a lê
                como reserva, e ainda não há como escolhê-la aqui.
            </p>
        </section>

        <div class="flex items-center gap-3 border-t border-[var(--rule)] pt-6">
            <x-button type="submit" label="Salvar" class="btn-primary" spinner="salvar" />
        </div>
    </form>
</div>

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

        {{-- Honestidade sobre o que a tela NÃO cobre. Sem isto, a ausência lê como
             recurso faltando em vez de decisão, e alguém procura o campo. --}}
        <section class="border border-[var(--rule)] p-4 text-sm text-base-content/60">
            <span class="rotulo">O que não está aqui</span>
            <p class="mt-2">
                O tronco SIP é configurado no provisionamento, não pelo painel. E as
                colunas de áudio de espera, áudio do classificador e mensagem genérica
                existem no banco mas nenhum processo as lê ainda — por isso não
                aparecem: campo que ninguém consome é promessa falsa.
            </p>
        </section>

        <div class="flex items-center gap-3 border-t border-[var(--rule)] pt-6">
            <x-button type="submit" label="Salvar" class="btn-primary" spinner="salvar" />
        </div>
    </form>
</div>

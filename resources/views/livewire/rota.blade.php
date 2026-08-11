<div class="max-w-2xl">
    <div class="flex items-baseline justify-between border-b border-[var(--rule)] pb-3">
        <div class="flex items-baseline gap-3">
            <h1 class="text-lg font-semibold tracking-tight">Rota</h1>
            <span class="rotulo">{{ $configurada ? 'configurada' : 'não configurada' }}</span>
        </div>
        <a href="{{ route('campanhas') }}" class="rotulo hover:text-primary">Campanhas</a>
    </div>

    @if (session('recado'))
        <div class="mt-4 border border-primary/50 p-3 text-sm text-primary">{{ session('recado') }}</div>
    @endif
    @if (session('recusa'))
        <div class="mt-4 border border-warning/50 p-3 text-sm text-warning">{{ session('recusa') }}</div>
    @endif

    <p class="mt-4 text-sm text-base-content/70">
        O caminho pelo qual as Ligações saem para a operadora. Salvar aqui reescreve a
        configuração do Asterisk e manda recarregar — é a única tela do painel que faz isso.
    </p>

    @unless ($configurada)
        <div class="mt-4 border border-warning/50 p-4 text-sm">
            <p class="text-warning">Sem Rota, o dialer não origina</p>
            <p class="mt-1 text-base-content/70">
                {{-- Honesto sobre a consequência: o dialer RECUSA em vez de discar para um
                     endpoint que não existe, o que sairia como falha de tronco e mandaria o
                     operador investigar o fornecedor. --}}
                Ele recusa a Campanha e diz por quê, em vez de discar para um endpoint que
                não existe — falha que pareceria problema do fornecedor.
            </p>
        </div>
    @endunless

    <form wire:submit="salvar" class="mt-6 flex flex-col gap-4">

        <x-input label="Host" wire:model="host" placeholder="200.1.2.3 ou sip.fornecedor.com"
                 class="identificador" />
        <p class="-mt-3 text-sm text-base-content/60">
            IP ou nome do fornecedor. É para onde o INVITE vai, e também o que autoriza a
            chamada de entrada dele.
        </p>

        <div class="grid gap-4 sm:grid-cols-2">
            <x-input label="Usuário" wire:model="usuario" class="identificador" />

            <div>
                <x-input label="Senha" wire:model="senha" type="password"
                         placeholder="{{ $senhaMascarada !== '' ? 'gravada: ' . $senhaMascarada : 'sem senha gravada' }}" />
                <p class="mt-1 text-sm text-base-content/60">
                    {{-- Dito porque o campo em branco parece "apagar". Preservar é o
                         comportamento certo: quem vem trocar o prefixo não deveria perder a
                         credencial por não redigitá-la, e senha vazia derrubaria a rota na
                         próxima recarga. --}}
                    Em branco, mantém a que já está gravada.
                </p>
            </div>
        </div>

        <x-input label="Prefixo técnico" wire:model="tech_prefix" placeholder="99901"
                 class="identificador" />
        <div class="-mt-3 border border-warning/40 p-3 text-sm">
            {{-- 🔴 Não é detalhe de roteamento. Medido: fora da rota Platinum da IDT o
                 CallerID e o DTMF falham em SILÊNCIO — e o produto inteiro é "escute e
                 digite uma tecla". O operador precisa ver isso ao lado do campo, não num
                 comentário de código. --}}
            <p class="text-warning">Isto escolhe a rota comercial, não só o caminho</p>
            <p class="mt-1 text-base-content/70">
                Na IDT, <span class="identificador">99901</span> é a Platinum, onde CallerID
                e DTMF funcionam. Em rota mais barata os dois falham <strong>em
                silêncio</strong> — sem erro em log nenhum, e o produto inteiro é “escute e
                digite uma tecla”. O sintoma seria Campanha sem resposta nenhuma.
            </p>
        </div>

        <x-input label="from_domain" wire:model="from_dominio" placeholder="sip.fornecedor.com"
                 class="identificador" />

        <div>
            <x-input label="from_user" wire:model="from_usuario" class="identificador" />
            <div class="mt-1 border border-error/40 p-3 text-sm">
                {{-- 🔴 O aviso mais importante desta tela. Medido em 2026-08-09: com
                     from_user setado, o From do INVITE saiu com o valor dele, ignorando
                     Set(CALLERID(num)=...). Removendo, o From passou a levar a BINA e a
                     rota ACEITOU. --}}
                <p class="text-error">Deixe vazio, a menos que o fornecedor exija</p>
                <p class="mt-1 text-base-content/70">
                    Preenchido, ele <strong>sobrepõe o CallerID</strong> e torna toda Regra
                    de BINA inócua: o painel mostra a BINA escolhida e a rota manda este
                    valor, sem erro em lugar nenhum. Medido em 09/08 — removendo-o, a BINA
                    passou a sair e a rota aceitou.
                </p>
            </div>
        </div>

        <div class="flex items-center justify-between border-t border-[var(--rule)] pt-4">
            <span class="rotulo">salvar recarrega o Asterisk</span>
            <div class="flex gap-2">
                @if ($configurada)
                    {{-- Para quem consertou permissão e quer só reaplicar o que já está
                         salvo, sem redigitar a senha. --}}
                    <button type="button" wire:click="aplicar" class="btn btn-sm">Aplicar de novo</button>
                @endif
                <button type="submit" class="btn btn-sm btn-primary">Salvar e aplicar</button>
            </div>
        </div>
    </form>
</div>

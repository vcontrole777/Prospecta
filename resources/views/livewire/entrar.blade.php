{{--
    Registro suíço aplicado ao menor formulário do produto: régua em vez de
    sombra, canto reto, um acento só. O `<x-input>` do Mary já herda tudo isso do
    tema (ADR-0008), então não há override aqui.
--}}
<div class="w-full max-w-sm">
    <div class="border border-[var(--rule)] p-8">
        <div class="border-b border-[var(--rule)] pb-4">
            <h1 class="text-lg font-semibold tracking-tight">Prospecta</h1>
            <p class="rotulo mt-1">acesso ao painel</p>
        </div>

        <form wire:submit="entrar" class="mt-6 flex flex-col gap-4">
            <x-input
                label="E-mail"
                wire:model="email"
                type="email"
                autocomplete="username"
                autofocus
            />

            <x-input
                label="Senha"
                wire:model="senha"
                type="password"
                autocomplete="current-password"
            />

            <x-checkbox label="Manter conectado" wire:model="lembrar" />

            {{-- `wire:target` no próprio submit: sem isso o indicador dispararia
                 em qualquer atualização do componente, inclusive na digitação. --}}
            <x-button
                type="submit"
                label="Entrar"
                class="btn-primary w-full"
                spinner="entrar"
            />
        </form>
    </div>

    {{-- Diz para onde ir quando a senha se perdeu, porque NÃO existe fluxo de
         redefinição por e-mail e uma tela que se cala nesse ponto faz o operador
         procurar um link que não existe. --}}
    <p class="mt-4 text-center text-xs text-base-content/50">
        Esqueceu a senha? Ela é redefinida pelo fornecedor — não há recuperação
        por e-mail neste painel.
    </p>
</div>

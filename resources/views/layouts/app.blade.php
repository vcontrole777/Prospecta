{{--
    Shell do painel. É o layout que o Livewire 4 resolve por padrão, via
    `component_layout => 'layouts::app'` e o namespace de componente `layouts`.

    Sem sidebar de propósito. Com uma tela, um menu de um item é ruído; ela entra
    quando houver três. O `<x-main>` do Mary aceita o slot `sidebar` quando for a
    hora, sem reescrever este arquivo.

    🔴 NADA de classe no elemento <html>. O `<x-theme-toggle>` do Mary faz
    `documentElement.setAttribute("class", ...)`, que APAGA o atributo inteiro em
    vez de acrescentar. Não usamos o toggle hoje (ver abaixo), mas quem for
    adotá-lo precisa saber que classe em <html> não sobrevive.
--}}
<!DOCTYPE html>
<html lang="pt-BR" data-theme="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>{{ $title ?? 'Prospecta' }}</title>

    {{-- Sem fonte remota: ver o comentário no vite.config.js. --}}
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>

{{--
    `data-theme` fixo em "dark" porque o ADR-0008 decidiu escuro como padrão — o
    operador acompanha o monitor por horas.

    O `<x-theme-toggle>` do Mary NÃO serve como está: ele deriva o tema inicial
    de `prefers-color-scheme`, então um operador com sistema claro cairia no tema
    claro, contrariando a decisão. O CSS dos dois temas está pronto e verificado,
    e `[data-theme="light"]` funciona se trocado à mão — o que falta é só um
    toggle que respeite "escuro por padrão".
--}}
<body class="min-h-dvh bg-base-100 text-base-content antialiased">
    <x-nav sticky full-width>
        <x-slot:brand>
            <div class="flex items-baseline gap-2">
                <span class="text-base font-semibold tracking-tight">Prospecta</span>
                <span class="rotulo">discadora</span>
            </div>
        </x-slot:brand>

        <x-slot:actions>
            <div class="flex items-center gap-4">
                <span class="rotulo">{{ auth()->user()?->name }}</span>

                {{-- Formulário e não link: saída por GET é acionável por qualquer
                     imagem de terceiro apontando para /sair, o que derrubaria a
                     sessão do operador no meio de uma campanha. --}}
                <form method="POST" action="{{ route('sair') }}">
                    @csrf
                    <button type="submit" class="btn btn-ghost btn-sm">Sair</button>
                </form>
            </div>
        </x-slot:actions>
    </x-nav>

    <x-main with-nav full-width>
        <x-slot:content>
            {{ $slot }}
        </x-slot:content>
    </x-main>
</body>
</html>

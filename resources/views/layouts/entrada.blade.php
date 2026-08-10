{{--
    Layout de convidado. Sem `<x-nav>` e sem `<x-main>`: não há navegação para
    quem não entrou, e um cromo de aplicação em volta de um formulário de login
    sugere que existe algo acessível ali.

    Mesma regra do layout do painel: NADA de classe em <html>.
--}}
<!DOCTYPE html>
<html lang="pt-BR" data-theme="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>{{ $title ?? 'Entrar · Prospecta' }}</title>

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>

<body class="min-h-dvh bg-base-100 text-base-content antialiased">
    <div class="flex min-h-dvh items-center justify-center p-6">
        {{ $slot }}
    </div>
</body>
</html>

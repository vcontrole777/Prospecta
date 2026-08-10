<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // 🔴 OBRIGATÓRIO no Laravel 13, não é conveniência.
        //
        // A convenção antiga de o middleware `auth` procurar uma rota chamada
        // `login` não existe mais: sem destino registrado, o handler devolve
        // `204 No Content` com status 401. O operador veria uma página em branco
        // em vez do formulário, e nada no log diria por quê.
        $middleware->redirectGuestsTo('/entrar');

        // O contrário também: quem já entrou e visita /entrar vai para o painel.
        $middleware->redirectUsersTo('/');

        // 🔴 O webhook da Meta NÃO tem token CSRF, e não teria como ter: quem chama é
        // máquina, sem sessão e sem formulário. Sem esta exclusão todo POST dela volta
        // 419 — e 419 é não-200, então a Meta reentrega o mesmo evento em laço,
        // indefinidamente, enquanto o log do nginx enche de 419 que ninguém liga a
        // CSRF. O que protege a rota é a assinatura, verificada no controller.
        $middleware->validateCsrfTokens(except: ['webhook/*']);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();

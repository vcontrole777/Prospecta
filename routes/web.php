<?php

declare(strict_types=1);

use App\Livewire\CampanhaForm;
use App\Livewire\Audios;
use App\Livewire\Campanhas;
use App\Livewire\Configuracao;
use App\Livewire\Conversas;
use App\Livewire\ImportarLista;
use App\Http\Controllers\WebhookWhatsapp;
use App\Livewire\Integracoes;
use App\Livewire\Entrar;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Painel
|--------------------------------------------------------------------------
| Produto de usuário único, uma VPS por cliente, não multi-inquilino.
|
| Não existe rota de CADASTRO nem de redefinição de senha por e-mail. As duas
| ausências são deliberadas — ver `App\Livewire\Entrar`. O operador é criado por
| `php artisan prospecta:operador`.
*/

/*
| A rota se chama `login` porque é o nome que o resto do framework espera em
| convenção; o caminho é /entrar porque a interface é em português. O redirect de
| convidado está em bootstrap/app.php, e no Laravel 13 ele é OBRIGATÓRIO: sem ele
| o acesso não autenticado devolve 401 sem corpo em vez do formulário.
*/
/*
| 🔴 WEBHOOK: PÚBLICO, e tem que ser.
|
| Quem chama é a Meta, não o operador — pôr atrás de `auth` faria todo evento voltar
| como redirecionamento para o formulário de login, e a Meta reentregaria em laço.
|
| O que protege é a assinatura `X-Hub-Signature-256`, verificada no controller. E o
| CSRF é excluído em bootstrap/app.php: sem isso todo POST da Meta volta 419, que
| também é não-200 e também gera reentrega em laço.
*/
Route::get('/webhook/whatsapp', [WebhookWhatsapp::class, 'verificar'])->name('webhook.whatsapp.verificar');
Route::post('/webhook/whatsapp', [WebhookWhatsapp::class, 'receber'])->name('webhook.whatsapp');

Route::middleware('guest')->group(function (): void {
    Route::get('/entrar', Entrar::class)->name('login');
});

Route::middleware('auth')->group(function (): void {
    Route::get('/', Campanhas::class)->name('campanhas');

    Route::get('/campanhas/nova', CampanhaForm::class)->name('campanha.nova');
    Route::get('/campanhas/{id}/editar', CampanhaForm::class)->name('campanha.editar');
    Route::get('/campanhas/{id}/importar', ImportarLista::class)->name('campanha.importar');

    Route::get('/audios', Audios::class)->name('audios');
    Route::get('/integracoes', Integracoes::class)->name('integracoes');
    Route::get('/conversas', Conversas::class)->name('conversas');
    Route::get('/configuracao', Configuracao::class)->name('configuracao');

    /*
    | POST e não GET: saída por GET é acionável por qualquer imagem ou link de
    | terceiro apontando para /sair, o que derrubaria a sessão do operador no meio
    | de uma campanha. Com POST o token CSRF é exigido.
    */
    Route::post('/sair', function (Request $request) {
        Auth::logout();

        // Os dois, e nesta ordem. `invalidate` descarta os dados da sessão;
        // `regenerateToken` troca o token CSRF, sem o que o token antigo
        // continuaria válido para a próxima sessão criada no mesmo navegador.
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    })->name('sair');
});

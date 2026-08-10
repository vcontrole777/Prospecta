<?php

declare(strict_types=1);

use App\Livewire\Campanhas;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Painel
|--------------------------------------------------------------------------
| Produto de usuário único, uma VPS por cliente, não multi-inquilino.
|
| 🔴 SEM autenticação ainda. O painel é servido em domínio público com
| certificado (ver `dominio` no provisionamento), então isto NÃO pode ir para
| produção como está. Login é o próximo passo depois desta tela — pequeno, e
| separável justamente por ser usuário único.
*/
Route::get('/', Campanhas::class)->name('campanhas');

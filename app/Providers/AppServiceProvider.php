<?php

namespace App\Providers;

use App\Worker\Transporte;
use App\Worker\TransporteCurl;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        /*
         * 🔴 O TRANSPORTE HTTP VEM DO CONTÊINER, e é o que torna a descoberta testável.
         *
         * A tela de Integrações consulta a Meta para achar o Phone Number ID. Instanciar o
         * `TransporteCurl` dentro do componente faria o teste da tela sair para a internet
         * com o token de alguém — ou, na prática, falhar por rede e não testar nada.
         *
         * O worker NÃO usa isto: ele roda com bootstrap mínimo (ADR-0001), sem contêiner, e
         * constrói o transporte à mão. Este vínculo é do painel.
         */
        $this->app->bind(Transporte::class, TransporteCurl::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}

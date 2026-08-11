<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 🔴 `tronco_*` VIRA `rota_*`, e agora é de graça.
     *
     * O glossário passou a chamar de **Rota** o caminho de saída, porque foi a palavra que
     * o operador escolheu para a tela. Duas palavras para a mesma coisa é exatamente como
     * o vocabulário se parte: "tronco" na coluna, "Rota" no rótulo, e a próxima pessoa
     * (ou eu, em outra sessão) acha que são conceitos diferentes.
     *
     * Renomear AGORA custa nada: as seis colunas eram órfãs — nenhum processo as lia, o
     * tronco vivia no `pjsip.conf` gerado pelo Ansible. Depois de a tela passar a escrever
     * nelas, o mesmo renome exigiria coordenar migration, painel e dialer.
     *
     * `tronco_ip` vira `rota_host` e não `rota_ip`: o campo aceita nome de domínio, e
     * chamá-lo de IP faz o operador achar que precisa resolver o DNS na mão.
     *
     * `rota_senha` é TEXT porque guarda o envelope cifrado do `Cofre`, não a senha.
     */
    public function up(): void
    {
        Schema::table('configuracao', function (Blueprint $t) {
            $t->renameColumn('tronco_ip', 'rota_host');
            $t->renameColumn('tronco_user', 'rota_usuario');
            $t->renameColumn('tronco_password', 'rota_senha');
            $t->renameColumn('tronco_from_user', 'rota_from_usuario');
            $t->renameColumn('tronco_from_domain', 'rota_from_dominio');
            $t->renameColumn('tronco_tech_prefix', 'rota_tech_prefix');
        });
    }

    public function down(): void
    {
        Schema::table('configuracao', function (Blueprint $t) {
            $t->renameColumn('rota_host', 'tronco_ip');
            $t->renameColumn('rota_usuario', 'tronco_user');
            $t->renameColumn('rota_senha', 'tronco_password');
            $t->renameColumn('rota_from_usuario', 'tronco_from_user');
            $t->renameColumn('rota_from_dominio', 'tronco_from_domain');
            $t->renameColumn('rota_tech_prefix', 'tronco_tech_prefix');
        });
    }
};

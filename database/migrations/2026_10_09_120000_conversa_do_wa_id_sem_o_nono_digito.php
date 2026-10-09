<?php

use App\Dominio\Telefone;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Junta as Conversas que o `wa_id` sem o nono dígito partiu em duas.
     *
     * 🔴 A META MANDA CELULAR ANTIGO COM 12 DÍGITOS, e `registrarEntrada` gravava esse
     * número cru. Toda resposta a um Disparo abria uma Conversa NOVA, órfã, ao lado da
     * Conversa do Disparo (13 dígitos, a do Lead). Medido em 09/10/2026: 15 Conversas de
     * 12 dígitos, todas com a gêmea de 13, nenhuma vinculada a Lead. A do Disparo ficava
     * com a janela de 24 horas fechada, porque a entrada caía na outra.
     *
     * O código novo (`Telefone::deWaId`) para de criar essas linhas; esta migration
     * conserta as que já existem:
     *
     *   · com gêmea de 13 dígitos: as mensagens passam para a gêmea, a janela fica com a
     *     entrada mais recente das duas, e a de 12 é apagada
     *   · sem gêmea: a própria Conversa passa a ter o telefone de 13 e ganha o Lead
     *
     * E o mesmo para `bloqueio`: um opt-out gravado com 12 dígitos não bloqueava nada.
     *
     * Só toca no que `deWaId` reconhece como celular brasileiro. Número estrangeiro de 12
     * dígitos fica como está.
     *
     * Sem `down`: desfazer seria partir de novo a Conversa de uma pessoa em duas.
     */
    public function up(): void
    {
        $t = new Telefone();

        DB::transaction(function () use ($t): void {
            $orfas = DB::table('conversa')->whereRaw('LENGTH(telefone) = 12')->get();

            foreach ($orfas as $c) {
                $canonico = $t->tentarWaId((string) $c->telefone);
                if ($canonico === null || $canonico === $c->telefone) {
                    continue;
                }

                $lead = DB::table('leads')->where('telefone', $canonico)->orderBy('id')->value('id');
                $gemea = DB::table('conversa')->where('telefone', $canonico)->first();

                if ($gemea === null) {
                    DB::table('conversa')->where('id', $c->id)->update([
                        'telefone' => $canonico,
                        'lead_id' => $c->lead_id ?? $lead,
                    ]);

                    continue;
                }

                DB::table('conversa_mensagem')->where('conversa_id', $c->id)->update(['conversa_id' => $gemea->id]);

                // A janela de 24 horas é a entrada MAIS RECENTE — e a entrada estava na órfã.
                $entradas = array_filter([$gemea->ultima_entrada_em, $c->ultima_entrada_em]);
                DB::table('conversa')->where('id', $gemea->id)->update([
                    'ultima_entrada_em' => $entradas === [] ? null : max($entradas),
                    'lead_id' => $gemea->lead_id ?? $c->lead_id ?? $lead,
                ]);

                DB::table('conversa')->where('id', $c->id)->delete();
            }

            foreach (DB::table('bloqueio')->whereRaw('LENGTH(telefone) = 12')->get() as $b) {
                $canonico = $t->tentarWaId((string) $b->telefone);
                if ($canonico === null || $canonico === $b->telefone) {
                    continue;
                }

                // `insertOrIgnore` por causa do UNIQUE: o canônico pode já estar bloqueado.
                DB::table('bloqueio')->insertOrIgnore([
                    'telefone' => $canonico,
                    'ligacao_id' => $b->ligacao_id,
                    'origem' => $b->origem,
                    'criado_em' => $b->criado_em,
                ]);
                DB::table('bloqueio')->where('telefone', $b->telefone)->delete();
            }
        });
    }
};

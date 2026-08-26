<?php

declare(strict_types=1);

namespace App\Sms;

/**
 * Verifica o `Authorization: Bearer <JWT>` que o httpSMS manda no webhook.
 *
 * 🔴 O httpSMS ASSINA DIFERENTE DA META, e a diferença importa. A Meta manda
 * `X-Hub-Signature-256`, que é um HMAC DO CORPO — quem confere sabe que aquele
 * corpo veio de quem tem o segredo. O httpSMS manda um JWT HS256, que prova que
 * QUEM CHAMOU conhece a chave, mas não amarra o token ao payload.
 *
 * Consequência aceita e nomeada: um token capturado pode, em tese, ser reapresentado
 * com outro corpo. O que protege na prática é o resto do caminho — o `id` do
 * CloudEvent dá idempotência, e um status só é aplicado a uma mensagem cujo id JÁ
 * está gravado numa Ação nossa. Evento forjado para id desconhecido não faz nada.
 *
 * 🔴 SEM BIBLIOTECA DE JWT, e a decisão é deliberada.
 *
 * A documentação do httpSMS recomenda uma, e a recomendação é boa no caso geral:
 * JWT tem armadilhas famosas — `alg: none` e confusão de algoritmo — que
 * implementação caseira erra. Aqui a superfície é UM algoritmo com segredo
 * compartilhado, o projeto já faz o equivalente para a Meta, e os dois ataques têm
 * teste. Trocar por biblioteca depois não mexe em nada fora desta classe.
 *
 * PURA por exigência do ADR-0006: sem framework, sem rede. O teste roda com strings.
 */
final class Assinatura
{
    /**
     * O token confere com a chave de assinatura?
     *
     * @param ?string $cabecalho o `Authorization` cru, com ou sem o `Bearer `
     * @param int $agora instante para conferir `exp`; parâmetro para o teste não
     *        depender do relógio
     */
    public function confere(?string $cabecalho, string $chave, int $agora = 0): bool
    {
        if ($cabecalho === null || $chave === '') {
            return false;
        }

        $token = str_starts_with($cabecalho, 'Bearer ') ? substr($cabecalho, 7) : $cabecalho;
        $partes = explode('.', trim($token));

        if (count($partes) !== 3) {
            return false;
        }

        [$cabecalhoB64, $corpoB64, $assinaturaB64] = $partes;

        $declarado = self::json($cabecalhoB64);
        if ($declarado === null) {
            return false;
        }

        /*
         * 🔴 SÓ HS256, e recusar o resto é o ponto desta classe.
         *
         * `alg: none` é o ataque de manual: o token vem sem assinatura e uma
         * implementação ingênua o aceita porque "não havia o que conferir". E a
         * confusão de algoritmo — mandar `RS256` para um verificador que usa a chave
         * como segredo simétrico — transforma a chave PÚBLICA em segredo válido.
         *
         * Aceitar apenas o que o httpSMS declara usar fecha os dois de uma vez.
         */
        if (($declarado['alg'] ?? null) !== 'HS256') {
            return false;
        }

        $esperada = self::b64url(hash_hmac('sha256', $cabecalhoB64 . '.' . $corpoB64, $chave, true));

        // `hash_equals` e não `===`: comparação de string vaza pelo tempo, e aqui o
        // atacante controla o valor comparado e pode medir.
        if (! hash_equals($esperada, $assinaturaB64)) {
            return false;
        }

        /*
         * `exp` só é conferido quando VEM. O httpSMS não documenta as claims, e
         * exigir uma que ele talvez não mande recusaria todo evento — falha fechada
         * que pareceria chave errada.
         */
        $corpo = self::json($corpoB64);
        $exp = $corpo['exp'] ?? null;

        if (is_numeric($exp) && $agora > 0 && (int) $exp < $agora) {
            return false;
        }

        return true;
    }

    /** @return array<array-key,mixed>|null */
    private static function json(string $b64): ?array
    {
        $cru = self::deB64url($b64);
        if ($cru === null) {
            return null;
        }

        $d = json_decode($cru, true);

        return is_array($d) ? $d : null;
    }

    private static function b64url(string $bruto): string
    {
        return rtrim(strtr(base64_encode($bruto), '+/', '-_'), '=');
    }

    private static function deB64url(string $b64): ?string
    {
        // `strict: true` para lixo não virar bytes plausíveis em silêncio.
        $r = base64_decode(strtr($b64, '-_', '+/'), true);

        return $r === false ? null : $r;
    }
}

<?php

declare(strict_types=1);

namespace App\Seguranca;

use RuntimeException;

/**
 * Cifra simétrica para credencial de fornecedor. PURA — só `openssl` e `hash`.
 *
 * 🔴 POR QUE NÃO O `Crypt` DO LARAVEL.
 *
 * A credencial é escrita pelo painel e lida pelo `bin/worker.php`, que roda com
 * bootstrap mínimo: autoload e PDO, sem framework (ADR-0001). O `Crypt` só existe
 * dentro do contêiner do Laravel, então cifrar com ele deixaria o worker com um
 * texto que ele não consegue abrir — e o sintoma seria "token inválido" vindo da
 * Meta, mandando o operador trocar uma chave que estava correta.
 *
 * Esta classe é o denominador comum: as duas pontas precisam apenas da `APP_KEY`.
 *
 * 🔴 CIFRA E AUTENTICA. Cifrar sem MAC permite que alguém com escrita no banco
 * altere bytes do texto cifrado e mude o texto claro de forma controlada. Numa
 * coluna que guarda token de envio de mensagem, isso é pior que vazamento: é
 * mensagem saindo no nome da empresa com destino escolhido por outro.
 *
 * Chaves de cifra e de autenticação são DERIVADAS separadamente da mestra. Usar a
 * mesma chave para as duas coisas funciona na prática e é o que muita
 * implementação faz, mas separar custa uma linha e remove a discussão.
 */
final class Cofre
{
    private const CIFRA = 'aes-256-cbc';

    private string $chaveCifra;

    private string $chaveMac;

    /**
     * @param string $chaveMestra a `APP_KEY`, com ou sem o prefixo `base64:`
     *
     * @throws RuntimeException quando a chave não serve — falhar aqui é melhor que
     *         cifrar com chave curta e descobrir na hora de decifrar
     */
    public function __construct(string $chaveMestra)
    {
        $bruta = str_starts_with($chaveMestra, 'base64:')
            ? (base64_decode(substr($chaveMestra, 7), true) ?: '')
            : $chaveMestra;

        if (strlen($bruta) < 32) {
            throw new RuntimeException(
                'APP_KEY inválida para cifrar credencial: precisa de 32 bytes. '
                . 'Gere com `php artisan key:generate --show`.',
            );
        }

        $this->chaveCifra = hash_hmac('sha256', 'prospecta:cifra:v1', $bruta, true);
        $this->chaveMac = hash_hmac('sha256', 'prospecta:mac:v1', $bruta, true);
    }

    public function cifrar(string $claro): string
    {
        $iv = random_bytes(16);

        $valor = openssl_encrypt($claro, self::CIFRA, $this->chaveCifra, OPENSSL_RAW_DATA, $iv);
        if ($valor === false) {
            throw new RuntimeException('falha ao cifrar');
        }

        // MAC sobre IV E texto cifrado. Só sobre o texto deixaria o IV livre para
        // alteração, e o IV muda o primeiro bloco do texto claro.
        $mac = hash_hmac('sha256', $iv . $valor, $this->chaveMac);

        $pacote = json_encode([
            'v' => 1,
            'iv' => base64_encode($iv),
            'valor' => base64_encode($valor),
            'mac' => $mac,
        ]);

        if ($pacote === false) {
            throw new RuntimeException('falha ao serializar o pacote cifrado');
        }

        return base64_encode($pacote);
    }

    /**
     * @throws RuntimeException quando o pacote é inválido, adulterado, ou a chave
     *         é outra. NUNCA devolve texto não autenticado.
     */
    public function decifrar(string $cifrado): string
    {
        $json = base64_decode($cifrado, true);
        if ($json === false) {
            throw new RuntimeException('pacote cifrado não é base64');
        }

        $p = json_decode($json, true);
        if (! is_array($p)) {
            throw new RuntimeException('pacote cifrado não é JSON');
        }

        $iv = is_string($p['iv'] ?? null) ? base64_decode($p['iv'], true) : false;
        $valor = is_string($p['valor'] ?? null) ? base64_decode($p['valor'], true) : false;
        $mac = is_string($p['mac'] ?? null) ? $p['mac'] : '';

        if ($iv === false || $valor === false || $mac === '' || strlen($iv) !== 16) {
            throw new RuntimeException('pacote cifrado incompleto');
        }

        // 🔴 VERIFICA O MAC ANTES DE DECIFRAR, e com comparação de tempo constante.
        //
        // Decifrar primeiro e conferir depois expõe o oráculo de preenchimento:
        // a diferença entre "preenchimento inválido" e "MAC inválido" vaza o texto
        // claro byte a byte. E `===` em string vaza pelo tempo de comparação.
        $esperado = hash_hmac('sha256', $iv . $valor, $this->chaveMac);
        if (! hash_equals($esperado, $mac)) {
            throw new RuntimeException(
                'credencial adulterada ou cifrada com outra APP_KEY. '
                . 'Trocar a APP_KEY torna toda credencial ilegível — recadastre a integração.',
            );
        }

        $claro = openssl_decrypt($valor, self::CIFRA, $this->chaveCifra, OPENSSL_RAW_DATA, $iv);
        if ($claro === false) {
            throw new RuntimeException('falha ao decifrar');
        }

        return $claro;
    }

    /**
     * Cifra um mapa de credenciais para a coluna `credenciais`, que é do tipo JSON.
     *
     * 🔴 O ENVELOPE EXISTE POR DUAS RAZÕES, e a segunda é a melhor.
     *
     * A primeira é mecânica: a coluna é `JSON` e o MySQL RECUSA o pacote cifrado
     * cru, porque base64 não é JSON válido — erro 3140, "Invalid JSON text".
     *
     * A segunda é de projeto: com `{"cifrado": "..."}` o discriminador entre
     * credencial cifrada e credencial em claro de antes da cifra é a PRESENÇA DA
     * CHAVE, explícita. A alternativa era inferir de uma falha de parse — "se não é
     * JSON, então está cifrado" — que é heurística e passa a errar no dia em que
     * alguém guardar qualquer outra coisa ali.
     *
     * Convenção num lugar só: painel e worker chamam este par, e não montam o
     * envelope por conta. Regra duplicada sempre diverge.
     *
     * @param array<string,mixed> $dados
     */
    public function cifrarParaColuna(array $dados): string
    {
        $claro = json_encode($dados, JSON_UNESCAPED_UNICODE);
        if ($claro === false) {
            throw new RuntimeException('credenciais não serializáveis');
        }

        $envelope = json_encode(['cifrado' => $this->cifrar($claro)]);
        if ($envelope === false) {
            throw new RuntimeException('falha ao montar o envelope');
        }

        return $envelope;
    }

    /**
     * Lê a coluna, cifrada ou em claro.
     *
     * A tolerância ao claro é deliberada e limitada: instalação que cadastrou
     * integração antes da cifra tem o mapa direto na coluna, e recusá-lo derrubaria
     * o envio de quem já estava funcionando. O que NÃO é tolerado é envelope cifrado
     * que falha o MAC — ali a exceção sobe, porque credencial adulterada não pode ser
     * usada nem ignorada em silêncio.
     *
     * @return array<string,mixed>
     */
    public function decifrarDaColuna(string $coluna): array
    {
        $lido = json_decode($coluna, true);
        if (! is_array($lido)) {
            return [];
        }

        $cifrado = $lido['cifrado'] ?? null;

        if (! is_string($cifrado)) {
            return self::comChavesDeTexto($lido);   // claro, de antes da cifra
        }

        $claro = json_decode($this->decifrar($cifrado), true);

        return is_array($claro) ? self::comChavesDeTexto($claro) : [];
    }

    /**
     * Normaliza as chaves para texto sem `@var` inline.
     *
     * `json_decode` devolve `array<array-key,mixed>`, e a assinatura promete
     * `array<string,mixed>`. Converter a chave é honesto; anotar o tipo por PHPDoc
     * seria afirmar algo que o código não garante — e foi o que produziu uma
     * checagem tautológica na primeira versão.
     *
     * @param array<array-key,mixed> $dados
     * @return array<string,mixed>
     */
    private static function comChavesDeTexto(array $dados): array
    {
        $saida = [];
        foreach ($dados as $chave => $valor) {
            $saida[(string) $chave] = $valor;
        }

        return $saida;
    }

    /**
     * Mostra só o suficiente para o operador reconhecer a chave sem ela ser
     * legível: os 4 últimos caracteres.
     *
     * Os PRIMEIROS não: prefixo de token costuma identificar o tipo e a conta, e é
     * a parte com valor para quem estiver olhando a tela por cima do ombro.
     */
    public function mascarar(string $claro, int $visiveis = 4): string
    {
        $n = mb_strlen($claro);
        if ($n === 0) {
            return '';
        }
        if ($n <= $visiveis) {
            return str_repeat('•', $n);
        }

        return str_repeat('•', min(24, $n - $visiveis)) . mb_substr($claro, -$visiveis);
    }
}

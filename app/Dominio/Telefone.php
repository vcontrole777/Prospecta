<?php

declare(strict_types=1);

namespace App\Dominio;

/**
 * Normalização de telefone para o formato canônico. PURA.
 *
 * 🔴 ACONTECE NA IMPORTAÇÃO, UMA VEZ. Normalizar na discagem significaria
 * normalizar N vezes, em código diferente, com resultado diferente. Feito aqui,
 * três coisas passam a funcionar de graça:
 *
 *   · a comparação com a lista de bloqueio, porque os dois lados ficam no mesmo
 *     formato
 *   · a unicidade por Campanha mais telefone
 *   · a derivação de DDD, que passa a ser `substring` e não um parser
 *
 * O canônico é `5511987654321`: país, DDD, número. Sem `+`, sem pontuação. É o
 * mesmo que `Bina` exige, e não é coincidência — a BINA deriva do destino por
 * posição de dígito.
 *
 * Rigor aqui é sancionado por medição, não por gosto. Cada recusa abaixo evita uma
 * falha que já foi medida contra tronco real:
 *   · DDD inexistente devolve `503`, que CONTA para a proteção e pode pausar a
 *     Campanha inteira por causa de uma planilha ruim
 *   · número não atribuído devolve `404`, que aposenta o Lead na hora
 * Recusar na importação troca uma chamada paga e um erro de tronco por uma linha
 * numa lista de rejeitados que o operador consegue consertar.
 */
final class Telefone
{
    /**
     * DDDs que existem no Brasil. NÃO é faixa contígua — 20, 23, 25, 26, 29, 36,
     * 39, 40, 50, 52, 56 a 60, 70, 72, 76, 78, 80 e 90 não existem.
     *
     * Tratar como intervalo `11..99` aceitaria dezoito DDDs inexistentes, e cada um
     * deles é um `503` que conta para a proteção.
     */
    private const DDDS = [
        11, 12, 13, 14, 15, 16, 17, 18, 19,
        21, 22, 24, 27, 28,
        31, 32, 33, 34, 35, 37, 38,
        41, 42, 43, 44, 45, 46, 47, 48, 49,
        51, 53, 54, 55,
        61, 62, 63, 64, 65, 66, 67, 68, 69,
        71, 73, 74, 75, 77, 79,
        81, 82, 83, 84, 85, 86, 87, 88, 89,
        91, 92, 93, 94, 95, 96, 97, 98, 99,
    ];

    /**
     * Devolve o canônico ou lança.
     *
     * @throws ErroDeDominio
     */
    public function canonico(string $bruto): string
    {
        $d = preg_replace('/\D/', '', $bruto) ?? '';

        if ($d === '') {
            throw ErroDeDominio::telefoneInvalido($bruto);
        }

        // `00` é prefixo de discagem internacional feita do Brasil: 00 55 11 9…
        // Some antes de qualquer outra leitura, senão o comprimento engana.
        if (str_starts_with($d, '00')) {
            $d = substr($d, 2);
        }

        // `0` sozinho é prefixo de operadora na discagem interurbana: 0 11 9…
        // Nenhum canônico começa com zero, então tirar é seguro.
        $d = ltrim($d, '0');

        $d = match (strlen($d)) {
            // 13 e 12 já têm país; a conferência do 55 vem depois.
            13, 12 => $d,

            // 🔴 11 dígitos é DDD + móvel de 9, SEM país. Prefixar 55 é a leitura
            // certa mesmo quando o número começa com 55: `55987654321` é o DDD 55,
            // que EXISTE — Santa Maria, no Rio Grande do Sul. Ler aquele 55 como
            // país deixaria 9 dígitos, que não é telefone nenhum.
            11 => '55' . $d,

            // 10 dígitos é DDD + fixo de 8, sem país.
            10 => '55' . $d,

            default => throw ErroDeDominio::telefoneInvalido($bruto),
        };

        if (! str_starts_with($d, '55')) {
            // Só atendemos o Brasil. Um 13 dígitos que não começa com 55 não é
            // interpretável, e adivinhar produziria chamada para outro país.
            throw ErroDeDominio::telefoneInvalido($bruto);
        }

        $ddd = (int) substr($d, 2, 2);
        if (! in_array($ddd, self::DDDS, true)) {
            throw ErroDeDominio::telefoneInvalido($bruto);
        }

        $assinante = substr($d, 4);

        // 🔴 Móvel brasileiro tem 9 dígitos e o primeiro é 9, desde 2016. Um
        // "móvel" de 9 dígitos que não começa com 9 é fixo com um dígito sobrando —
        // digitação, ou coluna deslocada na planilha — e discar aquilo é um `404`
        // garantido, que aposenta o Lead permanentemente.
        if (strlen($assinante) === 9 && ! str_starts_with($assinante, '9')) {
            throw ErroDeDominio::telefoneInvalido($bruto);
        }

        // Fixo tem 8 dígitos e começa em 2 a 5. Começar em 9 seria móvel sem o
        // nono dígito, que não completa desde a migração.
        if (strlen($assinante) === 8 && ! preg_match('/^[2-5]/', $assinante)) {
            throw ErroDeDominio::telefoneInvalido($bruto);
        }

        return $d;
    }

    /**
     * O canônico a partir do `wa_id` que a Meta manda no webhook.
     *
     * 🔴 A META IDENTIFICA CELULAR ANTIGO SEM O NONO DÍGITO. Quem registrou o WhatsApp
     * antes da migração de 2016 continua com `wa_id` de 12 dígitos — `551187654321` —,
     * mesmo que o número discável hoje seja `5511987654321`. Em produção, 463 de 480
     * mensagens recebidas vinham assim.
     *
     * Por que NÃO está em `canonico`: lá, móvel sem o 9 é recusado de propósito, porque
     * numa planilha ele é dado ruim e discar aquilo é `404`. Aqui é o formato documentado
     * de um fornecedor, e o 9 que falta é certo.
     *
     * A regra é a da migração: assinante de 8 dígitos começando em 6 a 9 era móvel e
     * ganhou o 9 na frente. Fixo (2 a 5) segue fixo — WhatsApp Business em linha fixa
     * existe.
     *
     * @throws ErroDeDominio
     */
    public function deWaId(string $waId): string
    {
        $d = preg_replace('/\D/', '', $waId) ?? '';

        if (strlen($d) === 12 && str_starts_with($d, '55') && preg_match('/^[6-9]/', substr($d, 4)) === 1) {
            $d = substr($d, 0, 4) . '9' . substr($d, 4);
        }

        return $this->canonico($d);
    }

    /** `deWaId`, ou `null` em vez de exceção. */
    public function tentarWaId(string $waId): ?string
    {
        try {
            return $this->deWaId($waId);
        } catch (ErroDeDominio) {
            return null;
        }
    }

    /** O canônico, ou `null` em vez de exceção. Para laço de importação. */
    public function tentar(string $bruto): ?string
    {
        try {
            return $this->canonico($bruto);
        } catch (ErroDeDominio) {
            return null;
        }
    }

    /** DDD por substring, que é o que a normalização na entrada compra. */
    public function ddd(string $canonico): string
    {
        return substr($canonico, 2, 2);
    }

    public function movel(string $canonico): bool
    {
        return strlen($canonico) === 13;
    }
}

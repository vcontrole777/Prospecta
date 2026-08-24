<?php

declare(strict_types=1);

namespace App\Dominio;

/**
 * Os nove parâmetros do Pós-atendimento, declarados UMA vez.
 *
 * 🔴 A ORDEM DESTA LISTA É CONTRATO COM O ASTERISK.
 *
 * `AMD()` recebe os nove por POSIÇÃO, não por nome. Trocar duas posições não dá
 * erro, não aparece em log: dá classificação errada em silêncio, que é o modo de
 * falha mais caro do produto — falso-MÁQUINA desliga na cara de quem atendeu.
 * Por isso a ordem mora aqui e em nenhum outro lugar, e há teste que a fixa.
 *
 * Antes desta classe os nove eram soletrados em SETE lugares: a migration, a lista
 * de colunas e a lista de defaults do `Fastagi\Banco`, o default do `Fastagi\Dados`,
 * as propriedades e as regras de validação da tela, e os atributos `min`/`max` da
 * blade. Nada obrigava os sete a concordarem.
 *
 * Pura por exigência do ADR-0006: o handler não sobe framework (ADR-0001), então
 * uma classe que o painel e o `fastagi` compartilham não pode tocar `app()`,
 * container, facade nem Eloquent. Entra e sai com tipos da linguagem, e o teste
 * unitário roda sem banco, sem Asterisk e sem HTTP.
 */
final class PosAtendimento
{
    /**
     * Coluna => valor já resolvido, na ordem posicional do `AMD()`.
     *
     * @var array<string,int>
     */
    private array $valores;

    /** @param array<string,int> $valores */
    private function __construct(array $valores)
    {
        $this->valores = $valores;
    }

    /**
     * A declaração, na ORDEM POSICIONAL do `AMD()`. O comentário ao lado de cada um
     * é o nome do argumento na documentação do Asterisk — é por ele que se confere
     * a ordem contra a fonte, sem precisar confiar na nossa tradução.
     *
     * Os defaults aqui são os das COLUNAS, não os do `amd.conf`, e a diferença é
     * carregada: `PosAtendimentoTest` afirma que os dois conjuntos batem com a
     * migration, para que mudar um sem o outro quebre em vez de divergir calado.
     *
     * 🔴 O conjunto de fábrica JÁ TEVE um critério morto, e o 3500 abaixo é o
     * conserto. `silêncio inicial` nasceu em 6000 contra 5000 de `análise total` —
     * pedia mais tempo que o orçamento inteiro e por isso nunca disparava. Medido em
     * 24/08/2026 sobre 8.829 Ligações: com 3500, `NOTSURE` caiu de 55,0% para 1,9% e
     * `INITIALSILENCE` saiu de ZERO ocorrências para 1.638. Ver a migration
     * `pos_silencio_inicial_nasce_vivo`, que carrega a tabela inteira.
     *
     * 3500 e não o 2500 do `amd.conf` porque 3500 é o que foi MEDIDO nesta rota.
     *
     * @return list<ParametroDoPos>
     */
    public static function parametros(): array
    {
        return [
            // 1 initialSilence — quanto silêncio antes do primeiro som ainda é humano
            new ParametroDoPos('pos_silencio_inicial_ms', 'silêncio inicial', 3500, 100, 65535, dentroDaAnalise: true),
            // 2 greeting — saudação mais longa que isto é secretária eletrônica
            new ParametroDoPos('pos_saudacao_max_ms', 'saudação máxima', 4000, 100, 65535, dentroDaAnalise: true),
            // 3 afterGreetingSilence
            new ParametroDoPos('pos_silencio_apos_saudacao_ms', 'silêncio após a saudação', 800, 100, 65535),
            // 4 totalAnalysisTime — o ORÇAMENTO contra o qual os outros são medidos
            new ParametroDoPos('pos_analise_total_ms', 'análise total', 5000, 100, 65535),
            // 5 minimumWordLength
            new ParametroDoPos('pos_palavra_min_ms', 'palavra mínima', 100, 10, 65535),
            // 6 betweenWordsSilence
            new ParametroDoPos('pos_silencio_entre_palavras_ms', 'silêncio entre palavras', 50, 10, 65535),
            // 7 maximumNumberOfWords — TINYINT, por isso o teto é 255 e não 65535
            new ParametroDoPos('pos_palavras_max', 'máximo de palavras', 3, 1, 255, unidade: ''),
            // 8 silenceThreshold
            new ParametroDoPos('pos_limiar_silencio', 'limiar de silêncio', 256, 1, 65535, unidade: ''),
            /*
             * 9 maximumWordLength — FORA da conta da análise, de propósito.
             *
             * O `amd.conf` de fábrica entrega este e `total_analysis_time` iguais em
             * 5000, e ele não é um prazo a vencer: é o tamanho a partir do qual uma
             * única elocução conta como máquina. Recusar 5000/5000 seria recusar o
             * padrão do fornecedor — é como validação zelosa demais vira obstáculo.
             */
            new ParametroDoPos('pos_palavra_max_ms', 'palavra máxima', 5000, 100, 65535),
        ];
    }

    /** O conjunto de fábrica. É o que vale quando a linha de `configuracao` não existe. */
    public static function padrao(): self
    {
        $valores = [];
        foreach (self::parametros() as $p) {
            $valores[$p->coluna] = $p->padrao;
        }

        return new self($valores);
    }

    /**
     * Resolve a partir de uma linha de `configuracao`.
     *
     * 🔴 COLUNA AUSENTE CAI NO DEFAULT, e isto é o conserto de um caminho morto.
     *
     * A montagem anterior comparava com `null` o retorno de um helper que devolvia
     * `0` para ausência — a comparação era sempre falsa, o PHPStan a acusou como
     * `identical.alwaysFalse`, e o fallback nunca rodava. Com a linha ausente o
     * `LEFT JOIN` traz tudo nulo e o Asterisk teria recebido `AMD "0,0,0,..."`.
     *
     * Zero não é valor válido para nenhum dos nove — o menor mínimo é 1 —, então
     * "não positivo" É "a coluna não disse nada". É o mesmo critério que a tela já
     * usava ao carregar, agora escrito num lugar só.
     *
     * @param array<string,mixed> $linha
     */
    public static function deLinha(array $linha): self
    {
        $valores = [];
        foreach (self::parametros() as $p) {
            $bruto = $linha[$p->coluna] ?? null;
            $valor = is_numeric($bruto) ? (int) $bruto : 0;
            $valores[$p->coluna] = $valor > 0 ? $valor : $p->padrao;
        }

        return new self($valores);
    }

    /**
     * Substitui valores, validando nada — quem valida é a tela, com os limites
     * daqui. Serve para a tela montar o conjunto que ela está prestes a gravar e
     * perguntar a ele quais critérios ficariam mortos.
     *
     * @param array<string,int> $valores
     */
    public function com(array $valores): self
    {
        $novo = $this->valores;
        foreach (self::parametros() as $p) {
            if (isset($valores[$p->coluna])) {
                $novo[$p->coluna] = $valores[$p->coluna];
            }
        }

        return new self($novo);
    }

    public function valor(string $coluna): int
    {
        return $this->valores[$coluna] ?? 0;
    }

    /**
     * Os nove argumentos do `AMD()`, como o Asterisk os quer: strings, por posição.
     *
     * @return list<string>
     */
    public function argumentos(): array
    {
        $saida = [];
        foreach (self::parametros() as $p) {
            $saida[] = (string) $this->valores[$p->coluna];
        }

        return $saida;
    }

    /**
     * O conjunto como colunas, para o `UPDATE` da tela.
     *
     * @return array<string,int>
     */
    public function paraColunas(): array
    {
        return $this->valores;
    }

    /**
     * Os critérios que NUNCA são alcançados, coluna => rótulo.
     *
     * Critério cujo relógio corre dentro do orçamento da análise e que pede tempo
     * igual ou maior que ele simplesmente some: não dá erro, não aparece em log.
     * Medido em 21/08/2026 com `silêncio inicial` em 6000 contra `análise total` em
     * 5000: em 8.406 classificações a causa `INITIALSILENCE` apareceu ZERO vezes, e
     * 54,6% das Ligações atendidas voltaram sem decisão — 12,7 horas de tronco.
     *
     * `>=` e não `>`: empatar com o orçamento é a mesma coisa que estourá-lo, porque
     * o relógio para ao ATINGIR o total.
     *
     * @return array<string,string>
     */
    public function criteriosMortos(): array
    {
        $total = $this->valor('pos_analise_total_ms');
        if ($total <= 0) {
            return [];
        }

        $mortos = [];
        foreach (self::parametros() as $p) {
            if ($p->dentroDaAnalise && $this->valor($p->coluna) >= $total) {
                $mortos[$p->coluna] = $p->rotulo;
            }
        }

        return $mortos;
    }

    /** O rótulo como a tela o mostra: primeira maiúscula, unidade entre parênteses. */
    public static function etiqueta(ParametroDoPos $p): string
    {
        $base = ucfirst($p->rotulo);

        return $p->unidade === '' ? $base : sprintf('%s (%s)', $base, $p->unidade);
    }

    /**
     * Os rótulos dos critérios medidos contra a análise total, para a tela dizer
     * quais são — em vez de a prosa listar três e a regra conferir dois.
     *
     * @return list<string>
     */
    public static function rotulosDentroDaAnalise(): array
    {
        $rotulos = [];
        foreach (self::parametros() as $p) {
            if ($p->dentroDaAnalise) {
                $rotulos[] = $p->rotulo;
            }
        }

        return $rotulos;
    }
}

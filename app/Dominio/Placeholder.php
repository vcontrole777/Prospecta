<?php

declare(strict_types=1);

namespace App\Dominio;

/**
 * Resolve Placeholder. PURA por exigência do ADR-0006: sem container, sem
 * model, sem framework. Roda no painel (pré-visualização e "testar Campanha")
 * e no `fastagi` (montagem da Mensagem), e é UMA classe nos dois lugares.
 *
 * O MagnusBilling é o contra-exemplo: o AGI deles roda fora do framework e
 * duplica a lógica do painel, chegando a ter duas classes com o mesmo nome
 * calculando a mesma regra de formas diferentes. A análise deles registra que
 * "divergirem é questão de tempo".
 */
final class Placeholder
{
    /**
     * Substitui `{Nome da Coluna}` pelo valor do Lead.
     *
     * Falha com causa nomeada em vez de substituir por vazio: "Olá, , a  tem
     * uma proposta" tocado para um Lead real é pior que não ligar. E como esta
     * mesma classe roda na importação, o operador descobre no upload em vez de
     * na ligação.
     *
     * @param array<string,string|int|float|null> $dados
     */
    public function resolverTexto(string $texto, array $dados): string
    {
        preg_match_all('/\{([^{}]+)\}/', $texto, $achados);

        foreach ($achados[1] as $coluna) {
            if (! array_key_exists($coluna, $dados)) {
                throw ErroDeDominio::colunaAusente($coluna);
            }
            $valor = trim((string) $dados[$coluna]);
            if ($valor === '') {
                throw ErroDeDominio::valorVazio($coluna);
            }
            $texto = str_replace('{' . $coluna . '}', $valor, $texto);
        }

        return $texto;
    }

    /**
     * Quais colunas um texto exige. Serve para o painel validar a Campanha
     * ANTES de ativar, contra os cabeçalhos do arquivo importado.
     *
     * @return list<string>
     */
    public function colunasCitadas(string $texto): array
    {
        preg_match_all('/\{([^{}]+)\}/', $texto, $achados);

        return array_values(array_unique($achados[1]));
    }

    /**
     * Parâmetros posicionais de template, na ordem numérica.
     *
     * O template da Meta é POSICIONAL e exige numeração sequencial sem lacuna.
     * Trabalhar por posição desde a importação evita uma camada de tradução
     * entre "por nome" e "por posição" — e camada de tradução é mais uma regra
     * para divergir.
     *
     * @param array<array-key,string> $mapa posição => nome da coluna, ex. {"1":"Nome do Lead"}
     * @param array<string,string|int|float|null> $dados
     * @return list<string>
     */
    public function parametrosTemplate(array $mapa, array $dados): array
    {
        $posicoes = [];
        foreach (array_keys($mapa) as $chave) {
            if (! preg_match('/^[1-9][0-9]*$/', (string) $chave)) {
                throw ErroDeDominio::mapaNaoSequencial("posição \"{$chave}\" não é inteiro positivo");
            }
            $posicoes[] = (int) $chave;
        }
        sort($posicoes);

        /*
         * 🔴 GUARDA DO VAZIO, e ela existe por uma armadilha do PHP.
         *
         * `range(1, 0)` NÃO devolve lista vazia: devolve `[1, 0]`, porque o `range`
         * conta de trás quando o fim é menor que o início. Sem esta guarda, mapa vazio
         * comparava `[]` com `[1, 0]`, a comparação falhava, e TODO template sem
         * parâmetro era recusado como "não sequencial".
         *
         * MEDIDO em produção: quatro Ações de disparo falharam com
         * `template_mapa_invalido | ... recebido  e esperado 1,0` — o "recebido" vazio
         * ao lado de um "esperado" impossível é a assinatura deste defeito. Template
         * sem parâmetro é caso legítimo e comum: toda mensagem de texto fixo é assim.
         */
        if ($posicoes === []) {
            return [];
        }

        $esperado = range(1, count($posicoes));
        if ($posicoes !== $esperado) {
            throw ErroDeDominio::mapaNaoSequencial(
                'recebido ' . implode(',', $posicoes) . ' e esperado ' . implode(',', $esperado)
            );
        }

        $saida = [];
        foreach ($posicoes as $pos) {
            $coluna = $mapa[(string) $pos] ?? $mapa[$pos];
            if (! array_key_exists($coluna, $dados)) {
                throw ErroDeDominio::colunaAusente($coluna);
            }
            $valor = trim((string) $dados[$coluna]);
            if ($valor === '') {
                throw ErroDeDominio::valorVazio($coluna);
            }
            $this->conferirValorDeTemplate($coluna, $valor);
            $saida[] = $valor;
        }

        return $saida;
    }

    /**
     * A Meta recusa parâmetro de template com quebra de linha, tabulação ou
     * corrida longa de espaço.
     *
     * PROCEDÊNCIA: regra de plataforma, não medida por nós. Conferir na
     * documentação vigente antes de confiar no limite exato de espaços — o
     * comportamento verificado é que a recusa acontece, e é no ENVIO, o que
     * significa Ação em `falhou` sem o Lead receber nada.
     *
     * Falhamos aqui em vez de sanitizar porque alterar o dado do Lead em
     * silêncio produz mensagem errada, que é pior que mensagem não enviada.
     */
    private function conferirValorDeTemplate(string $coluna, string $valor): void
    {
        if (preg_match('/[\r\n\t]/', $valor) === 1) {
            throw ErroDeDominio::valorInvalidoParaTemplate($coluna, 'contém quebra de linha ou tabulação');
        }
        if (preg_match('/ {4,}/', $valor) === 1) {
            throw ErroDeDominio::valorInvalidoParaTemplate($coluna, 'contém corrida de 4 ou mais espaços');
        }
    }
}

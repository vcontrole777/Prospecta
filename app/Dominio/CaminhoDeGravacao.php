<?php

declare(strict_types=1);

namespace App\Dominio;

/**
 * Contenção de caminho de gravação. PURA.
 *
 * O expurgo APAGA arquivo cujo caminho vem do banco. Hoje esse caminho é escrito
 * pelo nosso próprio código a partir de um inteiro — `{dir}/{ligacaoId}.gsm` — e
 * portanto é seguro por construção.
 *
 * Esta classe existe para que continue seguro quando isso mudar. É o contexto
 * "caminho de arquivo" do ADR-0003, e a consequência de errar é um serviço que
 * roda como usuário da aplicação apagando arquivo fora do diretório de gravação.
 * Uma linha com `/etc/asterisk/pjsip.conf` na coluna bastaria.
 */
final class CaminhoDeGravacao
{
    /**
     * O caminho está contido no diretório base?
     *
     * Verificação LEXICAL, de propósito: o alvo pode não existir mais — é o caso
     * normal, de arquivo apagado numa execução anterior que morreu no meio — e
     * `realpath` devolve `false` para inexistente, o que faria a checagem recusar
     * exatamente a linha que precisa ser limpa.
     *
     * Symlink não é ameaça aqui: `unlink` remove o próprio link, nunca segue até
     * o alvo. Quem quiser mudar o expurgo para truncar em vez de apagar tem que
     * revisitar esta afirmação.
     */
    public function contido(string $base, string $caminho): bool
    {
        // Byte nulo trunca o caminho nas chamadas de sistema do C: "/dir/a\0/../x"
        // seria checado inteiro e aberto como "/dir/a". O PHP moderno já recusa,
        // mas a recusa é nossa, não emprestada.
        if (str_contains($base, "\0") || str_contains($caminho, "\0")) {
            return false;
        }

        if (! str_starts_with($base, '/') || ! str_starts_with($caminho, '/')) {
            return false;
        }

        $baseNormal = $this->normalizar($base);
        $alvoNormal = $this->normalizar($caminho);

        if ($baseNormal === null || $alvoNormal === null) {
            return false;
        }

        // O próprio diretório não é um arquivo a apagar; exige-se estar DENTRO.
        return str_starts_with($alvoNormal, $baseNormal . '/');
    }

    /**
     * Normaliza sem tocar no disco. `null` quando o caminho tenta subir.
     *
     * Subida é RECUSADA, não resolvida. Resolver `..` seria aceitar
     * `/gravacoes/../gravacoes/x.gsm`, que é inofensivo, ao custo de aceitar a
     * mesma forma com um segmento a mais — e a diferença entre as duas é a
     * contagem de segmentos, que é exatamente o tipo de coisa que se erra.
     */
    private function normalizar(string $caminho): ?string
    {
        $partes = [];

        foreach (explode('/', $caminho) as $parte) {
            if ($parte === '' || $parte === '.') {
                continue;
            }
            if ($parte === '..') {
                return null;
            }
            $partes[] = $parte;
        }

        return '/' . implode('/', $partes);
    }
}

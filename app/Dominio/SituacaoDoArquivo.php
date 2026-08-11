<?php

declare(strict_types=1);

namespace App\Dominio;

/**
 * O arquivo está lá? PURA no raciocínio, encosta no disco só para perguntar.
 *
 * 🔴 `is_file() === false` NÃO PROVA QUE O ARQUIVO NÃO EXISTE.
 *
 * Prova que este processo não conseguiu vê-lo. As duas causas pedem ações opostas:
 *
 * · o arquivo não existe — alguém precisa colocá-lo lá;
 * · o processo não atravessa o diretório — alguém precisa mexer em grupo de usuário, e
 *   reiniciar o serviço depois, porque grupo suplementar é fixado no `exec`.
 *
 * Reportar as duas como ausência acusa o Asterisk de um problema que pode ser nosso e,
 * pior, treina o operador a ignorar exatamente o aviso que um dia vai estar certo. Um
 * alarme que mente é pior que alarme nenhum.
 *
 * ESTA CLASSE EXISTE PORQUE A REGRA JÁ ESTAVA EM TRÊS LUGARES — a coluna de gravação dos
 * Resultados, a conferência de áudio da ativação e a listagem de Áudios —, e a quarta
 * cópia seria escrita agora. Regra duplicada diverge; este repositório já pagou por isso
 * com o leitor de ambiente, que existia em três cópias e divergiu na terceira.
 *
 * A condição não é hipotética. Medido nesta máquina: as gravações são `asterisk:asterisk`
 * modo 2770, os áudios são `root:asterisk` modo 0640, e o painel roda como `prospecta`.
 * Sem o grupo `asterisk` nos processos EM EXECUÇÃO, tudo respondia "ausente".
 */
final class SituacaoDoArquivo
{
    /** Não há caminho para conferir — não é falha, é ausência de pergunta. */
    public const SEM_CAMINHO = 'sem_caminho';

    /** O arquivo está lá. */
    public const EXISTE = 'existe';

    /** O arquivo não está lá, e temos como saber disso. É falha, e nomeável. */
    public const AUSENTE = 'ausente';

    /** O diretório existe e não abre: qualquer afirmação sobre o conteúdo é chute. */
    public const SEM_ACESSO = 'sem_acesso';

    public function de(string $caminho): string
    {
        if ($caminho === '') {
            return self::SEM_CAMINHO;
        }

        if (is_file($caminho)) {
            return self::EXISTE;
        }

        // A pergunta certa antes de acusar: eu conseguiria ver este arquivo se ele
        // estivesse aí?
        //
        // Diretório INEXISTENTE não entra aqui de propósito — ele é prova de que o arquivo
        // não existe, não de cegueira. Confundir os dois faria uma instância sem nada
        // instalado exibir aviso de permissão, mandando o operador mexer em grupo de
        // usuário quando o que falta é copiar arquivo.
        if (! $this->diretorioAcessivel(\dirname($caminho))) {
            return self::SEM_ACESSO;
        }

        return self::AUSENTE;
    }

    /**
     * Este processo atravessa o diretório?
     *
     * Diretório inexistente responde `true`: aí o problema é outro, e cada arquivo que
     * falta é dito um a um, com a causa dele.
     *
     * `is_readable` E `is_executable`: listar e atravessar são permissões distintas, e
     * `is_file` sobre um caminho dentro do diretório precisa da segunda. Um diretório
     * `--x` deixa passar mas não listar; um `r--` deixa listar e não passar. Conferir só
     * uma das duas deixaria metade dos casos respondendo "ausente" por cegueira.
     */
    public function diretorioAcessivel(string $diretorio): bool
    {
        if ($diretorio === '' || ! is_dir($diretorio)) {
            return true;
        }

        return is_readable($diretorio) && is_executable($diretorio);
    }
}

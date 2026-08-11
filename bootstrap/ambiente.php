<?php

declare(strict_types=1);

/**
 * Leitor de configuração para os processos de CHAMADA. Bootstrap mínimo (ADR-0001):
 * sem `env()` do framework, sem contêiner, sem Dotenv.
 *
 * 🔴 UM LUGAR SÓ, e a extração é o conserto de uma divergência que já custou.
 *
 * Esta lógica estava copiada em `config/dialer.php` e `config/fastagi.php`, e quando
 * escrevi `config/worker.php` eu copiei a FORMA e não o recurso de ler o `.env`. O
 * resultado: o systemd não passa variável de ambiente para as unidades — nenhuma tem
 * `EnvironmentFile` — então dialer e fastagi funcionavam pelo recurso e o worker
 * entrava em ciclo de falha com `DB_PASSWORD ausente`.
 *
 * Encontrado subindo os serviços pela primeira vez. Três cópias da mesma regra
 * divergiram na terceira, que é exatamente o que "regra duplicada sempre diverge"
 * descreve.
 *
 * 🔴 O AMBIENTE GANHA DO ARQUIVO. É o que permite `PROSPECTA_TRONCO=x php bin/...`
 * numa bateria de teste sem editar o `.env` da instância — e é a ordem que o
 * `Environment=` das unidades do systemd pressupõe.
 *
 * 🔴 FORA DE `config/`, E É REQUISITO DO FRAMEWORK.
 *
 * Este arquivo devolve uma CLOSURE. O Laravel carrega todo `config/*.php` como
 * configuração, e `artisan config:cache` serializa a configuração inteira com
 * `var_export` — closure não sobrevive a isso. Com ele em `config/`, o deploy morria em
 * "Your configuration files could not be serialized because the value at 'ambiente' is
 * non-serializable", e sem o cache o painel falha na primeira requisição.
 *
 * Encontrado IMPLANTANDO, não testando: a suíte nunca roda `config:cache`. Foi acrescentado
 * um teste que confere que nenhuma configuração devolve valor não serializável — ver
 * `ConfiguracaoSerializavelTest`.
 *
 * @return callable(string, string|null): string
 */
return static function (string $raiz): callable {
    /** @var array<string,string>|null $arquivo */
    $arquivo = null;

    return static function (string $chave, ?string $padrao = '') use ($raiz, &$arquivo): string {
        $v = getenv($chave);
        if ($v !== false && $v !== '') {
            return $v;
        }

        if ($arquivo === null) {
            $arquivo = [];
            $caminho = rtrim($raiz, '/') . '/.env';

            if (is_readable($caminho)) {
                foreach (file($caminho, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $linha) {
                    if ($linha === '' || $linha[0] === '#' || ! str_contains($linha, '=')) {
                        continue;
                    }
                    [$k, $val] = explode('=', $linha, 2);
                    $arquivo[trim($k)] = trim($val, " \t\"'");
                }
            }
        }

        $doArquivo = $arquivo[$chave] ?? '';
        if ($doArquivo !== '') {
            return $doArquivo;
        }

        // 🔴 `null` como padrão significa OBRIGATÓRIA. Falhar alto na partida é melhor
        // que subir sem credencial e descobrir na primeira chamada — que é quando há
        // uma pessoa na linha.
        if ($padrao === null) {
            fwrite(STDERR, json_encode([
                'evento' => 'erro_fatal',
                'mensagem' => "variável de ambiente obrigatória ausente: {$chave}",
            ], JSON_UNESCAPED_UNICODE) . "\n");
            exit(1);
        }

        return $padrao;
    };
};

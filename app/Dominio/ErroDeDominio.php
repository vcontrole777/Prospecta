<?php

declare(strict_types=1);

namespace App\Dominio;

/**
 * Falha de regra de domínio, com CAUSA NOMEADA.
 *
 * Causa nomeada em vez de mensagem solta porque o chamador precisa decidir o
 * que fazer: o importador marca a linha do arquivo, o `dialer` grava status
 * terminal no Lead, e o painel mostra ao operador. Uma string de mensagem não
 * serve para isso.
 */
final class ErroDeDominio extends \RuntimeException
{
    private function __construct(
        public readonly string $causa,
        string $mensagem,
    ) {
        parent::__construct($mensagem);
    }

    public static function colunaAusente(string $coluna): self
    {
        return new self('COLUNA_AUSENTE', "A coluna \"{$coluna}\" não existe nos dados do Lead.");
    }

    public static function valorVazio(string $coluna): self
    {
        return new self('VALOR_VAZIO', "A coluna \"{$coluna}\" existe mas está vazia.");
    }

    public static function mapaNaoSequencial(string $detalhe): self
    {
        return new self('MAPA_NAO_SEQUENCIAL', "O mapa de posições precisa ser 1..N sem lacuna: {$detalhe}");
    }

    public static function valorInvalidoParaTemplate(string $coluna, string $motivo): self
    {
        return new self('VALOR_INVALIDO_TEMPLATE', "A coluna \"{$coluna}\" não serve como parâmetro de template: {$motivo}");
    }

    public static function regraDesconhecida(string $regra): self
    {
        return new self('REGRA_BINA_DESCONHECIDA', "Regra de BINA desconhecida: \"{$regra}\"");
    }

    public static function telefoneInvalido(string $telefone): self
    {
        return new self('TELEFONE_INVALIDO', "Telefone fora do formato canônico: \"{$telefone}\"");
    }
}

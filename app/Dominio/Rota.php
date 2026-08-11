<?php

declare(strict_types=1);

namespace App\Dominio;

/**
 * A Rota de saída, e o texto de configuração que o Asterisk lê. PURA.
 *
 * 🔴 A ROTA MORAVA EM TRÊS LUGARES, e é isso que esta classe existe para acabar.
 *
 * · `/etc/asterisk/pjsip.conf`, gerado pelo Ansible a partir de `host_vars`;
 * · `.env`, em `PROSPECTA_TRONCO` e `PROSPECTA_TECH_PREFIX`, que o dialer lê na partida
 *   para montar `PJSIP/{prefixo}{numero}@{nome}`;
 * · `configuracao.rota_*` (eram `tronco_*`), seis colunas que ninguém lia.
 *
 * Três fontes para o mesmo fato divergem — este repositório já pagou por isso com a
 * retenção da gravação (duas fontes, defaults diferentes, o painel mostrando 15 e o
 * expurgo apagando aos 30) e com o leitor de ambiente (três cópias, a terceira sem o
 * recurso de ler o `.env`, worker em ciclo de falha). Aqui o preço seria pior: o operador
 * troca o prefixo técnico no painel e o dialer segue discando pelo antigo.
 *
 * 🔴 E O PREFIXO TÉCNICO É SELEÇÃO DE CAPACIDADE, não detalhe de roteamento. Medido: na
 * rota barata, CallerID e DTMF falham em SILÊNCIO — e o produto inteiro é "escute e digite
 * uma tecla". Discar pelo prefixo errado não dá erro; dá Campanha inteira sem resposta.
 */
final class Rota
{
    /**
     * O nome do endpoint é FIXO.
     *
     * Ele aparece no texto do pjsip, no `Dial` do dialer e no `identify` de entrada. Deixá-lo
     * editável obrigaria os três a concordarem no instante da troca, e o intervalo entre
     * salvar e recarregar sairia discando para um endpoint que não existe. O que muda de
     * fornecedor para fornecedor é host, credencial e prefixo — não o apelido interno.
     */
    public const NOME = 'rota';

    public function __construct(
        public readonly string $host = '',
        public readonly string $usuario = '',
        public readonly string $senha = '',
        public readonly string $fromDominio = '',
        public readonly string $fromUsuario = '',
        public readonly string $techPrefix = '',
    ) {
    }

    /** Sem host não há para onde discar; é o mínimo que torna a Rota utilizável. */
    public function configurado(): bool
    {
        return trim($this->host) !== '';
    }

    /**
     * O canal que o dialer manda no `Originate`.
     *
     * Montado AQUI e não no dialer para que o prefixo e o nome tenham um dono só. O
     * telefone entra como veio da Fila — já canônico, só dígitos.
     */
    public function canal(string $tecnologia, string $telefone): string
    {
        return sprintf('%s/%s%s@%s', $tecnologia, $this->techPrefix, $telefone, self::NOME);
    }

    /**
     * O trecho de `pjsip.conf` desta Rota.
     *
     * 🔴 NÃO inclui `[transport-udp]`. O transporte é da máquina, não da Rota, e vive no
     * arquivo que o Ansible gera. Duplicá-lo aqui faria o Asterisk ver duas seções com o
     * mesmo nome a cada recarga.
     *
     * O texto é gerado com os valores ESCAPADOS pela ausência de escape: o pjsip.conf não
     * tem sintaxe de aspas, então caractere de nova linha num campo quebraria o arquivo. A
     * validação da tela é que impede isso — ver `Configuracao::regras`. Aqui só se recusa
     * a gerar quando não há host, porque arquivo pela metade é pior que arquivo vazio.
     */
    public function paraPjsip(): string
    {
        if (! $this->configurado()) {
            return "; Rota não configurada no painel. Nenhum endpoint definido.\n";
        }

        $n = self::NOME;

        $texto = <<<CONF
            ; ------------------------------------------------------------------
            ; GERADO PELO PAINEL. Não edite aqui: a próxima gravação sobrescreve.
            ; A fonte é `configuracao.rota_*`, e a senha fica cifrada no banco.
            ; ------------------------------------------------------------------

            [{$n}]
            type=endpoint
            context=prospecta-entrada
            transport=transport-udp
            aors={$n}

            CONF;

        // 🔴 SEM USUÁRIO NÃO HÁ AUTENTICAÇÃO, e o bloco NÃO é emitido.
        //
        // Rota autenticada por IP — como a desta instância — não tem credencial. Emitir um
        // `[nome-auth]` com `username=` vazio faz o Asterisk RECUSAR o objeto:
        //
        //   res_pjsip/config_auth.c: rota-auth: No authentication username
        //   res_sorcery_config.c: Could not create an object of type 'auth'
        //
        // Medido nesta máquina. A chamada continua saindo, porque o IP autoriza — então o
        // efeito não é falha, é um erro a cada recarga e um `outbound_auth` apontando para
        // objeto inexistente. Log cheio de erro rotineiro é log que ninguém lê, e o dia em
        // que houver um erro de verdade ele se perde no meio.
        if (trim($this->usuario) !== '') {
            $texto .= "outbound_auth={$n}-auth\n";
        }


        // ⚠️ `from_user` SOBREPÕE o CALLERID(num) e torna TODA Regra de BINA inócua: o
        // painel mostra a BINA escolhida e a rota manda outra coisa, sem erro em log
        // nenhum. Medido em 2026-08-09 — removendo-o, o From passou a levar a BINA e a
        // rota ACEITOU. Só sai no arquivo quando o operador preenche de propósito.
        if (trim($this->fromUsuario) !== '') {
            $texto .= "from_user={$this->fromUsuario}\n";
        }

        if (trim($this->fromDominio) !== '') {
            $texto .= "from_domain={$this->fromDominio}\n";
        }

        $texto .= <<<CONF

            ; alaw/ulaw: os áudios são alaw, e casar com a rota elimina transcodificação
            ; na reprodução, deixando DSP só para o AMD e o MixMonitor.
            disallow=all
            allow=alaw,ulaw

            ; rfc4733: DTMF fora de banda. Em banda depende de codec e sobrevive mal a
            ; transcodificação — e aqui DTMF é o produto.
            dtmf_mode=rfc4733

            ; O Asterisk PRECISA estar no caminho da mídia: sem isso o AMD não tem áudio
            ; para analisar e o MixMonitor não tem o que gravar.
            direct_media=no

            rtp_symmetric=yes
            force_rport=yes
            rewrite_contact=yes

            [{$n}]
            type=aor
            contact=sip:{$this->host}

            ; OPTIONS periódico, por duas razões: prova que o SIP faz ida e volta pelo
            ; provedor de rede SEM gastar chamada — alguns filtram 5060, e o sintoma seria
            ; INVITE sem resposta, indistinguível de erro de configuração —, e faz
            ; `pjsip show aors` mostrar Avail/Unavail, que é diagnóstico de graça.
            qualify_frequency=60
            qualify_timeout=3.0

            ; Casamento de entrada por IP.
            [{$n}]
            type=identify
            endpoint={$n}
            match={$this->host}

            CONF;

        // O bloco de auth vem DEPOIS do endpoint, e só quando há usuário. A ordem no arquivo
        // não importa para o pjsip — ele resolve as referências no fim da leitura.
        if (trim($this->usuario) !== '') {
            $texto .= <<<CONF

                [{$n}-auth]
                type=auth
                auth_type=userpass
                username={$this->usuario}
                password={$this->senha}

                CONF;
        }

        return $texto;
    }
}

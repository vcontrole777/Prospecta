<?php

declare(strict_types=1);

/**
 * Só o que precisa ser diferente do padrão do Livewire.
 *
 * O arquivo publicado inteiro tem trinta chaves que nunca vamos mexer, e cada
 * uma delas passaria a ser nossa para manter na próxima atualização do pacote.
 * O `FileUploadConfiguration` lê chave por chave com `?:`, então um arquivo
 * parcial é seguro: o que não está aqui continua vindo do default.
 */
return [

    'temporary_file_upload' => [

        /*
         * 🔴 SEM ISTO, O TETO DA IMPORTAÇÃO É 12 MB E NÃO 100.
         *
         * O Livewire valida o upload temporário ANTES de o componente ver o
         * arquivo, com regra própria — `['required', 'file', 'max:12288']` — e
         * essa regra não tem nada a ver com a de `ImportarLista`. Um CSV de
         * 40 MB seria recusado por ela, com mensagem genérica, e a mensagem boa
         * do componente nunca apareceria.
         *
         * 105 MB, e não 100: o menor limite da pilha tem que ser o do
         * componente, que é quem sabe dizer "divida a lista em partes".
         *
         * Consequência aceita: as outras telas de upload (Áudios, Imagens)
         * passam a receber no disco arquivos grandes antes de recusá-los pela
         * própria regra. O produto é de operador único e autenticado; a troca é
         * gravar um arquivo grande à toa em vez de quebrar a importação.
         */
        'rules' => ['required', 'file', 'max:107520'],
    ],

];

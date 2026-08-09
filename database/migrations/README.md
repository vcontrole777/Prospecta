# Migrations

## Por que não existe migration de `jobs`

O ADR-0002 decide que a Ação é **linha na tabela `acao`**, com idempotência no
esquema (`UNIQUE (tipo, referencia_id)`), consumida por um `worker` próprio.

A migration de fila do framework foi **removida de propósito**, e
`QUEUE_CONNECTION` está em `sync`. Manter a tabela ali seria manter um segundo
mecanismo de fila à disposição — e o defeito mais bem documentado do sistema que
serviu de referência é exatamente ter duas implementações da mesma coisa.

Se alguém precisar de fila do framework para outra finalidade, que seja decisão
registrada, não consequência de uma tabela que já estava lá.

## Ordem

As tabelas de domínio vêm depois das do framework, e entre si respeitam a ordem
das chaves estrangeiras: `audio` antes de `campanha`, `campanha` antes de `lead`,
e assim por diante.

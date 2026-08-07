# Prospecta

Discadora para prospecção de leads: recebe uma lista de contatos, liga para cada um, reproduz uma mensagem e registra a resposta do Lead para decidir o que fazer em seguida.

## Language

### Prospecção

**Lead**:
Pessoa ou empresa que pode se tornar cliente e ainda não foi qualificada.
_Avoid_: prospect, contato, cliente

**Opt-in**:
Resposta do Lead que autoriza o contato seguinte por mensagem.
_Avoid_: aceite, conversão, qualificação

**Opt-out**:
Resposta do Lead pedindo para não ser mais contatado. Leva o número à Lista de bloqueio.
_Avoid_: descadastro, remoção, recusa

**Lista de bloqueio**:
Os números que não podem ser discados por nenhuma Campanha, para sempre.
_Avoid_: blacklist, lista negra, DNC

### Campanha

**Campanha**:
Conjunto de Leads que recebem a mesma mensagem sob as mesmas regras de discagem.
_Avoid_: lista, lote, disparo

**Modelo**:
Uma Campanha salva sem Leads, para servir de ponto de partida para outras.
_Avoid_: template, preset

**Placeholder**:
Coluna da lista, além do telefone, usada para personalizar a mensagem de cada Lead.
_Avoid_: variável, campo, merge tag

**Fila de discagem**:
A ordem em que os Leads de uma Campanha serão discados.
_Avoid_: lista de discagem, batch

**URA**:
O fluxo que o Lead percorre depois de atender: ouve a Mensagem e responde.
_Avoid_: IVR, menu, árvore

**Mensagem**:
O áudio principal da Campanha, tocado assim que o Lead atende.
_Avoid_: locução, gravação, prompt

**Áudio de confirmação**:
O áudio tocado depois da resposta do Lead, confirmando o Opt-in ou o Opt-out.
_Avoid_: agradecimento, despedida

### Telefonia

**Ligação**:
Uma tentativa de entregar a mensagem a um Lead. Existe mesmo quando ninguém atende, e o mesmo Lead pode receber várias.
_Avoid_: chamada, discagem, tentativa

**Pré-atendimento**:
A classificação da Ligação antes de ela ser atendida, a partir da condição da linha, que decide se a Ligação segue.
_Avoid_: classificador de chamada, call progress

**Pós-atendimento**:
A classificação de quem atendeu — Humano ou Máquina — que decide se a mensagem é entregue.
_Avoid_: AMD, detecção de secretária

**Desfecho**:
O resultado de negócio de uma Ligação: não atendeu, caiu em Máquina, deu Opt-in, deu Opt-out.
_Avoid_: status, resultado, disposição

**CDR**:
O registro técnico de uma Ligação — horários, duração, condição da linha. Distinto do Desfecho, que é o resultado de negócio.
_Avoid_: log de chamada, histórico

**BINA**:
O número exibido para o Lead quando o telefone toca.
_Avoid_: caller ID, identificador, origem

**Tronco SIP**:
O caminho pelo qual as Ligações saem para a operadora.
_Avoid_: gateway, peer, rota

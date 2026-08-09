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

**Ação**:
Algo que o sistema faz fora dele em consequência do que aconteceu numa Ligação. Disparo e Notificação são Ações; consultar um CPF digitado e publicar um evento também. Não é clique de botão nem etapa da URA.
_Avoid_: tarefa, job, envio

**Disparo**:
A Ação de enviar WhatsApp ou SMS ao Lead em consequência do Opt-in. Nunca chamado de "mensagem" — Mensagem é o áudio da Campanha.
_Avoid_: envio, mensagem, notificação

**Notificação**:
A Ação de avisar o dono da Campanha com o resultado de uma captura. Distinta do Disparo, que vai para o Lead.
_Avoid_: alerta, aviso, disparo

**Conversa**:
A troca de mensagens de WhatsApp entre o número do dono da Campanha e o número do Lead, com a janela de 24 h que o
toque no botão do template abre. É **por número, não por Campanha** — o WhatsApp não sabe das nossas Campanhas, então
o mesmo Lead em duas Campanhas tem uma Conversa só.
Mensagens individuais não têm termo próprio: numa interface de conversa ninguém rotula mensagem individual.
_Avoid_: chat, thread, atendimento, e **"mensagem"** para se referir a um item — Mensagem é o áudio da Campanha

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
O áudio principal da Campanha, tocado assim que o Lead atende. **Sempre áudio** — nunca uma mensagem de WhatsApp
ou SMS, que são Disparo, e nunca um item de Conversa.
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
A visão das Ligações pelos seus dados técnicos — horários, duração, condição da linha. Não é uma entidade própria: são atributos da própria Ligação, ao lado do Desfecho, que é o resultado de negócio.
_Avoid_: log de chamada, histórico

**BINA**:
O número exibido para o Lead quando o telefone toca. Escolhida por uma Regra de BINA.
_Avoid_: caller ID, identificador, origem

**Regra de BINA**:
Como a Campanha escolhe a BINA de cada Ligação. Quatro: `unica` (sempre `campanha.bina`),
`lista` (sorteia do pool entre as que casam o DDD do Lead), `espelho` (telefone do Lead com os
4 últimos dígitos trocados) e `espelho_fixo` (DDD do Lead mais um fixo gerado).
A BINA registrada numa Ligação é a **solicitada**, não a entregue — o fornecedor pode
substituí-la e não temos como saber.
_Avoid_: BINA inteligente (bom nome de recurso, nome ruim de coluna), rotação de callerid,
e **"fixa"** para dizer "não muda" — em telefonia "fixo" é telefone fixo, por isso `unica`

**Tronco SIP**:
O caminho pelo qual as Ligações saem para a operadora.
_Avoid_: gateway, peer, rota

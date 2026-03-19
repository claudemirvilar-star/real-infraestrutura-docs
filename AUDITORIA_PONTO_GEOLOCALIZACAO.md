# PLANO DE IMPLEMENTAÇÃO
## Auditoria Inteligente de Ponto por Geolocalização, Veículo e Produção

**Data:** 18-03-2026
**Status:** Planejamento funcional
**Fase:** Pré-código / definição de arquitetura e regras
**Objetivo:** estruturar a auditoria de ponto com cruzamento entre ponto georreferenciado, GPS do caminhão/equipamento e evidências operacionais da produção

---

# 1. VISÃO GERAL DO QUE SERÁ IMPLEMENTADO

Este módulo terá como finalidade auditar automaticamente a coerência dos pontos dos colaboradores com base em três pilares principais:

1. **Ponto georreferenciado do Tangerino**
2. **GPS do veículo/equipamento da equipe**
3. **Georreferenciamento e horário das evidências operacionais da produção**
   (principalmente fotos de início e fim do trabalho, ou fotos intermediárias quando houver múltiplos pontos no mesmo dia)

A lógica central será:

- o **motorista** terá sua jornada auditada com base no horário real de uso do caminhão
- os **demais integrantes da equipe** terão sua jornada auditada com base no local e horário real da operação, apurados por meio das fotos georreferenciadas e do deslocamento do caminhão
- qualquer divergência acima da tolerância definida deverá gerar **alerta automático**

---

# 2. CENÁRIO OPERACIONAL JÁ EXISTENTE

Este plano parte do princípio de que o projeto **não está começando do zero**.

Já existem componentes importantes implementados, entre eles:

## 2.1. Estruturas já existentes
- motor diário de consolidação de ponto
- regras definidas para batidas faltantes
- regras de horário do pessoal do escritório
- relatórios e alertas já enviados por WhatsApp
- tabela de ponto do Tangerino com georreferenciamento
- GPS do caminhão via CEABS
- tabela de medições / produção
- fluxo operacional do encarregado, que lança a produção já vinculado à equipe
- identificação do veículo/equipamento por apelido dentro do processo operacional
- evidências fotográficas da produção com horário e georreferenciamento

## 2.2. Conclusão estratégica
Portanto, esta nova auditoria **não é um sistema novo completo**, e sim uma **nova camada de inteligência e cruzamento** sobre uma base já madura.

---

# 3. PREMISSA PRINCIPAL DA AUDITORIA

A auditoria será baseada no conceito de **coerência operacional do dia trabalhado**.

Ou seja:

- o ponto do colaborador precisa fazer sentido com o local onde a equipe realmente trabalhou
- o ponto do motorista precisa fazer sentido com o momento real em que o caminhão começou e terminou a operação
- a produção lançada pelo encarregado precisa servir como elo entre:
  - equipe
  - encarregado
  - veículo
  - fotos
  - operação do dia

---

# 4. PRIMEIRO PILAR: AMARRAÇÃO DA EQUIPE

## 4.1. Conceito de equipe no sistema
No contexto operacional, a equipe é representada pelo **usuário do encarregado**.

Exemplo:

- encarregado: Jefferson
- usuário no sistema: `soares`
- equipe tratada no sistema: **Equipe Soares**

Assim, a equipe não é apenas um agrupamento solto. Ela já nasce vinculada ao encarregado no ato da produção.

## 4.2. Como a equipe já é formada na prática
Quando o encarregado faz o lançamento da produção:

- ele está logado no sistema com seu usuário
- ele escolhe quais colaboradores trabalharam naquele dia
- ele informa a produção executada
- ele já está vinculado ao veículo/equipamento com que trabalha
- esse veículo já é conhecido pelo sistema por apelido

Exemplo:
- usuário do encarregado: `soares`
- veículo/equipamento da equipe: `Thor 27`

## 4.3. Importância dessa amarração
Essa relação será o ponto de partida da auditoria, porque permite montar a cadeia:

**encarregado -> equipe -> veículo -> GPS -> produção -> fotos -> ponto dos colaboradores**

Sem essa amarração, a auditoria perde o eixo.
Com essa amarração, o sistema passa a saber:

- quem trabalhou
- com qual encarregado
- em qual equipe
- em qual veículo
- em qual produção
- em qual local
- em qual janela de tempo

---

# 5. SEGUNDO PILAR: IDENTIFICAÇÃO DO VEÍCULO DA EQUIPE

## 5.1. Veículo como elo operacional
O caminhão/equipamento da equipe será tratado como a referência física da operação.

A partir do momento em que o lançamento da produção já identifica qual veículo estava com aquela equipe, torna-se possível:

- buscar o histórico GPS do veículo naquele dia
- identificar horário de início de deslocamento / operação
- identificar horário de parada / encerramento
- cruzar essa informação com os pontos do motorista
- cruzar essa informação com o local real do serviço

## 5.2. Função do apelido do veículo
O apelido do veículo é essencial para conectar as bases.

Exemplo:
- `Thor 27`

Esse apelido deve permitir localizar:
- qual ativo CEABS corresponde a esse veículo
- quais registros GPS pertencem a ele
- qual equipe operou com esse veículo naquele dia

## 5.3. Resultado esperado
Ao final dessa etapa, o sistema precisa responder:

- qual equipe trabalhou no dia
- qual encarregado liderou
- qual veículo foi usado
- qual trilha GPS pertence àquela equipe naquele dia

---

# 6. TERCEIRO PILAR: PONTO DE VERDADE DA EQUIPE

## 6.1. Regra operacional dos ajudantes e demais integrantes
Para os integrantes da equipe que **não são motoristas**, a regra desejada é:

- entrada deve ocorrer no local real do trabalho
- saída deve ocorrer no local real do trabalho
- o ponto deve ser coerente com o último local efetivo de produção do dia

## 6.2. Problema prático que a auditoria resolve
Em muitos dias, a equipe pode trabalhar em:

- 1 ponto
- 2 pontos
- 3 pontos
- 4 ou mais pontos

Logo, não basta olhar o primeiro local do dia.
É necessário descobrir:

- onde a equipe estava no encerramento da operação
- qual foi a última evidência real do serviço
- em que momento o caminhão efetivamente se afastou desse local

## 6.3. Definição de "ponto de verdade" da equipe
Para os ajudantes e demais membros da equipe, o "ponto de verdade" do encerramento será definido por:

1. localizar a **última foto válida do dia**, com base no horário da produção
2. obter o georreferenciamento dessa foto
3. verificar no GPS do caminhão o momento em que ele estava naquele contexto operacional
4. identificar o momento em que o caminhão se afastou significativamente desse último ponto
5. usar esse horário como referência para validar a batida final dos integrantes da equipe

---

# 7. QUARTO PILAR: REGRA DO MOTORISTA

## 7.1. Lógica específica do motorista
O motorista tem uma lógica diferente do restante da equipe.

Isso ocorre porque ele:
- inicia atividades antes da equipe
- prepara o caminhão antes da saída
- encerra a jornada após a finalização prática da operação

## 7.2. Regra de entrada do motorista
O horário de entrada do motorista será comparado com o momento em que o caminhão **deu partida / começou a operar**.

Será aplicada tolerância de **10 minutos antes** desse momento, para cobrir atividades como:

- conferir óleo
- conferir água
- checar pneus
- preparação geral do veículo

### Regra proposta
O ponto do motorista será considerado coerente se estiver dentro da janela:

- até **10 minutos antes** do primeiro uso/partida do caminhão
- até o horário efetivo do início do uso/partida do caminhão

Batidas fora dessa janela devem gerar alerta.

## 7.3. Regra de saída do motorista
O horário de saída do motorista será comparado com o horário em que o caminhão **foi desligado / encerrou a operação do dia**.

Será aplicada tolerância de **10 minutos após** esse momento.

### Regra proposta
O ponto do motorista será considerado coerente se estiver dentro da janela:

- no horário efetivo de encerramento do caminhão
- até **10 minutos após** o desligamento/fim da operação

Batidas fora dessa janela devem gerar alerta.

## 7.4. Resumo operacional do motorista
O motorista será auditado com base em dois marcos:

- **início real do caminhão**
- **fim real do caminhão**

Com tolerância operacional de 10 minutos.

---

# 8. QUINTO PILAR: REGRA DOS AJUDANTES E DEMAIS MEMBROS DA EQUIPE

## 8.1. Entrada
A entrada dos ajudantes deve refletir a chegada ao local de trabalho.

A referência poderá ser construída a partir de:
- primeira evidência operacional do dia
- chegada do caminhão ao local de produção
- foto inicial da atividade
- contexto da produção lançada

## 8.2. Saída
A saída dos ajudantes deve refletir o encerramento da atividade no local de trabalho.

A referência principal para isso será:
- **última foto válida do dia**
- local dessa foto
- momento em que o caminhão se afasta desse local em distância significativa

## 8.3. Critério de afastamento do caminhão
Foi sugerida a regra de considerar que o caminhão "encerrou o vínculo com aquele ponto" quando ele se afastar cerca de:

- **3 km do último ponto fotografado**

A partir desse momento, será marcado o horário-base de encerramento operacional daquele local.

## 8.4. Tolerância dos integrantes da equipe
Será aplicada tolerância de **10 minutos para mais ou para menos** em relação ao horário de referência definido.

### Regra proposta
A batida final do colaborador será considerada coerente se estiver dentro da janela:

- 10 minutos antes do horário de referência
- 10 minutos depois do horário de referência

Qualquer desvio maior deverá gerar alerta.

---

# 9. COMO SERÁ DEFINIDO O HORÁRIO DE REFERÊNCIA FINAL DA EQUIPE

## 9.1. Etapa 1
Localizar todas as fotos válidas vinculadas à produção do dia.

## 9.2. Etapa 2
Ordenar essas fotos por horário.

## 9.3. Etapa 3
Selecionar a **última foto do dia**.

## 9.4. Etapa 4
Obter:
- latitude
- longitude
- horário
- vínculo com produção/equipe/encarregado

## 9.5. Etapa 5
Cruzar com a trilha GPS do caminhão da equipe.

## 9.6. Etapa 6
Localizar o momento em que o caminhão:
- estava nesse contexto de operação
- e depois se afastou aproximadamente **3 km** desse último ponto

## 9.7. Etapa 7
Registrar esse horário como:
- **horário operacional de saída da equipe**

## 9.8. Etapa 8
Comparar esse horário com os pontos dos ajudantes e demais integrantes da equipe.

---

# 10. TIPOS DE ALERTA A SEREM GERADOS

## 10.1. Alertas do motorista
- motorista bateu entrada muito antes da partida do caminhão
- motorista bateu entrada depois da partida do caminhão
- motorista bateu saída muito antes do encerramento do caminhão
- motorista bateu saída muito depois do encerramento do caminhão

## 10.2. Alertas dos ajudantes / equipe
- colaborador encerrou ponto antes demais em relação ao fim operacional
- colaborador encerrou ponto depois demais em relação ao fim operacional
- colaborador sem coerência com local da última operação
- colaborador com ponto incompatível com o deslocamento do caminhão
- equipe com múltiplas divergências no mesmo dia

## 10.3. Alertas estruturais
- produção sem foto suficiente para auditoria
- veículo não localizado / apelido sem vínculo CEABS
- trilha GPS insuficiente no dia
- equipe lançada sem vínculo claro com veículo
- colaborador apontado na produção sem ponto correspondente
- ponto existente sem vínculo com produção/equipe

---

# 11. ESTRATÉGIA DE IMPLEMENTAÇÃO EM FASES

A implementação deve ser feita em fases, para reduzir risco e facilitar validação.

---

# 12. FASE 1 – MAPEAMENTO E AMARRAÇÃO DE DADOS

## Objetivo
Garantir que o sistema consiga montar com segurança a cadeia de vínculo entre:

- encarregado
- equipe
- produção
- veículo
- GPS
- fotos
- ponto

## Entregas esperadas
- confirmar como o encarregado é identificado no banco
- confirmar como a equipe é reconhecida a partir do usuário do encarregado
- confirmar em qual tabela/campo está o apelido do veículo da equipe
- confirmar como vincular esse apelido ao ativo CEABS correto
- confirmar em qual estrutura estão as fotos da produção e seus metadados
- confirmar como localizar os colaboradores efetivamente lançados naquela produção
- confirmar o cruzamento entre colaboradores lançados e registros de ponto do Tangerino

## Resultado final da fase
Criar um mapa de relacionamento funcional entre todas as bases envolvidas.

---

# 13. FASE 2 – DEFINIÇÃO OFICIAL DAS REGRAS DE NEGÓCIO

## Objetivo
Transformar a lógica discutida em regras oficiais e inequívocas.

## Pontos a formalizar
- tolerância do motorista na entrada: 10 minutos antes do início do caminhão
- tolerância do motorista na saída: 10 minutos após o fim do caminhão
- distância para considerar saída do último ponto operacional: 3 km
- tolerância dos ajudantes: 10 minutos para mais ou para menos
- definição de qual foto será considerada válida
- critérios para escolher a última foto do dia
- comportamento em dias com múltiplas frentes de serviço
- comportamento quando faltar foto
- comportamento quando faltar GPS
- comportamento quando a equipe mudar de veículo
- comportamento quando o encarregado lançar produção em mais de um contexto no mesmo dia

## Resultado final da fase
Documento fechado com todas as regras do auditor.

---

# 14. FASE 3 – CONSOLIDAÇÃO DA BASE DE AUDITORIA

## Objetivo
Criar uma visão consolidada por dia/equipe/colaborador, para servir como base da auditoria.

## Essa base deverá permitir responder
- quem trabalhou no dia
- em qual equipe
- com qual encarregado
- em qual veículo
- quais foram os horários de ponto
- quais foram as fotos do dia
- qual foi a última foto
- qual foi o local da última foto
- qual foi o momento em que o caminhão se afastou desse local
- qual foi o horário real de início/fim do caminhão
- se o colaborador é motorista ou não
- se o ponto ficou dentro ou fora da tolerância

## Resultado final da fase
Uma base única pronta para ser auditada por regra.

---

# 15. FASE 4 – MOTOR DE AUDITORIA

## Objetivo
Aplicar as regras de negócio sobre a base consolidada.

## O motor deverá classificar
- conforme
- divergente leve
- divergente relevante
- crítico

## Regras iniciais sugeridas
### Motorista
- entrada fora da janela permitida
- saída fora da janela permitida

### Equipe
- saída fora da janela do fim operacional
- ponto incompatível com o contexto do último local
- colaborador sem coerência com equipe/produção

### Estruturais
- ausência de foto
- ausência de GPS
- ausência de vínculo entre produção e veículo

## Resultado final da fase
Gerar registros objetivos de alerta.

---

# 16. FASE 5 – ENTREGA DOS ALERTAS

## Objetivo
Definir como os alertas serão consumidos operacionalmente.

## Canais possíveis
- tabela interna de auditoria
- painel administrativo
- relatório diário
- resumo por equipe
- envio por WhatsApp
- resumo para RH / supervisão / diretoria

## Formatos sugeridos
### Formato detalhado
- data
- equipe
- encarregado
- colaborador
- função
- veículo
- tipo de alerta
- horário real esperado
- horário batido
- diferença em minutos
- evidência usada
- severidade

### Formato resumido
- total de divergências do dia
- equipes com maior incidência
- motoristas com maior desvio
- ajudantes com maior desvio
- problemas estruturais que impediram auditoria

## Resultado final da fase
Alertas utilizáveis na rotina operacional.

---

# 17. FASE 6 – CAMADA DE INTELIGÊNCIA / IA

## Objetivo
Adicionar inteligência para resumir, priorizar e explicar os casos, sem substituir a regra objetiva.

## A IA deverá entrar para
- resumir os alertas do dia
- identificar padrões recorrentes
- agrupar problemas por equipe/encarregado
- separar erro operacional de suspeita mais séria
- gerar textos prontos para análise de gestão
- futuramente sugerir cobrança automática de justificativa

## Importante
Nesta etapa, a IA **não deve ser a base da decisão**.

A decisão inicial deve continuar sendo:
- regra objetiva
- tolerância objetiva
- vínculo operacional objetivo

A IA entra como:
- explicadora
- resumidora
- priorizadora

## Resultado final da fase
Um auditor mais inteligente e mais fácil de interpretar.

---

# 18. PRINCIPAIS DECISÕES FUNCIONAIS A VALIDAR ANTES DO CÓDIGO

Antes de escrever qualquer código, precisamos validar oficialmente os seguintes pontos:

## 18.1. Sobre equipe
- equipe será oficialmente representada pelo usuário do encarregado?
- haverá algum caso em que o mesmo usuário represente mais de uma equipe no mesmo dia?

## 18.2. Sobre veículo
- o apelido do veículo é sempre confiável e obrigatório?
- existe tabela oficial de vínculo entre apelido e ativo CEABS?

## 18.3. Sobre motorista
- como identificar de forma inequívoca quem é o motorista da equipe no dia?
- haverá casos com troca de motorista no mesmo dia?

## 18.4. Sobre fotos
- quais fotos podem ser usadas como evidência?
- qual é a fonte oficial das fotos?
- toda foto possui georreferenciamento e horário confiável?
- como tratar fotos sem coordenada?

## 18.5. Sobre GPS
- qual é a granularidade do CEABS?
- qual frequência de atualização será considerada segura para a auditoria?
- como tratar falha de sinal?

## 18.6. Sobre a distância de 3 km
- 3 km será fixo para todos os cenários?
- ou poderá variar por tipo de operação?

## 18.7. Sobre tolerâncias
- 10 minutos é fixo para todos os colaboradores?
- haverá tolerância diferente para contextos especiais?

---

# 19. RISCOS E CUIDADOS DO PROJETO

## 19.1. Risco de falso positivo
Se a regra estiver muito rígida, o sistema pode acusar divergência onde existe operação normal.

## 19.2. Risco de base mal vinculada
Se houver falha no vínculo entre equipe e veículo, todo o restante fica comprometido.

## 19.3. Risco de foto fraca como evidência
Nem sempre a última foto enviada representa exatamente o encerramento real da operação.

## 19.4. Risco de GPS imperfeito
A trilha do caminhão pode sofrer atraso, ruído ou ausência de atualização.

## 19.5. Risco de múltiplos pontos no dia
Dias com várias frentes exigem uma regra bem amarrada para escolher a evidência final correta.

---

# 20. BENEFÍCIOS ESPERADOS

Com esse módulo implantado, a empresa passa a ter:

- auditoria muito mais objetiva do ponto externo
- validação do motorista com base no uso real do veículo
- validação da equipe com base no local real de produção
- redução de distorções manuais
- mais disciplina operacional
- mais força para cobranças internas
- base histórica para reincidência e gestão
- possibilidade futura de cobrança automática por WhatsApp
- aumento da confiabilidade do apontamento de mão de obra

---

# 21. ORDEM RECOMENDADA DOS PRÓXIMOS PASSOS

## Passo 1
Mapear oficialmente as tabelas e campos envolvidos:
- usuário do encarregado
- equipe
- produção
- colaboradores lançados
- apelido do veículo
- vínculo com CEABS
- fotos
- ponto Tangerino

## Passo 2
Fechar as regras oficiais:
- motorista
- ajudantes
- última foto
- distância
- tolerâncias
- exceções

## Passo 3
Desenhar a base consolidada de auditoria

## Passo 4
Desenhar o motor de alertas

## Passo 5
Somente depois disso, criar os prompts para o Claude executar a implementação

---

# 22. CONCLUSÃO EXECUTIVA

A proposta é plenamente viável porque o projeto já possui quase todos os pilares necessários.

O que falta agora não é "inventar um sistema do zero", e sim **amarrar corretamente os vínculos e oficializar as regras de coerência operacional**.

A lógica principal ficará assim:

- o **encarregado** amarra a equipe
- a **produção** amarra os colaboradores do dia
- o **apelido do veículo** amarra o caminhão da equipe
- o **CEABS** mostra o comportamento real do veículo
- a **última foto georreferenciada** ajuda a definir o último ponto real de operação
- o **Tangerino** mostra onde e quando cada colaborador bateu ponto
- a **auditoria** compara tudo isso e gera alerta quando houver desvio acima da tolerância

Esse módulo tem potencial para virar uma das camadas mais fortes de governança operacional do ponto, porque deixa de olhar apenas "horário batido" e passa a olhar **coerência real entre pessoa, equipe, caminhão e trabalho executado**.

---

# 23. PRÓXIMA ETAPA SUGERIDA

Após aprovação deste plano, a próxima entrega recomendada será:

**Documento 2 – Mapeamento técnico das fontes de dados e regras oficiais do auditor**

Esse próximo documento deverá listar:
- tabelas
- campos
- chaves de vínculo
- regras definitivas
- exceções
- critérios de fallback

A partir dele, ficará seguro preparar os prompts de implementação para o Claude.

# DOCUMENTO 2 — MAPEAMENTO TÉCNICO DO AUDITOR DE PONTO
## Auditoria Inteligente de Ponto por Geolocalização, Veículo e Produção

**Data:** 18-03-2026
**Status:** Mapeamento técnico das fontes de dados
**Fase:** Pré-código / levantamento de tabelas, campos, vínculos e regras
**Base:** Documento 1 (`AUDITORIA_PONTO_GEOLOCALIZACAO.md`)

---

# 1. FONTES DE DADOS

## 1.1. Ponto do Colaborador (Tangerino)

| Fonte | Descrição |
|---|---|
| **Sistema:** | Tangerino (ponto eletrônico) |
| **Tabela consolidada:** | `fato_ponto_diario` |
| **Tabela de batidas brutas:** | `espelho_tangerino_punch` |
| **Geolocalização:** | Dentro do `payload_json` da `espelho_tangerino_punch` |
| **Sincronização:** | Pipeline RH roda diariamente (00:10 VPS1) + intraday a cada 30min |

**Importante:** A tabela `fato_ponto_diario` **NÃO possui** latitude/longitude diretamente. A geolocalização completa está no campo `payload_json` da tabela `espelho_tangerino_punch`, que contém as coordenadas de entrada e saída de cada batida.

## 1.2. GPS do Veículo (CEABS)

| Fonte | Descrição |
|---|---|
| **Sistema:** | CEABS (rastreamento veicular) |
| **API:** | `apicps.ceabs.com.br` |
| **Acesso:** | VPS1 → proxy → CEABS API |
| **Dados:** | Posição GPS, ignição (ON/OFF), velocidade, timestamp |
| **16 veículos** com rastreador CEABS ativo de 39 no total |

**Endpoint principal:** `fleet_status` retorna posição atual + estado da ignição.
**Histórico:** Necessário verificar se a API CEABS fornece trilha GPS histórica por dia ou apenas posição atual.

## 1.3. Produção / Medições

| Fonte | Descrição |
|---|---|
| **Sistema:** | App de produção (globalsinalizacao.online) |
| **Tabela:** | `Tab_medicao_nova` |
| **Fotos:** | Campos inline (`69_foto_antes`, `70_foto_depois`) — URLs no servidor |
| **Localização:** | Via rodovia + km (`5_rodovia` + `6_km_inicial`), **NÃO por coordenadas GPS** |
| **Encarregado:** | Campo `3_usuario` (login do encarregado) |
| **Equipe:** | Campos `71_funcionario1` até `85_funcionario15` (nomes dos colaboradores) |
| **Veículo:** | Campos `88_equipamento_id` + `89_equipamento_apelido` |

## 1.4. Cadastro de Colaboradores

| Fonte | Descrição |
|---|---|
| **Tabela:** | `Tab_colaboradores` |
| **Chave Tangerino:** | `tangerino_employee_id` |
| **Função/Cargo:** | Campo `funcao` (varchar livre) |
| **Alocação:** | `alocacao_fixa` (OBRA, ADM, OFICINA) |

## 1.5. Frota / Veículos

| Fonte | Descrição |
|---|---|
| **Tabela:** | `Tab_frota` |
| **Apelido:** | Campo `apelido` (ex: "THOR 8", "APOIO LIDER 3") |
| **Placa:** | Campo `placa` |
| **Motorista atual:** | `motorista_atual_nome` + `motorista_atual_telefone` |
| **Encarregado atual:** | `encarregado_atual_nome` + `encarregado_atual_telefone` |

---

# 2. TABELAS E CAMPOS

## 2.1. `espelho_tangerino_punch` — Batidas brutas com geolocalização

| Campo | Tipo | Uso na Auditoria |
|---|---|---|
| `id` | bigint PK | identificador |
| `punch_id` | varchar UNIQUE | ID original Tangerino |
| `employee_id` | bigint | vínculo com colaborador |
| `date_iso` | date | data da batida |
| `date_in_iso` | datetime | horário de entrada |
| `date_out_iso` | datetime | horário de saída |
| `status` | varchar | status da batida |
| `payload_json` | longtext | **CAMPO CHAVE — contém geolocalização completa** |

### Estrutura do `payload_json` (campos relevantes)

```json
{
  "locationIn": {
    "latitude": -23.9985296,
    "longitude": -51.3269172,
    "address": "Avenida Eugenio Bastiane, 1091, Faxinal - Faxinal, Parana"
  },
  "locationOut": {
    "latitude": -23.9991234,
    "longitude": -51.3275678,
    "address": "Rua XV de Novembro, 500, Faxinal, Parana"
  },
  "photoIn": {
    "photoURL": "https://..."
  },
  "photoOut": {
    "photoURL": "https://..."
  },
  "workPlace": {
    "name": "OBRAS"
  },
  "plataform": "Android",
  "version": "5.x"
}
```

## 2.2. `fato_ponto_diario` — Ponto consolidado por dia

| Campo | Tipo | Uso na Auditoria |
|---|---|---|
| `id` | bigint PK | identificador |
| `data_ref` | date | data do ponto |
| `employee_id` | bigint | vínculo Tangerino |
| `employee_nome` | varchar(255) | nome do colaborador |
| `minutos_trabalhados` | int | total trabalhado |
| `horas_trabalhadas` | decimal(10,2) | total em horas |
| `status_dia` | varchar(20) | PENDENTE, PRESENTE, etc. |
| `punches_total` | int | total de batidas |
| `punches_validos` | int | batidas aprovadas |
| `alertas_json` | longtext | alertas já gerados (ex: LOCAL_ENTRADA_DIFERENTE_SAIDA) |

## 2.3. `Tab_medicao_nova` — Produção / Medições

| Campo | Tipo | Uso na Auditoria |
|---|---|---|
| `3_usuario` | varchar | **login do encarregado** (chave da equipe) |
| `4_data` / `data_ref` | date | data da medição |
| `5_rodovia` | varchar | rodovia da operação |
| `6_km_inicial` | varchar | km inicial da operação |
| `69_foto_antes` | text | URL foto início do trabalho |
| `70_foto_depois` | text | URL foto fim do trabalho |
| `71_funcionario1` ... `85_funcionario15` | varchar | nomes dos colaboradores na equipe |
| `88_equipamento_id` | int | ID do veículo/equipamento |
| `89_equipamento_apelido` | varchar | **apelido do veículo** (ex: "Thor 27") |
| `is_nao_produtivo` | tinyint | flag dia não produtivo |
| `motivo_nao_produtivo` | varchar | motivo (CHUVA, FALTA MATERIAL, etc.) |

## 2.4. `Tab_colaboradores` — Cadastro

| Campo | Tipo | Uso na Auditoria |
|---|---|---|
| `Id` | int PK | identificador interno |
| `nome` | varchar | nome completo |
| `tangerino_employee_id` | bigint | **chave de vínculo com Tangerino** |
| `login` | varchar | login no app |
| `funcao` | varchar | cargo/função (texto livre) |
| `status` | varchar | ativo/inativo |
| `alocacao_fixa` | enum | OBRA, ADM, OFICINA |

## 2.5. `Tab_frota` — Frota de veículos

| Campo | Tipo | Uso na Auditoria |
|---|---|---|
| `apelido` | varchar | **nome operacional** (ex: "THOR 8") |
| `placa` | varchar | placa do veículo |
| `Status` | varchar | Ativo/Inativo |
| `motorista_atual_nome` | varchar | motorista vinculado atualmente |
| `motorista_atual_telefone` | varchar | telefone do motorista |
| `encarregado_atual_nome` | varchar | encarregado vinculado |
| `encarregado_atual_telefone` | varchar | telefone encarregado |
| `status_bloqueio` | varchar | LIVRE, BLOQUEADO_OPERACIONAL, etc. |

## 2.6. `Tab_encarregados_whatsapp` — Encarregados

| Campo | Tipo | Uso na Auditoria |
|---|---|---|
| `id` | int PK | identificador |
| `login` | varchar(100) UNIQUE | **vínculo com `3_usuario` da medição** |
| `telefone` | varchar(20) | telefone WhatsApp |
| `ativo` | tinyint | ativo/inativo |

---

# 3. CHAVES DE VÍNCULO

## 3.1. Cadeia completa de relacionamento

```text
Tab_encarregados_whatsapp.login
        ↕ (= Tab_medicao_nova.3_usuario)
Tab_medicao_nova
        ├── .71_funcionario1 ... .85_funcionario15 (nomes dos colaboradores)
        │       ↕ (match por NOME com Tab_colaboradores.nome)
        │   Tab_colaboradores.tangerino_employee_id
        │       ↕ (= espelho_tangerino_punch.employee_id)
        │   espelho_tangerino_punch.payload_json → lat/lon entrada/saída
        │
        ├── .89_equipamento_apelido (apelido do veículo)
        │       ↕ (match por APELIDO com Tab_frota.apelido)
        │   Tab_frota.placa
        │       ↕ (placa → ativo CEABS → GPS)
        │   API CEABS → posição, ignição, velocidade
        │
        └── .69_foto_antes / .70_foto_depois (URLs das fotos)
                → fotos no servidor com horário de upload
```

## 3.2. Vínculos detalhados

| De | Para | Campo de vínculo | Tipo de match |
|---|---|---|---|
| Encarregado → Medição | `Tab_encarregados_whatsapp.login` | `Tab_medicao_nova.3_usuario` | EXATO |
| Medição → Colaboradores | `Tab_medicao_nova.71_funcionarioN` | `Tab_colaboradores.nome` | **POR NOME (risco)** |
| Colaborador → Ponto | `Tab_colaboradores.tangerino_employee_id` | `espelho_tangerino_punch.employee_id` | EXATO |
| Medição → Veículo | `Tab_medicao_nova.89_equipamento_apelido` | `Tab_frota.apelido` | **POR APELIDO (normalizar)** |
| Veículo → GPS CEABS | `Tab_frota.placa` | API CEABS (ativo) | EXATO (placa) |
| Veículo → Motorista | `Tab_frota.motorista_atual_nome` | `Tab_colaboradores.nome` | **POR NOME (risco)** |

## 3.3. Pontos críticos de vínculo

### ALERTA 1 — Colaboradores vinculados por NOME
Os campos `71_funcionario1` até `85_funcionario15` contêm **nomes livres** (texto). O match com `Tab_colaboradores.nome` precisa ser **normalizado** (maiúsculas, acentos, abreviações).

### ALERTA 2 — Apelido do veículo precisa de normalização
O campo `89_equipamento_apelido` pode ter variações ("Thor 27", "THOR 27", "thor27"). Comparação com `Tab_frota.apelido` precisa ignorar case e espaços.

### ALERTA 3 — Motorista identificado por nome na Tab_frota
O campo `motorista_atual_nome` é texto livre. Precisa de match normalizado com `Tab_colaboradores.nome`.

### ALERTA 4 — Fotos sem georreferenciamento próprio
As fotos da produção (`69_foto_antes`, `70_foto_depois`) são URLs de arquivo. O **georreferenciamento da foto depende dos metadados EXIF** do arquivo original. Necessário verificar se as fotos preservam EXIF com lat/lon ao serem salvas no servidor.

### ALERTA 5 — Localização da medição é por rodovia+km, não por coordenadas
A medição usa `5_rodovia` + `6_km_inicial` como referência espacial. Para cruzamento geográfico com GPS do caminhão ou ponto do Tangerino, seria necessário **converter rodovia+km em coordenadas** ou usar abordagem alternativa.

---

# 4. REGRAS OFICIAIS

## 4.1. Regras do Motorista

| Regra | Descrição | Tolerância |
|---|---|---|
| **ENTRADA** | Ponto de entrada do motorista vs. primeiro evento de ignição ON do caminhão | Até **10 min antes** da ignição ON |
| **SAÍDA** | Ponto de saída do motorista vs. último evento de ignição OFF do caminhão | Até **10 min depois** da ignição OFF |
| **IDENTIFICAÇÃO** | Motorista = `Tab_frota.motorista_atual_nome` matched com `Tab_colaboradores` | Match por nome normalizado |

### Classificação de desvio — Motorista

| Desvio | Severidade |
|---|---|
| Dentro da janela (±10min) | **CONFORME** |
| 10–30 min fora da janela | **DIVERGENTE LEVE** |
| 30–60 min fora da janela | **DIVERGENTE RELEVANTE** |
| > 60 min fora da janela | **CRÍTICO** |

## 4.2. Regras dos Ajudantes / Equipe

| Regra | Descrição | Tolerância |
|---|---|---|
| **SAÍDA** | Ponto de saída do colaborador vs. horário de referência operacional | ±**10 min** do horário de referência |
| **HORÁRIO DE REFERÊNCIA** | Momento em que o caminhão se afastou **3 km** do último ponto de operação | Calculado via GPS CEABS |
| **ÚLTIMO PONTO DE OPERAÇÃO** | Definido pela última foto válida do dia OU último km registrado na medição | Foto com EXIF > rodovia+km |

### Classificação de desvio — Equipe

| Desvio | Severidade |
|---|---|
| Dentro da janela (±10min) | **CONFORME** |
| 10–30 min fora da janela | **DIVERGENTE LEVE** |
| 30–60 min fora da janela | **DIVERGENTE RELEVANTE** |
| > 60 min fora da janela | **CRÍTICO** |

## 4.3. Regras Estruturais

| Regra | Condição | Ação |
|---|---|---|
| **SEM_FOTO** | Medição sem `69_foto_antes` e `70_foto_depois` | Alerta estrutural — auditoria limitada |
| **SEM_GPS** | Veículo sem dados CEABS no dia ou sem rastreador ativo | Alerta estrutural — auditoria limitada |
| **SEM_VEICULO** | `89_equipamento_apelido` vazio ou sem match na `Tab_frota` | Alerta estrutural — cadeia quebrada |
| **SEM_PONTO** | Colaborador na medição sem registro em `espelho_tangerino_punch` | Alerta estrutural — ausência de ponto |
| **PONTO_SEM_PRODUCAO** | Ponto existente sem vínculo com nenhuma medição do dia | Alerta informativo |
| **SEM_MOTORISTA** | `Tab_frota.motorista_atual_nome` vazio para o veículo da equipe | Alerta estrutural — não pode auditar motorista |

---

# 5. EXCEÇÕES E FALLBACKS

## 5.1. Exceções operacionais

| Situação | Tratamento |
|---|---|
| **Dia não produtivo** | Se `is_nao_produtivo = 1` na medição → pular auditoria desse dia/equipe |
| **Múltiplas frentes** | Encarregado com >1 medição no mesmo dia → usar a ÚLTIMA medição como referência para saída |
| **Troca de motorista** | Se `motorista_atual_nome` mudou no meio do dia → auditar cada trecho separadamente (implementação futura) |
| **Colaborador em 2 equipes** | Mesmo nome em medições de 2 encarregados diferentes → gerar alerta de duplicidade, não auditar automaticamente |
| **Colaborador ADM** | `alocacao_fixa = ADM` → excluir da auditoria de campo |

## 5.2. Fallbacks quando falta dado

| Dado ausente | Fallback |
|---|---|
| **Foto sem EXIF/geo** | Usar rodovia+km da medição como referência aproximada |
| **GPS CEABS indisponível** | Auditar apenas com geolocalização do ponto Tangerino vs. local da medição |
| **Geolocalização do ponto ausente** | `payload_json` sem `locationIn`/`locationOut` → marcar como "auditoria incompleta" |
| **Apelido do veículo não encontrado** | Tentar normalização (case insensitive, sem espaços) → se falhar, alerta SEM_VEICULO |
| **Nome do colaborador não encontrado** | Tentar match parcial (LIKE) → se falhar, alerta de vínculo quebrado |
| **Veículo sem rastreador CEABS** | Dos 39 veículos, apenas 16 têm CEABS ativo → para os demais, auditar sem GPS |

## 5.3. Conversão rodovia+km → coordenadas (fallback geográfico)

A medição registra localização como `5_rodovia` + `6_km_inicial` (ex: "PR-090", "km 152").

**Opções para converter em coordenadas:**
1. Manter tabela de referência `rodovia + km → lat/lon` (alimentada manualmente ou por API)
2. Usar a geolocalização da primeira batida de ponto do encarregado como proxy do local da obra
3. Usar a posição GPS do caminhão no horário da medição como proxy

**Decisão pendente** — qual abordagem adotar.

---

# 6. DÚVIDAS EM ABERTO

## 6.1. Geolocalização das fotos

> As fotos salvas em `/app/fotos_medicao/` preservam os metadados EXIF (latitude/longitude)?
> Ou o processo de upload/resize remove o EXIF?

**Impacto:** Se não houver EXIF, o pilar "última foto georreferenciada" perde precisão. O fallback seria usar a posição do caminhão no horário da foto.

## 6.2. Histórico GPS CEABS

> A API CEABS fornece trilha GPS histórica (posições ao longo do dia)?
> Ou apenas a posição atual em tempo real?

**Impacto:** Se não houver histórico, será necessário **coletar e armazenar** posições via cron (ex: a cada 5 min) para construir a trilha do dia.

## 6.3. Identificação inequívoca do motorista

> O campo `Tab_frota.motorista_atual_nome` é atualizado em tempo real?
> Ou pode estar desatualizado em relação ao motorista real do dia?

**Impacto:** Se não for confiável, seria necessário cruzar com a medição (verificar se algum dos `funcionarioN` é motorista pela `funcao` na `Tab_colaboradores`).

## 6.4. Granularidade do CEABS

> Qual a frequência de atualização da posição GPS? (a cada 30s? 1min? 5min?)

**Impacto:** Define a precisão do cálculo de "momento em que o caminhão se afastou 3km".

## 6.5. Múltiplas fotos por medição

> Existem medições com mais de 2 fotos (além de foto_antes e foto_depois)?
> Ou são sempre exatamente 2?

**Impacto:** Se sempre 2, a "última foto do dia" será `70_foto_depois` da última medição. Se houver mais, precisamos de outra estratégia.

## 6.6. Conversão rodovia+km

> Existe alguma tabela de referência de coordenadas por rodovia+km no sistema?
> Ou essa conversão precisará ser construída do zero?

**Impacto:** Define o esforço da Fase 1 e se o cruzamento geográfico será possível desde o início.

---

# 7. DECISÕES JÁ FECHADAS

| # | Decisão | Data |
|---|---|---|
| 1 | Equipe é representada pelo **login do encarregado** (`3_usuario`) | 18-03-2026 |
| 2 | Veículo da equipe identificado pelo **apelido** (`89_equipamento_apelido`) | 18-03-2026 |
| 3 | Tolerância do motorista: **10 min antes** da ignição ON, **10 min depois** da ignição OFF | 18-03-2026 |
| 4 | Tolerância dos ajudantes: **±10 min** do horário de referência | 18-03-2026 |
| 5 | Distância de afastamento para encerramento: **3 km** do último ponto operacional | 18-03-2026 |
| 6 | Severidades: Conforme / Divergente Leve / Divergente Relevante / Crítico | 18-03-2026 |
| 7 | Dias não produtivos são **excluídos** da auditoria | 18-03-2026 |
| 8 | Colaboradores ADM (`alocacao_fixa = ADM`) são **excluídos** da auditoria de campo | 18-03-2026 |
| 9 | Geolocalização do ponto vem do `payload_json` da `espelho_tangerino_punch` | 18-03-2026 |
| 10 | Fotos da produção estão em campos inline (`69_foto_antes`, `70_foto_depois`) | 18-03-2026 |
| 11 | Cadeia de vínculo: encarregado → medição → funcionários (por nome) → Tangerino (por employee_id) | 18-03-2026 |
| 12 | Cadeia de vínculo: medição → apelido → Tab_frota → placa → CEABS GPS | 18-03-2026 |

---

# 8. PRÓXIMA ETAPA

Com este mapeamento técnico concluído, as próximas ações são:

## 8.1. Resolver dúvidas em aberto (Seção 6)
Validar com testes diretos no banco e nos arquivos:
- EXIF das fotos
- Histórico GPS CEABS
- Confiabilidade do motorista_atual_nome
- Granularidade do GPS

## 8.2. Iniciar Fase 1 — Mapeamento e Amarração
Criar script de validação que, dado um dia:
1. Liste todas as medições
2. Identifique encarregado, equipe, veículo
3. Cruze colaboradores com ponto Tangerino
4. Cruze veículo com GPS CEABS
5. Verifique disponibilidade de fotos e geo
6. Gere relatório de integridade da cadeia de dados

## 8.3. Construir base consolidada (Fase 3)
Criar tabela `auditoria_ponto_consolidada` com todos os dados cruzados por dia/equipe/colaborador.

---

> **Este documento é a base técnica oficial para implementação do Auditor de Ponto por Geolocalização.**
> **Qualquer alteração de regra deve ser registrada aqui antes de refletir no código.**

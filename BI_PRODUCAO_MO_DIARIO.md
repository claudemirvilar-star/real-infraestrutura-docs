# BI PRODUÇÃO — MO DIÁRIO (MOTOR DE CUSTO REAL)

> Ficha técnica auditada — gerada por cruzamento entre documentação e código em produção (VPS2)
> Última auditoria: 2026-03-17

---

## STATUS

| Campo | Valor |
|-------|-------|
| Status | **PRODUÇÃO ATIVA** |
| Motor | `motor_mo_real_v1.php` (Tangerino-only, gerencial desativado) |
| Registros fato_mo_diaria_colab | **5.374** |
| Registros fato_mo_diaria_colab_obra | **5.374** |
| Registros fato_resultado_diario_obra | **459** |
| Logs do motor | **1.307 execuções** |
| Período coberto | 2025-01-08 a 2026-03-17 |
| Dias processados | **132** |
| Colaboradores distintos | **133** |
| Obras distintas | **14** |
| Tangerino punches | **26.822** (88 employees, desde 2023-09-01) |

---

## OBJETIVO

Apurar o custo real de mão de obra por:
- Centro de custo (obra) / dia / colaborador
- Cruzamento automático: ponto Tangerino × alocação por medição
- Rateio proporcional quando colaborador atua em múltiplas obras

Gerar base para:
- Análise de margem (produção - MO)
- Rankings de eficiência por obra
- Alertas inteligentes (Donna — cobrança de ponto)
- DRE gerencial (futuro)

---

## ARQUITETURA

```
Tangerino (API Sólides)
        │
        ▼
espelho_tangerino_punch (sync diário 05:00)
        │
        ├──► Tab_colaboradores (tangerino_employee_id)
        │
        ▼
mo_custo_colab_lib.php (calcula custo/dia por colaborador)
        │
        ├──► horas trabalhadas (E1/S1/E2/S2)
        ├──► custo mensal / dias úteis
        └──► detecção de pendências (sem_batida, bt_impar)
        │
        ▼
mo_alocacao_medicao_lib.php (onde o colaborador trabalhou)
        │
        ├──► Tab_medicao_nova (medições diárias)
        ├──► Funcionários 1-15 por medição
        └──► Peso de rateio por obra
        │
        ▼
motor_mo_real_v1.php (motor principal)
        │
        ├──► BEGIN TRANSACTION
        ├──► DELETE fato_mo_diaria_colab       WHERE data_ref = ?
        ├──► DELETE fato_mo_diaria_colab_obra  WHERE data_ref = ?
        ├──► DELETE fato_resultado_diario_obra WHERE data_ref = ?
        ├──► DELETE espelho_alertas_ponto_diario WHERE data_ref = ?
        ├──► INSERT cálculos novos
        ├──► COMMIT (ou ROLLBACK em caso de erro)
        └──► Grava log_motor_mo
        │
        ▼
Endpoints BI (Flutter + Donna)
```

---

## ARQUIVOS EM PRODUÇÃO (28 endpoints)

### Motor e bibliotecas

| Arquivo | Função |
|---------|--------|
| `motor_mo_real_v1.php` | Motor principal — idempotente (DELETE + INSERT em transação) |
| `mo_custo_colab_lib.php` | Cálculo de custo por colaborador/dia (Tangerino-only) |
| `mo_alocacao_medicao_lib.php` | Alocação por medição — mapeia colaborador × obra × peso |
| `motor_mo_gerencial_v1.php` | Motor gerencial (desativado — sempre retorna 0) |
| `mo_motor_diario_rebuild.php` | Rebuild completo para reprocessamento |

### Endpoints de consulta (Flutter/BI)

| Arquivo | Função |
|---------|--------|
| `mo_diario_consolidado_v1.php` | Dados consolidados do dia (resultado por obra + detalhe colab) |
| `listar_mo_diaria_v2.php` | Lista MO por colaborador/dia |
| `listar_mo_diaria_obra_equipe_v2.php` | MO por obra + equipe (modal Flutter) |
| `mo_ranking_margem_dia.php` | Ranking de obras por margem (semáforo verde/amarelo/vermelho) |
| `mo_resultado_diario_obra.php` | Resultado (produção - MO) por obra |
| `mo_resumo_obras_dia.php` | Resumo agregado |
| `mo_gerencial_periodo.php` | Gerencial por período |
| `mo_gerencial_resumo.php` | Resumo gerencial |
| `mo_calc_resumo.php` | Cálculo de resumo |
| `listar_producao_por_obra.php` | Produção por obra |

### Alertas e auditoria

| Arquivo | Função |
|---------|--------|
| `mo_alertas_diarios_listar.php` | Alertas de ponto/pendências |
| `mo_alertas_diarios_rebuild.php` | Rebuild de alertas |
| `mo_auditoria_obra_dia.php` | Auditoria detalhada por obra/dia |
| `health_mo_diario.php` | Health check do motor |

### Debug

| Arquivo | Função |
|---------|--------|
| `mo_debug_alocacao_medicao.php` | Debug de alocação |
| `mo_debug_custo_colab_dia.php` | Debug de custo por colaborador |
| `debug_primeira_data_com_medicao_v1.php` | Primeira data com medição |

### Cobrança de ponto (Donna)

| Arquivo | Função |
|---------|--------|
| `donna_cobranca_ponto_v2.php` | Motor de cobrança (doc separado) |
| `donna_executor_cobranca.php` | Executor |
| `donna_mensagens_encarregado.php` | Templates de mensagem |
| `pendencias_por_encarregado_v2.php` | Pendências agrupadas |
| `pendencias_por_encarregado.php` | Versão legada |

> **CORREÇÃO vs ficha original:** A ficha lista 2 endpoints. Na realidade existem **28 arquivos PHP** no módulo MO.

---

## BASE DE DADOS

### fato_mo_diaria_colab (5.374 registros)

```sql
id                      BIGINT(20) UNSIGNED  PK
data_ref                DATE                 INDEX
colaborador_id          BIGINT(20)           INDEX  -- hash md5 truncado (ou crc32 legado)
cpf                     VARCHAR(14)          INDEX
nome                    VARCHAR(120)
tem_batida_valida       TINYINT(1)
tem_memoria_ou_ajuste   TINYINT(1)
eh_folga                TINYINT(1)
mo_oficial              DECIMAL(14,2)               -- custo MO oficial do dia
mo_gerencial            DECIMAL(14,2)               -- sempre 0 (gerencial desativado)
origem_mo               VARCHAR(30)                 -- TANGERINO
detalhes_json           LONGTEXT                    -- JSON com batidas, horas, etc.
created_at              DATETIME
updated_at              DATETIME
```

### fato_mo_diaria_colab_obra (5.374 registros)

```sql
id                      BIGINT(20) UNSIGNED  PK
data_ref                DATE                 INDEX
colaborador_id          BIGINT(20)           INDEX
cpf                     VARCHAR(14)
usuario_id              INT(11)
usuario_nome            VARCHAR(120)
nome                    VARCHAR(120)
nro_registro            INT(11)              INDEX
obra                    VARCHAR(120)         INDEX  -- centro de custo
peso_rateio             DECIMAL(10,6)               -- peso proporcional na obra
producao_base           DECIMAL(14,2)               -- produção base da obra
mo_oficial_rateada      DECIMAL(14,2)               -- custo MO rateado para esta obra
mo_gerencial_rateada    DECIMAL(14,2)               -- sempre 0
detalhes_json           LONGTEXT
created_at              DATETIME
```

### fato_resultado_diario_obra (459 registros)

```sql
id                                  BIGINT(20) UNSIGNED  PK
data_ref                            DATE                 INDEX
obra                                VARCHAR(120)         INDEX
producao_total_dia                  DECIMAL(14,2)
mo_oficial_total_dia                DECIMAL(14,2)
mo_gerencial_total_dia              DECIMAL(14,2)
resultado_oficial_dia               DECIMAL(14,2)        -- produção - MO
margem_percentual_oficial_dia       DECIMAL(10,4)        -- margem %
resultado_gerencial_dia             DECIMAL(14,2)
margem_percentual_gerencial_dia     DECIMAL(10,4)
detalhes_json                       LONGTEXT
created_at                          DATETIME
```

> **CORREÇÃO vs ficha original:** A ficha lista `fato_resultado_diario_obra` como "ROADMAP - criar tabela". Na realidade **já existe** com 459 registros e 11 obras.

### log_motor_mo (1.307 execuções)

```sql
id                  INT(11)        PK
data_ref            DATE           INDEX
inicio              DATETIME
fim                 DATETIME
duracao_seg         DECIMAL(10,2)
mo_total            DECIMAL(14,2)
total_colaboradores INT(11)
total_obras         INT(11)
status              VARCHAR(20)    INDEX  -- OK | ERRO
modo                VARCHAR(30)
assinatura          VARCHAR(80)
erro                TEXT
criado_em           TIMESTAMP
```

> **CORREÇÃO vs ficha original:** A ficha indica "GAP: não há tabela de histórico de cálculo". Na realidade `log_motor_mo` **existe e está ativa** com 1.307 execuções desde 2025-11-01.

### espelho_tangerino_punch (26.822 registros)

```sql
punch_id             BIGINT(20)     UNIQUE
employee_id          BIGINT(20)     INDEX
employee_external_id VARCHAR(120)
date_iso             VARCHAR(32)
date_in_iso          VARCHAR(32)    -- entrada
date_out_iso         VARCHAR(32)    -- saída
status               VARCHAR(20)    INDEX
last_modified_iso    VARCHAR(40)    INDEX
payload_json         LONGTEXT       -- JSON slim com dados relevantes
fetched_at           TIMESTAMP
updated_at           TIMESTAMP
```

### espelho_alertas_ponto_diario

```sql
id              BIGINT(20)     PK
data_ref        DATE           INDEX
colaborador_id  BIGINT(20)     INDEX
cpf             VARCHAR(20)
nome            VARCHAR(120)
tipo_alerta     VARCHAR(60)    INDEX
gravidade       VARCHAR(10)
detalhe         VARCHAR(255)
severidade      VARCHAR(20)
mensagem        TEXT
detalhes_json   LONGTEXT
criado_em       DATETIME
```

---

## MOTOR DE CÁLCULO (auditado no código)

### Etapa 1 — Captura (Tangerino)

Fonte exclusiva: `espelho_tangerino_punch` (Secullum removido).
Sync diário às 05:00 via `donna_sync_tangerino_punch.sh`.

### Etapa 2 — Cálculo individual (`mo_custo_colab_lib.php`)

Para cada colaborador/dia:
- Extrai batidas (E1/S1/E2/S2) do espelho Tangerino
- Calcula minutos trabalhados
- Detecta anomalias (turno > 16h)
- Determina pendências: `sem_batida`, `bt_impar`
- Calcula: `custo_dia = salario_mensal / dias_uteis_mes × (horas_trabalhadas / jornada_diaria)`

### Etapa 3 — Alocação (`mo_alocacao_medicao_lib.php`)

Cruza colaborador × medições do dia:
- Busca em `Tab_medicao_nova` onde o colaborador aparece (campos funcionario1-15)
- Calcula peso de rateio (se colaborador em 2 obras: 50%/50%)
- Determina obra (centro de custo) de cada fração

### Etapa 4 — Consolidação (`motor_mo_real_v1.php`)

- **Transação atômica:** BEGIN → DELETE → INSERT → COMMIT (ROLLBACK em caso de erro)
- Grava em 3 tabelas fato + alertas
- Registra execução em `log_motor_mo`
- Idempotente: pode reprocessar qualquer dia sem duplicação

### Etapa 5 — Resultado por obra

- `producao_total_dia` (soma de metragem das medições)
- `mo_oficial_total_dia` (soma de MO rateada)
- `resultado_oficial_dia` = produção - MO
- `margem_percentual_oficial_dia`

---

## SYNC TANGERINO

| Item | Detalhe |
|------|---------|
| Script punch | `/usr/local/bin/donna_sync_tangerino_punch.sh` |
| Script employee | `/usr/local/bin/donna_sync_tangerino_employee.sh` |
| Cron punch | **05:00 diário** (sync D-1) |
| Cron employee | **05:30 diário** (match nome/nro_registro) |
| API | `https://apis.tangerino.com.br/punch/` |
| Token | `/app/token_solides.php` (SOLIDES_API_TOKEN) |
| Exceções | 6 colaboradores com tangerino_employee_id = -1 |

---

## OBRAS ATIVAS (Março 2026)

| Obra | Registros | MO Total R$ |
|------|-----------|-------------|
| PR Vias - Motiva CCR | 407 | 28.735 |
| Rodoanel-SP | 156 | 15.189 |
| Serget Campinas/SP | 73 | 7.056 |
| Via Line Defensa - RO | 11 | 1.199 |
| Ridarp Bosch Campinas - SP | 4 | 758 |
| Entrevias - Cabo | 89 | 0 (FOLGA_TRECHO/FALTA MATERIAL) |

---

## PERFORMANCE DO MOTOR (últimas execuções)

| Data | Status | Duração | MO Total | Colabs | Obras |
|------|--------|---------|----------|--------|-------|
| 2026-03-15 | OK | 41s | R$ 201 | 27 | 4 |
| 2026-03-14 | OK | 61s | R$ 2.212 | 40 | 4 |
| 2026-03-13 | OK | 51s | R$ 1.161 | 33 | 4 |
| 2026-03-12 | OK | 73s | R$ 2.273 | 49 | 4 |
| 2026-03-11 | OK | 64s | R$ 3.562 | 41 | 5 |

Duração média: **~58 segundos** por dia processado.

---

## ENDPOINTS DE ACESSO

| URL | Função |
|-----|--------|
| `/api/mao_obra/motor_mo_real_v1.php?data_ref=YYYY-MM-DD&gravar=1&limpar=1` | Executar motor |
| `/api/mao_obra/mo_diario_consolidado_v1.php?data_ref=YYYY-MM-DD` | Consolidado do dia |
| `/api/mao_obra/listar_mo_diaria_v2.php?data_ref=YYYY-MM-DD` | MO por colaborador |
| `/api/mao_obra/listar_mo_diaria_obra_equipe_v2.php?data_ref=YYYY-MM-DD&obra=X` | MO por obra/equipe |
| `/api/mao_obra/mo_ranking_margem_dia.php?data_ref=YYYY-MM-DD` | Ranking margem |
| `/api/mao_obra/mo_resultado_diario_obra.php?data_ref=YYYY-MM-DD` | Resultado por obra |
| `/api/mao_obra/mo_resumo_obras_dia.php?data_ref=YYYY-MM-DD` | Resumo obras |
| `/api/mao_obra/health_mo_diario.php?data_ref=YYYY-MM-DD` | Health check |
| `/api/mao_obra/mo_alertas_diarios_listar.php?data_ref=YYYY-MM-DD` | Alertas ponto |
| `/api/mao_obra/mo_auditoria_obra_dia.php?data_ref=YYYY-MM-DD&obra=X` | Auditoria obra |

### Reprocessamento via CLI

```bash
php -d max_execution_time=0 /tmp/reprocess_day.php YYYY-MM-DD
```

---

## REGRAS CRÍTICAS (auditadas no código)

### REGRA 1 — Centro de custo é a base
Cada obra = centro de custo distinto. Nunca agrupar obras do mesmo cliente.

### REGRA 2 — Transação atômica
Motor usa BEGIN/COMMIT/ROLLBACK. Se falhar no meio, faz rollback completo. Implementado em 2026-03-10.

### REGRA 3 — Gerencial desativado
`mo_gerencial` sempre = 0. Campos mantidos por compatibilidade.

### REGRA 4 — Tangerino-only
Secullum removido. Fonte única de ponto = `espelho_tangerino_punch`.

### REGRA 5 — Idempotência
Qualquer dia pode ser reprocessado N vezes → mesmo resultado.

---

## TIMEOUTS (configurados)

| Camada | Valor |
|--------|-------|
| PHP-FPM `max_execution_time` | **180s** |
| nginx `fastcgi_read_timeout` | **180s** |
| Motor `set_time_limit` | **180s** |
| CLI (reprocessamento) | **ilimitado** (`max_execution_time=0`) |

---

## CORREÇÕES VS FICHA CHATGPT

| Item | Ficha ChatGPT | Realidade VPS |
|------|---------------|---------------|
| Endpoints | 2 listados | **28 arquivos PHP** |
| fato_resultado_diario_obra | "ROADMAP - criar tabela" | **Já existe com 459 registros** |
| Log de cálculo | "GAP: não há tabela de histórico" | **`log_motor_mo` existe com 1.307 execuções** |
| Tabelas descritas | 3 tabelas genéricas | **7 tabelas auditadas com schema completo** |
| Gerencial | "custo_dia = custo_mensal / dias_úteis" | **Gerencial DESATIVADO (sempre 0), apenas MO oficial** |
| Fonte de ponto | "Tangerino / Ponto" (genérico) | **Tangerino-only via espelho_tangerino_punch (Secullum removido)** |
| Transação | Não mencionada | **BEGIN/COMMIT/ROLLBACK implementado (2026-03-10)** |
| Sync Tangerino | Não detalhado | **2 crons: 05:00 punch D-1, 05:30 employee matching** |
| Rateio por obra | "alocação OBRA/ADM/OFICINA" | **Rateio por peso via Tab_medicao_nova (funcionario1-15)** |
| Timeouts | Não mencionados | **PHP-FPM=180s, nginx=180s, configurados em 2026-03-10** |
| Versionamento cálculo | "GAP: não há versionamento" | **`assinatura` em log_motor_mo identifica versão do motor** |
| Health check | Não mencionado | **health_mo_diario.php existente e funcional** |

---

## RISCOS OPERACIONAIS (ATUALIZADOS)

| Risco | Severidade | Status |
|-------|-----------|--------|
| Timeout no motor | ~~Alta~~ | **RESOLVIDO** (180s FPM + nginx) |
| Corrupção por execução parcial | ~~Alta~~ | **RESOLVIDO** (transação atômica) |
| Colisão crc32 no colaborador_id | Média | PENDENTE (risco latente com >500 nomes) |
| Custo incorreto por erro de ponto | Média | MITIGADO (alertas + cobrança Donna) |
| Alocação errada | Média | INERENTE (depende da medição) |
| Falta de rateio de indiretos (ADM/OFICINA) | Baixa | PENDENTE |

---

## ROADMAP PENDENTE

| Item | Prioridade | Status |
|------|-----------|--------|
| Trocar crc32 por md5 truncado no colaborador_id | Média | Pendente |
| Rateio de indiretos (ADM/OFICINA) | Média | Pendente |
| Detalhamento de pendências de ponto na tela | Média | Pendente |
| Semáforo %MO com tendência (D-1) | Baixa | Pendente |
| Ranking por colaborador | Baixa | Pendente |
| Detecção de batida coletiva (antifraude) | Baixa | Pendente |
| Integração DRE | Futura | Pendente |

---

*Documento gerado por auditoria direta do código e banco de dados em produção (VPS2: 187.77.235.22)*

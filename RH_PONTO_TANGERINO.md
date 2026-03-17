# RH — PONTO ELETRÔNICO E PIPELINE TANGERINO

> Ficha técnica auditada — cruzamento código × banco em produção (VPS2)
> Última auditoria: 2026-03-17

---

## STATUS

| Campo | Valor |
|-------|-------|
| Status | **PRODUÇÃO ATIVA** |
| Fonte de ponto | Tangerino / Sólides API |
| Punches no espelho | **26.822** (88 employees) |
| Ponto diário processado | **1.830 registros** |
| Pipeline execuções | **967 logs** |
| Cache employees | **88** |
| Colaboradores ativos | **74** (68 com Tangerino + 6 exceções) |

---

## OBJETIVO

- Sincronizar batidas de ponto do Tangerino para espelho local
- Processar ponto diário (minutos trabalhados, status, alertas)
- Vincular employees do Tangerino a colaboradores do sistema
- Alimentar Motor MO e Donna com dados de presença
- Pipeline D-1 automatizado

---

## ARQUITETURA

```
Tangerino API (apis.tangerino.com.br/punch/)
        │
        ├──► sync_tangerino_punch.php (cron 05:00 D-1)
        │           ▼
        │    espelho_tangerino_punch (26.822)
        │
        ├──► sync_tangerino_employee_id.php (cron 05:30)
        │           ▼
        │    Tab_colaboradores.tangerino_employee_id
        │    cache_tangerino_employees (88)
        │
        ▼
pipeline_rh_d1.php → motor_ponto_diario_v1.php
        │
        ├──► fato_ponto_diario (1.830)
        ├──► log_pipeline_rh_d1 (967)
        │
        ▼
Motor MO (motor_mo_real_v1.php)
Donna (cobrança de ponto)
```

---

## ARQUIVOS EM PRODUÇÃO (21 endpoints)

### Sync Tangerino

| Arquivo | Função |
|---------|--------|
| `/api/tangerino/sync_tangerino_punch.php` | Sync punches (paginado, com upsert) |
| `/api/tangerino/sync_tangerino_punch_d1.php` | Wrapper D-1 (ontem) |
| `/api/rh/sync_tangerino_employee_id.php` | Match employee_id por nome/nro_registro |

### Pipeline RH

| Arquivo | Função |
|---------|--------|
| `pipeline_rh_d1.php` | Orquestrador pipeline D-1 |
| `motor_ponto_diario_v1.php` | Motor de processamento de ponto |
| `gravar_ponto_diario_v1.php` | Gravação do ponto processado |
| `helpers_punch_dia_v1.php` | Helpers de cálculo (batidas, minutos) |
| `helpers_punch_obra_v1.php` | Helpers de alocação por obra |
| `log_pipeline_rh_d1_begin.php` | Log início pipeline |
| `log_pipeline_rh_d1_end.php` | Log fim pipeline |

### Consultas e debug

| Arquivo | Função |
|---------|--------|
| `listar_ponto_diario.php` | Lista ponto do dia |
| `resumo_ponto_diario.php` | Resumo consolidado |
| `status_dia_fechado.php` | Verifica se dia está fechado |
| `reprocessar_dia_http.php` | Reprocessar 1 dia via HTTP |
| `reprocessar_range_http.php` | Reprocessar range via HTTP |
| `listar_employees_do_espelho_v1/v2.php` | Lista employees do espelho |
| `listar_employees_tangerino_v1.php` | Lista employees da API |
| `listar_funcionarios_tangerino_espelho_v1.php` | Funcionários com match |
| `mapear_colaboradores_tangerino_v1.php` | Mapeamento manual |
| `tangerino_descobrir_rotas_v1.php` | Descoberta de rotas API |
| `debug_espelho_tangerino_schema_v1.php` | Debug schema |

### Scripts shell

| Script | Cron | Função |
|--------|------|--------|
| `/usr/local/bin/donna_sync_tangerino_punch.sh` | 05:00 | Sync punches D-1 |
| `/usr/local/bin/donna_sync_tangerino_employee.sh` | 05:30 | Match employee_id |

---

## BASE DE DADOS

### espelho_tangerino_punch (26.822 registros)

Espelho local de todas as batidas. Upsert por `punch_id` (UNIQUE).

### fato_ponto_diario (1.830 registros)

```sql
id                    BIGINT(20) UNSIGNED  PK
data_ref              DATE                 INDEX
employee_id           BIGINT(20)           INDEX
employee_nome         VARCHAR(255)
minutos_trabalhados   INT(11)
horas_trabalhadas     DECIMAL(10,2)
status_dia            VARCHAR(20)          INDEX
punches_total         INT(11)
punches_validos       INT(11)
pendencias_json       LONGTEXT
alertas_json          LONGTEXT
created_at / updated_at  TIMESTAMP
```

### cache_tangerino_employees (88 registros)

Cache de employees únicos extraídos do espelho. Atualizado pelo sync_employee_id.

### log_pipeline_rh_d1 (967 execuções)

Histórico de execuções do pipeline D-1.

### log_sync_tangerino_employee (42 registros)

Log de vinculações employee_id ↔ colaborador.

---

## TOKEN E API

| Item | Valor |
|------|-------|
| Token | `/app/token_solides.php` (SOLIDES_API_TOKEN) |
| Base URL | `https://apis.tangerino.com.br/punch/` |
| Paginação | pageSize=200, maxPages=30 |
| Payload | JSON slim (campos essenciais, sem dados pesados) |

---

## REGRAS DE MATCH EMPLOYEE

1. **Nome normalizado** — remove acentos, conectivos (DA/DE/DO), uppercase
2. **External ID** — match por `nro_registro` = `employeeExternalId`
3. **Exceções** — `tangerino_employee_id = -1` (6 colaboradores não existem no Tangerino)

---

*Documento gerado por auditoria direta do código e banco de dados em produção*

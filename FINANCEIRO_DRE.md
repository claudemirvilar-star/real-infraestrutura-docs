# FINANCEIRO — DRE E GOVERNANÇA CONTÁBIL

> Ficha técnica auditada — cruzamento código × banco em produção (VPS2)
> Última auditoria: 2026-03-17

---

## STATUS

| Campo | Valor |
|-------|-------|
| Status | **PRODUÇÃO ATIVA** |
| Lançamentos DRE | **64.717** |
| Meses cobertos | **132** |
| Volume financeiro | **R$ 260.540.910,52** |
| Contas a pagar (espelho) | **26.869** |
| Contas a receber (espelho) | **2.080** |
| Classificações contábeis | **231** (123 pendentes revisão) |
| Centros de custo mapeados | **102** (10 pendentes) |
| Aliases CC | **2** |

---

## OBJETIVO

Consolidar a DRE gerencial da empresa a partir de dados financeiros (contas a pagar/receber do BTH), com:
- Materialização em tabela fato (`fato_dre_lancamento`)
- Classificação contábil governada (`dim_classif_contabil`)
- Mapeamento de centros de custo (`map_centro_custo_financeiro` + aliases)
- DRE executiva por empresa e por centro de custo
- Regime de competência e regime de caixa
- Detecção automática de novidades (classificações/CC novos)
- Auditoria de lançamentos tardios

---

## ARQUITETURA

```
BTH (ERP Financeiro)
        │
        ├──► sync_pagar (cron */15 min)
        ├──► sync_receber (cron */5 min)
        │
        ▼
espelho_contas_pagar (26.869 registros)
espelho_contas_receber (2.080 registros)
        │
        ├──► reconcile_excluido_flag (cron */10 min)
        │
        ▼
materializar_fato_dre.php (cron 04:00 diário)
        │
        ├──► dim_classif_contabil (231 classificações)
        ├──► map_centro_custo_financeiro (102 CCs)
        ├──► map_cc_aliases (2 aliases)
        │
        ▼
fato_dre_lancamento (64.717 registros)
        │
        ├──► dre_executiva_empresa.php (visão empresa)
        ├──► dre_executiva_cc.php (visão centro de custo)
        ├──► deteccao_novidades_camada1.php (cron 06:30)
        └──► auditoria_lancamentos_tardios.php
```

---

## ARQUIVOS EM PRODUÇÃO

| Arquivo | Path | Função |
|---------|------|--------|
| DRE Empresa | `/api/financeiro/dre_executiva_empresa.php` | DRE por empresa (competência/caixa) |
| DRE Centro Custo | `/api/financeiro/dre_executiva_cc.php` | DRE por CC (filtrável) |
| Materialização | `/api/financeiro/materializar_fato_dre.php` | TRUNCATE + REBUILD fato_dre |
| Detecção novidades | `/api/financeiro/deteccao_novidades_camada1.php` | Classificações/CCs novos |
| Auditoria tardios | `/api/financeiro/auditoria_lancamentos_tardios.php` | Títulos com pouca antecedência |

---

## ESPELHO BTH (Sync)

| Arquivo | Path | Função |
|---------|------|--------|
| Conexão BTH | `/api/espelho_bth/conexao_bth.php` | Conexão ao banco BTH |
| Receive pagar | `/api/espelho_bth/receive_pagar.php` | Recebe dados de contas a pagar |
| Receive receber | `/api/espelho_bth/receive_receber.php` | Recebe dados de contas a receber |
| Sync pagar HTTP | `/api/espelho_bth/sync_pagar_http.php` | **DESATIVADO** (bug V6) |
| Sync receber HTTP | `/api/espelho_bth/sync_receber_http.php` | Sync HTTP ativo |
| Scripts sync | `/opt/bth_sync/call_sync_pagar.sh` | Shell wrapper para cron |
| Scripts sync | `/opt/bth_sync/call_sync_receber.sh` | Shell wrapper para cron |
| Reconcile flags | `/opt/bth_sync/reconcile_excluido_flag.php` | Reconcilia exclusões |

---

## CRON JOBS

| Horário | Script | Função |
|---------|--------|--------|
| `*/5 * * * *` | `call_sync_receber.sh` | Sync contas a receber |
| `*/10 * * * *` | `reconcile_excluido_flag.php` | Reconcilia exclusões |
| `*/15 * * * *` | `call_sync_pagar.sh` | Sync contas a pagar |
| `0 0 * * *` | `call_sync_receber.sh historico` | Sync histórico receber |
| `10 0 * * *` | `call_sync_pagar.sh historico` | Sync histórico pagar |
| `0 4 * * *` | `cron_materializar_fato_dre.sh` | Materialização DRE |
| `30 6 * * *` | `donna_cron_deteccao_novidades.sh` | Detecção de novidades |

---

## BASE DE DADOS

### fato_dre_lancamento (64.717 registros, 28 campos)

Tabela fato materializada. Chave lógica: `(tabela_origem, id_planilha)` UNIQUE.

Campos-chave:
- `tabela_origem` — PAGAR ou RECEBER
- `id_estabelecimento`, `nome_empresa` — multi-empresa
- `data_competencia`, `ano_mes` — regime competência
- `data_caixa`, `ano_mes_caixa` — regime caixa
- `valor_original`, `sinal_dre`, `valor_dre` — valores com sinal
- `grupo_dre`, `subgrupo` — classificação DRE
- `centro_custo_erp`, `nome_cc_base`, `tipo_cc` — centro de custo
- `entra_dre`, `entra_custo_cliente`, `entra_custo_obra` — flags
- `status_mapeamento` — OK, SEM_CLASSIFICACAO, SEM_CC_MAPEADO, CC_VIA_ALIAS, CC_VAZIO, EXCLUIDO_ESPELHO

### dim_classif_contabil (231 classificações, 14 campos)

Dimensão de classificações contábeis. Grupos DRE:
RECEITA_SERVICOS, RECEITA_LOCACAO, RECEITA_VENDAS, DEDUCAO_IMPOSTO, CUSTO_MO_DIRETA, CUSTO_MATERIAL, CUSTO_EQUIPAMENTO, CUSTO_OPERACIONAL_OUTROS, DESPESA_ADMINISTRATIVA, DESPESA_COMERCIAL, DESPESA_FINANCEIRA, INVESTIMENTO, PATRIMONIAL, PESSOAL_SOCIO, TRANSFERENCIA_INTERNA, NAO_CLASSIFICADO

### map_centro_custo_financeiro (102 CCs, 14 campos)

Tipos: OBRA_CLIENTE, OPERACIONAL_INDIRETO, ADMINISTRATIVO, PATRIMONIAL, PESSOAL, FABRICACAO, NAO_CLASSIFICADO

Regras de rateio: DIRETO, PROPORCIONAL_RECEITA_CC, PROPORCIONAL_HEADCOUNT_CC, FIXO, EXCLUIR, PENDENTE

### espelho_contas_pagar (26.869 registros, 35 campos)

Espelho completo do BTH. Inclui soft delete (`excluido_flag`, `ts_delete`, `motivo_exclusao`), reativação e lixeira.

### espelho_contas_receber (2.080 registros, 33 campos)

Mesma estrutura do pagar, sem campos de reativação de lixeira.

---

## ENDPOINTS DRE

### DRE Executiva por Empresa
```
GET /api/financeiro/dre_executiva_empresa.php
  ?empresa=1146        (ou CSV: 1146,1,1147)
  &periodo_de=2026-01
  &periodo_ate=2026-03
  &regime=competencia   (ou caixa)
  &formato=json         (ou texto)
```

### DRE Executiva por Centro de Custo
```
GET /api/financeiro/dre_executiva_cc.php
  ?periodo_de=2026-01
  &periodo_ate=2026-03
  &cc=RODOANEL-SP       (opcional)
  &tipo_cc=OBRA_CLIENTE  (opcional)
```

---

## RISCOS

| Risco | Severidade | Status |
|-------|-----------|--------|
| 123 classificações pendentes revisão | Média | PENDENTE |
| 10 CCs pendentes revisão | Baixa | PENDENTE |
| Data anômala em espelho_pagar (0024-12-31) | Baixa | Dado legado |
| sync_pagar_http desativado (bug V6) | Info | Migrado para shell script |

---

*Documento gerado por auditoria direta do código e banco de dados em produção*

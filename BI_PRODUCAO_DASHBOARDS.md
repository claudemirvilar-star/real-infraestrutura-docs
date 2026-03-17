# BI PRODUÇÃO — DASHBOARDS E ANALYTICS

> Ficha técnica auditada — cruzamento código × banco em produção (VPS2)
> Última auditoria: 2026-03-17

---

## STATUS

| Campo | Valor |
|-------|-------|
| Status | **PRODUÇÃO ATIVA** |
| Endpoints BI | **22 endpoints** |
| Frontend | Flutter Web (App Produção) |
| Integrações | VExpenses (despesas campo), Tangerino (jornadas) |

---

## OBJETIVO

Fornecer dashboards e analytics de produção operacional:
- Produção diária por obra/encarregado
- Rankings de clientes e encarregados
- Análise de não-produtividade (motivos, séries, rankings)
- Custos indiretos por cliente
- Disponibilidade de equipamentos
- Eficiência por encarregado (valor produzido)
- Folgas consolidadas
- Horas trabalhadas por fonte

---

## ENDPOINTS (22 auditados)

### Produção

| Endpoint | Função |
|----------|--------|
| `api_bi_producao_diario_obra.php` | Produção diária por obra (Dashboard principal) |
| `api_bi_diario_obra_explodido.php` | Produção explodida (detalhamento) |
| `api_bi_producao_encarregado_cliente_mes.php` | Produção por encarregado × cliente × mês |

### Rankings

| Endpoint | Função |
|----------|--------|
| `api_bi_ranking_clientes.php` | Ranking de clientes por produção |
| `api_bi_ranking_encarregados.php` | Ranking de encarregados |

### Não-produtividade

| Endpoint | Função |
|----------|--------|
| `bi_nao_produtividade_dashboard.php` | Dashboard de não-produtividade |
| `bi_nao_produtividade_resumo.php` | Resumo consolidado |
| `bi_nao_produtividade_serie.php` | Série temporal |
| `bi_nao_produtividade_rank_encarregados.php` | Rank por encarregado |
| `bi_nao_produtividade_rank_motivos.php` | Rank por motivo |

### Quebra por cliente/equipe

| Endpoint | Função |
|----------|--------|
| `bi_quebra_cliente_resumo.php` | Resumo quebra por cliente |
| `bi_quebra_por_cliente_equipe.php` | Quebra cliente × equipe |
| `bi_quebra_por_motivo_equipe.php` | Quebra motivo × equipe |

### Encarregados

| Endpoint | Função |
|----------|--------|
| `bi_eficiencia_encarregado.php` | Eficiência por valor produzido |
| `bi_encarregado_resumo.php` | Resumo por encarregado |
| `bi_lista_encarregados.php` | Lista encarregados (filtro por período) |

### Clientes e custos

| Endpoint | Função |
|----------|--------|
| `bi_lista_clientes.php` | Lista clientes (filtro por período) |
| `bi_cliente_custos_indiretos.php` | Custos indiretos por cliente |
| `bi_cliente_disponibilidade_periodo.php` | Disponibilidade de equipamentos |

### Outros

| Endpoint | Função |
|----------|--------|
| `api_bi_horas_fonte.php` | Horas por fonte (Tangerino) |
| `api_bi_folgas_consolidado.php` | Folgas consolidadas |
| `api_mock_horas_fonte.php` | Mock para dev |

### Integrações externas

| Endpoint | Função |
|----------|--------|
| `vexpenses_sync.php` | Sync completo VExpenses |
| `sync_vexpenses_despesas_credito.php` | Sync despesas crédito |
| `sync_tangerino_jornadas.php` | Sync jornadas Tangerino |

---

## AUDITORIA DE PRODUÇÃO

| Endpoint | Path | Função |
|----------|------|--------|
| Produção vs Receber | `/api/auditoria/auditoria_producao_vs_receber_mes.php` | Cruzamento produção × receita |
| Detalhe receber | `/api/auditoria/auditoria_receber_detalhe_mes.php` | Detalhe de recebíveis |

---

## TABELAS BASE

Os endpoints BI consultam principalmente:
- `Tab_medicao_nova` — medições diárias de produção
- `Tab_clientes_nova` — centros de custo (obras)
- `Tab_colaboradores` — colaboradores
- `fato_mo_diaria_colab_obra` — MO por colaborador/obra (motor MO)
- `fato_resultado_diario_obra` — resultado diário (produção - MO)
- `espelho_tangerino_punch` — batidas de ponto
- `fato_ponto_diario` — ponto processado

---

*Documento gerado por auditoria direta do código e banco de dados em produção*

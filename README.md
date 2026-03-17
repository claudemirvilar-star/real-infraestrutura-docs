# REAL INFRAESTRUTURA — ECOSSISTEMA DIGITAL

> Documentação oficial auditada contra código em produção
> Última auditoria: 2026-03-17

---

## VISÃO GERAL

Sistema integrado de gestão operacional do Grupo Real Infraestrutura, cobrindo:

- **Produção** — App Flutter + APIs PHP (medição, alocação, colaboradores)
- **BI** — Motor de custo real (MO Diário), rankings, margem por obra
- **Financeiro** — Despesas operacionais, DRE executiva, espelho BTH (contas a pagar/receber)
- **Frota** — CEABS (bloqueio/desbloqueio veicular, governança patrimonial)
- **RH** — Ponto Tangerino, pipeline diário, sync employee
- **Automação** — Donna (bot WhatsApp + Telegram, cobrança de ponto, alertas)
- **Governança** — MCP Gateway, auditoria, roles, idempotência

---

## ARQUITETURA DE INFRAESTRUTURA

```
┌─────────────────────────────────────┐     ┌──────────────────────────────┐
│ VPS1 — Contabo (72.60.139.98)       │     │ VPS2 — Hostinger             │
│ Domínio: realdefensas.com           │     │ (187.77.235.22)              │
│                                     │     │ Domínio: globalsinalizacao   │
│ • OpenClaw Proxy (CEABS)            │     │          .online             │
│ • Check scripts (MO, health)        │     │                              │
│ • Claude Code CLI                   │     │ • App Flutter Web            │
│ • mem0 MCP (memória persistente)    │     │ • Backend PHP 8.3 + nginx    │
│ • PostgreSQL (mem0_db)              │     │ • Donna (WhatsApp + Telegram)│
│                                     │     │ • MCP Gateway                │
│                                     │     │ • APIs (BI, MO, RH, Frota)  │
│                                     │     │ • Crons (sync, cobrança)     │
│                                     │     │                              │
│        SSH key-based ──────────────►│     │ DB: MySQL remoto (Hostinger) │
│                                     │     │ srv1136.hstgr.io             │
└─────────────────────────────────────┘     └──────────────────────────────┘
```

---

## MÓDULOS DOCUMENTADOS

| Módulo | Ficha Técnica | Status |
|--------|--------------|--------|
| Donna — Cobrança de Ponto V2 | [DONNA_COBRANCA_PONTO_V2.md](DONNA_COBRANCA_PONTO_V2.md) | Produção ativa |
| CEABS — Bloqueio Veicular | [CEABS_BLOQUEIO_VEICULAR.md](CEABS_BLOQUEIO_VEICULAR.md) | Produção ativa |
| Despesas | [DESPESAS.md](DESPESAS.md) | Produção ativa |
| BI Produção — MO Diário | [BI_PRODUCAO_MO_DIARIO.md](BI_PRODUCAO_MO_DIARIO.md) | Produção ativa |

---

## MAPA DE APIs (VPS2)

### `/api/` — Endpoints de dados

| Diretório | Função | Endpoints |
|-----------|--------|-----------|
| `mao_obra/` | Motor MO, rankings, alertas, cobrança Donna | 28 |
| `bi_producao/` | BI produção, rankings, não-produtividade, VExpenses | 29 |
| `rh/` | Pipeline RH, ponto diário, sync Tangerino | 21 |
| `tangerino/` | Sync punches, listagem | 3 |
| `financeiro/` | DRE executiva, detecção novidades, materialização | 5 |
| `espelho_bth/` | Sync contas pagar/receber com BTH | 7 |
| `frota/` | Governança, normalização, autorização | ~5 |
| `auditoria/` | Auditoria geral | ~2 |
| `tesouraria/` | Tesouraria (em desenvolvimento) | ~2 |
| `debug/` | Diagnóstico | ~3 |

### `/app/` — Aplicação e serviços

| Diretório | Função |
|-----------|--------|
| `ceabs/` | Bloqueio/desbloqueio, verificação física, relatório diário |
| `despesas/` | CRUD despesas, upload fotos, aprovação, exportação |
| `whatsapp/` | Webhook, router, Donna handler, WhatsApp Cloud API |
| `telegram/` | Webhook Telegram, transcrição áudio Whisper |
| `mcp/` | MCP Gateway (dispatch, call, registry, auditoria) |
| `frota/` | Governança de frota, autorizações |
| `clientes/` | Gestão de clientes/centros de custo |
| `rh/` | Módulos RH complementares |
| `_secrets/` | Chaves e tokens (protegido) |

---

## AUTOMAÇÕES (Cron Jobs VPS2)

| Horário | Script | Função |
|---------|--------|--------|
| `* * * * *` | `verificar_bloqueio_pendente.php` | Verificação física CEABS (1/min) |
| `0 5 * * *` | `donna_sync_tangerino_punch.sh` | Sync Tangerino punches D-1 |
| `30 5 * * *` | `donna_sync_tangerino_employee.sh` | Match employee_id |
| `0 7,19 * * *` | `donna_health_rastreadores.sh` | Health rastreadores CEABS |
| `0 8 * * *` | `donna_cron_cobranca_v2.sh` | Cobrança ponto (1ª rodada) |
| `10 8 * * *` | `relatorio_diario_bloqueio.php` | Relatório CEABS D-1 |
| `0 12 * * *` | `donna_cron_cobranca_v2.sh` | Cobrança ponto (2ª rodada) |
| `0 15 * * *` | `donna_cron_cobranca_v2.sh` | Cobrança ponto (3ª rodada) |
| `0 18 * * *` | `donna_cron_cobranca_v2.sh` | Cobrança ponto (4ª rodada) |
| `*/5 * * * *` | `donna_auto_checkup_light.sh` | Health check geral |

---

## TECNOLOGIAS

| Camada | Tecnologia |
|--------|-----------|
| Backend | PHP 8.3 (PHP-FPM + nginx) |
| Banco de dados | MySQL 8 (Hostinger remoto: srv1136.hstgr.io) |
| Frontend | Flutter Web |
| Bot WhatsApp | WhatsApp Cloud API (Meta Graph v25.0) — Token permanente |
| Bot Telegram | Telegram Bot API (@openclaw_realdefensas_bot) |
| Transcrição áudio | OpenAI Whisper (whisper-1) |
| Proxy CEABS | OpenClaw (VPS1) |
| Ponto eletrônico | Tangerino / Sólides API |
| Memória IA | mem0 MCP + PostgreSQL + pgvector (VPS1) |
| CLI IA | Claude Code (Anthropic) |
| Financeiro | BTH (contas pagar/receber via sync HTTP) |
| Despesas campo | VExpenses (sync manual) |

---

## DONNA — ASSISTENTE OPERACIONAL

### Canais ativos
- **WhatsApp** — Canal principal (22 encarregados + 4 inscritos RH + 2 inscritos CEABS)
- **Telegram** — @openclaw_realdefensas_bot (adicionado 17/03/2026)

### Capacidades
- Bloqueio/desbloqueio veicular com confirmação
- Consulta de status e localização de frota
- Cobrança automática de ponto (4x/dia, tom escalado)
- Relatórios CEABS
- Gerenciamento de alertas (CEABS + RH)
- Transcrição de áudio (WhatsApp + Telegram → Whisper)

### Comandos
`ajuda` · `frota` · `frota real` · `frota bth` · `status <placa>` · `localizar <placa>` · `bloquear <placa>` · `desbloquear <placa>` · `relatorio ceabs` · `ativar alertas ceabs` · `desativar alertas ceabs` · `ativar alertas rh` · `desativar alertas rh`

---

## MCP GATEWAY

Protocolo padronizado de comunicação entre módulos.

| Item | Detalhe |
|------|---------|
| Endpoint | `POST /app/mcp/dispatch.php` → `call.php` |
| Formato | JSON: `{ tool, args, source, user_id, empresa_id, role }` |
| Roles | `USER < ADM < CFO < CEO` |
| Segurança | Idempotência, rate limit, HMAC (opcional), auditoria |
| Auditoria | `Tab_mcp_audit` + arquivo `mcp_audit.log` |

---

## NÚMEROS DO SISTEMA (auditados em 17/03/2026)

| Métrica | Valor |
|---------|-------|
| Colaboradores ativos | 74 (68 com ponto Tangerino + 6 exceções) |
| Veículos na frota | 39 (5 bloqueados) |
| Punches Tangerino | 26.822 |
| Registros MO processados | 5.374 |
| Execuções do motor MO | 1.307 |
| Despesas registradas | 234 |
| Fotos de comprovantes | 252 |
| Encarregados WhatsApp | 22 |
| Obras ativas | 6 |
| Dias com dados MO | 132 |

---

## PRINCÍPIOS DE ENGENHARIA

1. **Centro de custo é a unidade principal** — nunca agrupar obras do mesmo cliente
2. **Separação rigorosa por empresa** — `empresa_id` obrigatório
3. **Idempotência** — toda operação pode ser reexecutada sem duplicação
4. **Transação atômica** — DELETE + INSERT em bloco (BEGIN/COMMIT/ROLLBACK)
5. **Auditabilidade** — toda ação crítica é rastreável (quem, quando, o quê)
6. **Automação com supervisão** — Donna automatiza, humano decide
7. **Documentação viva** — fichas técnicas auditadas contra código real

---

## COMO USAR ESTE REPOSITÓRIO

### Para desenvolvedores
- Cada módulo tem ficha técnica com schema completo, endpoints e regras de negócio
- Seguir padrões do MCP Gateway para novos endpoints
- Respeitar separação por empresa e centro de custo
- Atualizar fichas técnicas ao alterar código

### Para IA (Claude Code)
- Ler fichas técnicas antes de alterar qualquer módulo
- Memória persistente disponível via mem0 MCP
- Permissão total de leitura/auditoria em ambas VPS
- Atualizar fichas após alterações (regra obrigatória)

### Para gestão
- Fichas técnicas servem como manual operacional
- Números auditados refletem estado real do sistema
- Roadmap pendente documentado em cada ficha

---

*Repositório mantido por auditoria contínua — fichas geradas por cruzamento direto com código e banco de dados em produção*

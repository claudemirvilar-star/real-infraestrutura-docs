# CEABS — BLOQUEIO VEICULAR

> Ficha técnica auditada — gerada por cruzamento entre documentação e código em produção (VPS2)
> Última auditoria: 2026-03-17

---

## STATUS

| Campo | Valor |
|-------|-------|
| Status | **PRODUÇÃO ATIVA** |
| Canais | WhatsApp + Telegram (ambos operacionais) |
| Veículos cadastrados | 39 |
| Veículos bloqueados | 5 (em 17/03/2026) |
| Autorizados | 5 (acesso global) |
| Inscritos alertas | 2 (Claudemir, Leandro Spot) |

---

## OBJETIVO

Controle operacional e patrimonial da frota por meio de:
- Bloqueio/desbloqueio remoto via WhatsApp e Telegram
- Verificação de efetivação física (ignição + velocidade)
- Governança de autorização por perfil (empresa × papel × veículo)
- Notificações automáticas de bloqueio/desbloqueio
- Relatório diário consolidado
- Rastreabilidade completa de ações críticas

---

## ARQUITETURA

```
Usuário autorizado
      │
      ▼
Donna (WhatsApp / Telegram)
      │
      ▼
inbound_router.php (comandos: bloquear, desbloquear, status, localizar)
      │
      ▼
MCP Gateway (call.php)
      │
      ├──► ceabs.veiculo.bloquear → veiculo_bloquear.php
      ├──► ceabs.veiculo.desbloquear → veiculo_desbloquear.php
      ├──► ceabs.veiculo.status → veiculo_status.php
      └──► ceabs.frota.status → frota_status_ceabs.php
               │
               ▼
         _ceabs_proxy_client.php
               │
               ▼
         OpenClaw Proxy (VPS1: realdefensas.com)
               │
               ▼
         API CEABS (rastreador)
               │
               ▼
    Tab_ceabs_verificacao_bloqueio (fila)
               │
               ▼
    cron 1/min: verificar_bloqueio_pendente.php
               │
               ├──► CONFIRMADO → notifica inscritos
               └──► TIMEOUT (15 tentativas) → alerta inscritos
```

> **CORREÇÃO vs ficha original:** A ficha indica "Donna via Telegram hoje, WhatsApp no roadmap". Na realidade, **WhatsApp é o canal principal em produção** e Telegram foi adicionado em 2026-03-17. Ambos operacionais.

---

## ARQUIVOS EM PRODUÇÃO

| Arquivo | Path completo | Função |
|---------|--------------|--------|
| Bloquear | `/app/ceabs/veiculo_bloquear.php` | Envia comando de bloqueio |
| Desbloquear | `/app/ceabs/veiculo_desbloquear.php` | Envia comando de desbloqueio |
| Status veículo | `/app/ceabs/veiculo_status.php` | Consulta status real via CEABS |
| Status frota | `/app/ceabs/frota_status_ceabs.php` | Status de toda a frota |
| Verificador cron | `/app/ceabs/verificar_bloqueio_pendente.php` | Confirmação física 1/min |
| Notificador | `/app/ceabs/notificar_bloqueio.php` | WhatsApp para inscritos |
| Relatório diário | `/app/ceabs/relatorio_diario_bloqueio.php` | Resumo D-1 às 08:10 |
| Proxy client | `/app/ceabs/_ceabs_proxy_client.php` | Cliente interno → OpenClaw |
| Criar pedido | `/app/ceabs/criar_pedido_ceabs.php` | Criação de pedido (MCP) |
| Confirmar pedido | `/app/ceabs/confirmar_pedido_ceabs.php` | Confirmação 2-step (MCP) |
| Listar permitidos | `/app/ceabs/listar_veiculos_permitidos.php` | Veículos por autorização |
| Governança | `/app/frota/governanca_validar.php` | Motor de permissões |
| OpenClaw confirm | `/openclaw/ceabs_confirm.php` | Proxy de confirmação |
| Chave proxy | `/app/_secrets/openclaw_key.php` | X-OpenClaw-Key |

---

## BASE DE DADOS

### Tab_frota (39 veículos)

```sql
Id                          INT(11)       PK
apelido                     VARCHAR(255)
placa                       VARCHAR(255)  INDEX
ano                         VARCHAR(255)
Status                      VARCHAR(255)
tipo_veiculo                VARCHAR(30)
vinculo_operacional         ENUM('REAL','LOCATARIA')
cliente_locacao_atual       VARCHAR(120)
responsavel_atual_nome      VARCHAR(120)
responsavel_atual_telefone  VARCHAR(20)
motorista_atual_nome        VARCHAR(120)
motorista_atual_telefone    VARCHAR(20)
encarregado_atual_nome      VARCHAR(120)
encarregado_atual_telefone  VARCHAR(20)
status_bloqueio             ENUM('LIVRE','BLOQUEADO_OPERACIONAL','BLOQUEADO_ADMIN_REAL') INDEX
bloqueado_por_empresa       ENUM('REAL','LOCATARIA')
bloqueado_por_nome          VARCHAR(120)
bloqueado_por_telefone      VARCHAR(20)
bloqueado_por_papel         ENUM('DIRETOR','GERENTE_ADM','GERENTE_FROTAS','SUPERVISOR','MOTORISTA','ENCARREGADO_FROTAS')
bloqueado_em                DATETIME
motivo_bloqueio             VARCHAR(255)
origem_bloqueio_atual       VARCHAR(30)
permite_app                 TINYINT(1)
permite_whatsapp            TINYINT(1)
```

### Tab_frota_autorizacoes (5 autorizados globais)

```sql
id                  INT(11)       PK
escopo              ENUM('GLOBAL','VEICULO') INDEX
id_frota            INT(11)       INDEX
nome_pessoa         VARCHAR(120)
telefone            VARCHAR(20)   INDEX
empresa             ENUM('REAL','LOCATARIA') INDEX
papel               ENUM('DIRETOR','GERENTE_ADM','GERENTE_FROTAS','SUPERVISOR','MOTORISTA','ENCARREGADO_FROTAS')
pode_consultar      TINYINT(1)
pode_localizar      TINYINT(1)
pode_bloquear       TINYINT(1)
pode_desbloquear    TINYINT(1)
ativo               TINYINT(1)
observacao          VARCHAR(255)
created_at          DATETIME
updated_at          DATETIME
```

### Tab_ceabs_verificacao_bloqueio (fila de verificação física)

```sql
id                    INT(11)       PK
id_frota              INT(11)
placa                 VARCHAR(20)   INDEX
apelido               VARCHAR(120)
acao                   ENUM('BLOQUEAR','DESBLOQUEAR')
solicitante_nome      VARCHAR(120)
solicitante_telefone  VARCHAR(20)
status_verificacao    ENUM('PENDENTE','CONFIRMADO','TIMEOUT','CANCELADO') INDEX
tentativas            INT(11)
max_tentativas        INT(11)
ultima_consulta       DATETIME
ignicao_ultima        VARCHAR(10)
velocidade_ultima     INT(11)
confirmado_em         DATETIME
tempo_confirmacao_seg INT(11)
latitude              DECIMAL(10,7)
longitude             DECIMAL(10,7)
municipio             VARCHAR(120)
uf                    VARCHAR(2)
logradouro            VARCHAR(255)
timeout_em            DATETIME
notificado            INT(11)
created_at            DATETIME
```

### Tab_alertas_bloqueio (inscritos em notificações)

```sql
id          INT(11)       PK
telefone    VARCHAR(20)   UNIQUE
nome        VARCHAR(120)
ativo       TINYINT(1)
created_at  DATETIME
updated_at  DATETIME
```

---

## GOVERNANÇA DE ACESSO

### Autorizados globais (auditado)

| Nome | Empresa | Papel | Bloquear | Desbloquear |
|------|---------|-------|----------|-------------|
| Claudemir | REAL | DIRETOR | ✅ | ✅ |
| Leandro Spot | REAL | GERENTE_ADM | ✅ | ✅ |
| Vinicius | REAL | GERENTE_FROTAS | ✅ | ✅ |
| Meira | REAL | SUPERVISOR | ✅ | ✅ |
| Willian | REAL | SUPERVISOR | ✅ | ✅ |

### Regras de governança (implementadas em `governanca_validar.php`)

1. **Escopo GLOBAL** → pode agir em qualquer veículo da frota
2. **Escopo VEICULO** → só veículos vinculados ao id_frota
3. **Regra de soberania patrimonial:** Se veículo bloqueado por empresa=REAL (status `BLOQUEADO_ADMIN_REAL`), locatária **perde autonomia** — só REAL pode desbloquear
4. **Permissões granulares:** `pode_consultar`, `pode_localizar`, `pode_bloquear`, `pode_desbloquear`

---

## CRON JOBS (auditados)

| Cron | Script | Frequência | Função |
|------|--------|-----------|--------|
| Verificação física | `verificar_bloqueio_pendente.php` | **1x/minuto** | Consulta CEABS, confirma efetivação |
| Relatório diário | `relatorio_diario_bloqueio.php` | **08:10 diário** | Resumo D-1 para inscritos |
| Health rastreadores | `donna_health_rastreadores.sh` | **07:00 e 19:00** | Verifica saúde dos rastreadores |

---

## FLUXO DE VERIFICAÇÃO FÍSICA

### Regras de confirmação (auditadas no código)

| Ação | Condição de confirmação |
|------|------------------------|
| **BLOQUEAR** | ignição = OFF **E** velocidade = 0 |
| **DESBLOQUEAR** | ignição = ON |

### Fluxo

1. Comando enviado → cria registro PENDENTE em `Tab_ceabs_verificacao_bloqueio`
2. Cron 1/min consulta status CEABS via proxy OpenClaw
3. A cada consulta: incrementa `tentativas`, grava `ignicao_ultima` e `velocidade_ultima`
4. Se condição de confirmação atendida → status=CONFIRMADO, grava localização e tempo
5. Se `tentativas >= max_tentativas` (15 = 15 min) → status=TIMEOUT, envia alerta
6. Se comando oposto antes da confirmação → status=CANCELADO

### Métricas reais observadas (últimas verificações)

| Placa | Ação | Status | Tempo confirmação |
|-------|------|--------|-------------------|
| UGA-2I98 | BLOQUEAR | CONFIRMADO | 2s |
| UFA-5A72 | BLOQUEAR | CONFIRMADO | 27s |
| UEX-6H46 | BLOQUEAR | CONFIRMADO | 29s |
| CDR-3D10 | BLOQUEAR | CONFIRMADO | 60s |
| QSU-1G90 | BLOQUEAR | CONFIRMADO | 49s |
| AZU-6A36 | DESBLOQUEAR | CONFIRMADO | 155s |

---

## NOTIFICAÇÕES

### Notificação de bloqueio/desbloqueio (`notificar_bloqueio.php`)

Enviada via WhatsApp para todos inscritos em `Tab_alertas_bloqueio` (ativos).

**Formato:**
```
🔒 *VEÍCULO BLOQUEADO*
📋 *Placa:* ABC-1234
🚗 *Apelido:* THOR 23
👤 *Responsável:* Claudemir
🕐 *Horário:* 17/03/2026 às 14:30 (Brasília)
📝 *Motivo:* Uso fora do horário
_Notificação automática — Sistema de Frotas_
```

### Relatório diário (08:10)

Enviado para inscritos em `Tab_alertas_bloqueio`. Consolida:
- Total de comandos do dia anterior
- Breakdown por ação
- Tempo médio de efetivação
- Inscritos atuais: **2** (Claudemir, Leandro Spot)

---

## COMANDOS DONNA (WhatsApp + Telegram)

| Comando | Ação |
|---------|------|
| `bloquear <placa ou apelido>` | Solicita bloqueio com confirmação (SIM) |
| `desbloquear <placa ou apelido>` | Solicita desbloqueio com confirmação (SIM) |
| `status <placa ou apelido>` | Consulta status, localização, motorista |
| `localizar <placa ou apelido>` | Mesmo que status |
| `frota` | Lista toda a frota com status |
| `frota real` | Apenas veículos REAL |
| `frota bth` | Apenas veículos locados |
| `relatorio ceabs` | Relatório de bloqueios atual |
| `ativar alertas ceabs` | Inscreve em notificações |
| `desativar alertas ceabs` | Remove inscrição |

### Fluxo de bloqueio via Donna

1. Usuário: `bloquear THOR 23`
2. Donna: "Confirma BLOQUEIO? 🚛 THOR 23 (ABC-1234) — Responda SIM"
3. Usuário: `SIM`
4. Donna: "✅ Bloqueio executado! 🔒 Veículo BLOQUEADO"
5. Cron verifica efetivação física
6. Inscritos recebem notificação automática

---

## INTEGRAÇÃO COM OPENCLAW

- **Proxy:** `/app/ceabs/_ceabs_proxy_client.php` → VPS1 (realdefensas.com)
- **Chave:** `/app/_secrets/openclaw_key.php` (X-OpenClaw-Key)
- **Ações mapeadas:** `block`, `unblock`, `status`
- **Timeout:** 50s por request

---

## LOGS E RASTREABILIDADE

| Log | Path | Conteúdo |
|-----|------|----------|
| Verificação física | `/var/log/ceabs_verificacao_bloqueio.log` | Saída do cron 1/min |
| Relatório diário | `/var/log/ceabs_relatorio_diario.log` | Envio do relatório D-1 |
| Health rastreadores | `/var/log/donna_health_rastreadores.log` | Saúde dos rastreadores |
| Auditoria MCP | `/app/mcp/runtime/mcp_audit.log` | Auditoria de chamadas MCP |
| Banco | `Tab_ceabs_verificacao_bloqueio` | Histórico completo de verificações |

---

## CORREÇÕES VS FICHA CHATGPT

| Item | Ficha ChatGPT | Realidade VPS |
|------|---------------|---------------|
| Canal operacional | "Telegram hoje, WhatsApp no roadmap" | **WhatsApp é o canal principal + Telegram adicionado em 17/03/2026** |
| Donna funcional | "Donna funcional no Telegram para operação de frota" | **Donna funcional em WhatsApp (principal) E Telegram (desde 17/03)** |
| Tabelas descritas | Campos genéricos "conhecidos/relevantes" | **Schema completo auditado com tipos e índices** |
| Autorizados | "5 autorizados" (sem nomes/papéis) | **5 autorizados auditados: Claudemir (DIRETOR), Leandro (GERENTE_ADM), Vinicius (GERENTE_FROTAS), Meira e Willian (SUPERVISOR)** |
| Verificação física | "1x por minuto" | **Confirmado: 1x/min, 15 tentativas max, timeout 15min** |
| Arquivos | Apenas 2 listados | **11 arquivos PHP + 1 proxy OpenClaw identificados** |
| Bloqueio confirmado | "ignição OFF, velocidade 0" | **Confirmado idêntico no código** |
| Desbloqueio confirmado | "ignição ON" | **Confirmado idêntico no código** |
| Relatório diário | "08:10" | **Confirmado: 08:10 diário** |
| Migração para WhatsApp | "roadmap/futuro" | **WhatsApp já é o canal principal desde a implantação** |

---

## RISCOS OPERACIONAIS (ATUALIZADOS)

| Risco | Severidade | Status |
|-------|-----------|--------|
| Comando sem efetivação física | Média | **MITIGADO** (verificação 1/min + alerta timeout) |
| Atraso comunicação CEABS | Média | **MITIGADO** (timeout 15min + histórico) |
| Governança mal aplicada | Alta | **MITIGADO** (regra soberania patrimonial + perfis) |
| Alertas excessivos | Baixa | **MITIGADO** (cancelamento por comando oposto) |
| Warning REQUEST_METHOD no cron | Baixa | PENDENTE (funcional mas gera warning no log) |

---

## ROADMAP PENDENTE

| Item | Prioridade | Status |
|------|-----------|--------|
| Dashboard executivo de bloqueios | Alta | Pendente |
| Histórico filtrável por veículo/período | Alta | Pendente |
| Corrigir warning REQUEST_METHOD no cron | Baixa | Pendente |
| Alertas de uso indevido (antifraude GPS) | Futura | Pendente |
| Cruzamento local batidas × frota | Futura | Pendente |

---

*Documento gerado por auditoria direta do código e banco de dados em produção (VPS2: 187.77.235.22)*

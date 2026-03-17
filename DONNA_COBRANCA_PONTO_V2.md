# DONNA — COBRANÇA DE PONTO V2

> Ficha técnica auditada — gerada por cruzamento entre documentação e código em produção (VPS2)
> Última auditoria: 2026-03-17

---

## STATUS

| Campo | Valor |
|-------|-------|
| Status | **PRODUÇÃO ATIVA** |
| Modo | `real` (envia WhatsApp) |
| Em produção desde | 2026-03-13 |
| Modo simulação | 2026-03-10 a 2026-03-12 |

---

## OBJETIVO

Automatizar a cobrança de ajuste de ponto dos colaboradores com base no cruzamento entre:

- **Batidas de ponto** (MO Diário — `fato_mo_diaria_colab_obra`)
- **Produção realizada** (Medição — `Tab_medicao_nova`)

Objetivos operacionais:
- Reduzir cobranças indevidas (dispensa automática por motivo operacional)
- Aumentar disciplina operacional
- Automatizar comunicação com encarregados via WhatsApp
- Gerar visibilidade para o RH (resumo consolidado)

---

## ARQUITETURA

```
fato_mo_diaria_colab_obra ──┐
                            ├──► donna_cobranca_ponto_v2.php
Tab_medicao_nova ───────────┘         │
                                      ├──► Classificação: COBRAR / DISPENSAR
                                      │
                                      ├──► WhatsApp (encarregados) — tom escalado
                                      │
                                      └──► Resumo RH (Ana + Gabrielli + inscritos)
```

---

## ARQUIVOS EM PRODUÇÃO

| Arquivo | Path | Função |
|---------|------|--------|
| Motor principal | `/api/mao_obra/donna_cobranca_ponto_v2.php` | Cruzamento, classificação, envio |
| Script cron | `/usr/local/bin/donna_cron_cobranca_v2.sh` | Wrapper CLI com data D-1 |
| Mensagens | `/api/mao_obra/donna_mensagens_encarregado.php` | Templates de mensagem |
| Executor | `/api/mao_obra/donna_executor_cobranca.php` | Lógica de execução |
| Pendências | `/api/mao_obra/pendencias_por_encarregado_v2.php` | Lista pendências por encarregado |
| WhatsApp send | `/app/whatsapp/whatsapp_send.php` | Helper de envio via Cloud API |
| Log arquivo | `/var/log/donna_cobranca_v2.log` | Log de execução do cron |

---

## BASE DE DADOS

### Tabelas de entrada

| Tabela | Função | Campos-chave |
|--------|--------|-------------|
| `fato_mo_diaria_colab_obra` | Presença e alocação | data_ref, colaborador_id, nome_colaborador, obra, centro_custo, horas_trabalhadas, status_ponto |
| `Tab_medicao_nova` | Produção diária | data (1_Id), obra (2_cliente), is_nao_produtivo, motivo_nao_produtivo, 71-85_funcionario1-15 |

### Tabelas de suporte

| Tabela | Função | Campos |
|--------|--------|--------|
| `Tab_encarregados_whatsapp` | Roteamento de cobrança | id, login, telefone, telefone_normalizado, ativo |
| `Tab_alertas_rh` | Inscritos no resumo RH | telefone, nome, ativo |
| `log_donna_cobranca` | Histórico de envios | id, data_ref, rodada, encarregado, telefone, modo, status, mensagem_hash, mensagem_pronta, erro_detalhe, created_at |

### Contadores atuais (auditados)

| Dado | Valor |
|------|-------|
| Encarregados em `Tab_encarregados_whatsapp` | **22 ativos** |
| Inscritos em `Tab_alertas_rh` | **4** (Ana, Gabrielli, Claudemir, Leandro Spot) |
| Registros em `log_donna_cobranca` | Gravação ativa desde 2026-03-10 |

---

## CRON / AUTOMAÇÃO

### Horários (auditados no crontab)

| Rodada | Horário real | Tom |
|--------|-------------|-----|
| 1ª | **08:00** | 📋 Lembrete |
| 2ª | **12:00** | ⚠️ Reforço |
| 3ª | **15:00** | 🔴 Cobrança |
| 4ª | **18:00** | 🚨 Última chamada |

> **CORREÇÃO vs ficha original:** A ficha do ChatGPT indica 07/11/15/19h. Os horários reais no crontab são **08/12/15/18h**.

**Frequência:** 7 dias por semana
**Estratégia:** D-1 (cobra o dia anterior)
**Modo:** `real` (desde 2026-03-13)

### Script cron

```bash
# /usr/local/bin/donna_cron_cobranca_v2.sh
DATA_REF=$(date -d yesterday +%Y-%m-%d)
MODO="real"
php -d max_execution_time=120 donna_cobranca_ponto_v2.php "$DATA_REF" "$MODO"
```

---

## LÓGICA DE PROCESSAMENTO

### ETAPA 1 — Buscar pendências

Inclui `pendencias_por_encarregado_v2.php` para obter colaboradores com `status_ponto = sem_batida`, agrupados por encarregado/obra.

### ETAPA 2 — Buscar medições

Query em `Tab_medicao_nova` para o mesmo dia, extraindo:
- `is_nao_produtivo` e `motivo_nao_produtivo`
- Lista de funcionários (campos 71-85_funcionario1-15)

### ETAPA 3 — Classificação

#### DISPENSAR (automático)

Se `motivo_nao_produtivo` contém:

| Motivo | Ação |
|--------|------|
| `CHUVA` | Dispensa |
| `FOLGA_TRECHO` | Dispensa |
| `FALTA MATERIAL` | Dispensa |
| `MANUTENCAO` | Dispensa |

#### COBRAR

Se nenhum motivo de dispensa se aplica:
- Colaborador tem pendência de ponto + medição existe → **COBRAR**

#### CASOS NÃO TRATADOS (risco de falso positivo)

- EQUIPE APOIO
- TRANSPORTE MATERIAL
- VIAGEM
- RESTRICAO
- INTEGRACAO

> Esses casos ainda geram cobrança — pendente implementar tratamento específico.

### ETAPA 4 — Deduplicação

Usa `mensagem_hash` (SHA-256 do conteúdo) para evitar envio duplicado na mesma rodada. Status `duplicado` gravado em `log_donna_cobranca`.

### ETAPA 5 — Envio WhatsApp

Via `whatsapp_send_text()` usando WhatsApp Cloud API (Meta Graph v25.0).

- **Phone Number ID:** `1005476022651238`
- **Token:** Permanente (System User — nunca expira). Atualizado 2026-03-14.

> **CORREÇÃO vs ficha original:** A ficha indica "Token WhatsApp expira ~ago/2026". Na realidade, o token foi migrado para System User permanente em 2026-03-14, **não expira**.

---

## TOM ESCALADO POR RODADA

| Rodada | Emoji | Assinatura | Comportamento |
|--------|-------|------------|---------------|
| 08:00 | 📋 | *Donna* | Lembrete gentil |
| 12:00 | ⚠️ | *Donna — 2º aviso* | Reforço |
| 15:00 | 🔴 | *Donna — Cobrança* | Urgência: "regularizar HOJE" |
| 18:00 | 🚨 | *Donna — ÚLTIMA CHAMADA* | Aviso final: "impacta fechamento MO" |

---

## SAÍDAS DO SISTEMA

### 1. Mensagem WhatsApp (Encarregados)

Enviada individualmente para cada encarregado que tem pendências:
- Lista de colaboradores pendentes
- Obra
- Data de referência (formatada com dia da semana)
- Tom variável por rodada

### 2. Resumo RH

Destinatários fixos no código:
- **Ana** → 5514998486848
- **Gabrielli** → 5514991185676

Destinatários dinâmicos via `Tab_alertas_rh`:
- Claudemir, Leandro Spot (e quem se inscrever via Donna: `ativar alertas rh`)

Conteúdo:
- Total de cobranças enviadas
- Total de dispensas
- Breakdown por motivo de dispensa (com obra e quantidade)

---

## LOGS E RASTREABILIDADE

### Log em arquivo
- **Path:** `/var/log/donna_cobranca_v2.log`
- **Conteúdo:** timestamp, data_ref, modo, JSON com totais, envios e dispensados

### Log em banco de dados
- **Tabela:** `log_donna_cobranca`
- **Schema:** id, data_ref, rodada, encarregado, telefone, modo (simulacao/real), status (simulado/enviado/erro/duplicado), mensagem_hash, mensagem_pronta, erro_detalhe, created_at

> **CORREÇÃO vs ficha original:** A ficha indica "GAP CRÍTICO: não grava em tabela". Na realidade, a tabela `log_donna_cobranca` **existe e está ativa** desde 2026-03-10. O gap foi resolvido.

---

## ENCARREGADOS ATIVOS (22)

| Login | Telefone |
|-------|----------|
| Adriano | 5514997175722 |
| Alcimar | 5514996127940 |
| Ana | 5514998486848 |
| Augustinho | 556993194654 |
| Claudemir | 5514996109252 |
| Davi | 5514996619542 |
| Fernanda | 5514996237730 |
| Gabrielli | 5514991185676 |
| Gilberto | 5514991661864 |
| Joao | 5514996115096 |
| Jorge | 5581981654249 |
| Leonardo | 5514998126425 |
| Lucas | 5514997959176 |
| Lucena | 5514996545546 |
| Meira | 5534997143430 |
| rosivaldo | 5514998423558 |
| Sergio | 5514997364386 |
| Soares | 5514998082429 |
| Spoti | 5514997366868 |
| Vinicius | 5514999227103 |
| Willian | 5514998143007 |
| Yan | 5514996259881 |

---

## SCHEMA: Tab_encarregados_whatsapp

```sql
id              INT(11)       PK AUTO_INCREMENT
login           VARCHAR(100)  -- nome do encarregado (case insensitive para match)
telefone        VARCHAR(20)   -- formato E.164 (5514...)
telefone_normalizado VARCHAR(20)
ativo           TINYINT(4)    -- 1=ativo
data_criacao    TIMESTAMP
```

> **CORREÇÃO vs ficha original:** A ficha indica campo `nome` na tabela. O campo real é `login`. Não existe campo `nome` em `Tab_encarregados_whatsapp`.

---

## CORREÇÕES VS FICHA CHATGPT

| Item | Ficha ChatGPT | Realidade VPS |
|------|---------------|---------------|
| Horários cron | 07:00, 11:00, 15:00, 19:00 | **08:00, 12:00, 15:00, 18:00** |
| Token WhatsApp | "expira ~ago/2026" | **Permanente (System User), nunca expira** |
| Log em tabela | "GAP CRÍTICO: não grava" | **`log_donna_cobranca` existe e está ativa** |
| Campo `nome` em Tab_encarregados | Listado como campo | **Não existe. O campo é `login`** |
| Coluna `nome` na tabela | "login, telefone, nome" | **login, telefone, telefone_normalizado, ativo, data_criacao** |
| Roadmap "donna_cobranca_log" | "Criar tabela" | **Já criada: `log_donna_cobranca`** |
| Roadmap "donna_falta_colaborador" | Sugerido | **Não implementado** |

---

## RISCOS OPERACIONAIS (ATUALIZADOS)

| Risco | Severidade | Status |
|-------|-----------|--------|
| Falsos positivos em equipes de apoio/viagem | Média | PENDENTE |
| Dependência da qualidade da medição | Média | INERENTE |
| Token WhatsApp expira | ~~Alta~~ | **RESOLVIDO** (token permanente) |
| Falta de UNIQUE em Tab_encarregados_whatsapp | Baixa | PENDENTE |
| Logs não estruturados | ~~Alta~~ | **RESOLVIDO** (log_donna_cobranca em banco) |

---

## ROADMAP PENDENTE

| Item | Prioridade | Status |
|------|-----------|--------|
| Tratar motivos especiais (APOIO, VIAGEM, etc.) | Alta | Pendente |
| Confirmação de ajuste via WhatsApp | Média | Pendente |
| Tabela `donna_falta_colaborador` | Média | Pendente |
| Ranking de encarregados por pendências | Baixa | Pendente |
| Dashboard Donna no BI | Baixa | Pendente |
| UNIQUE em Tab_encarregados_whatsapp (login) | Baixa | Pendente |

---

## COMANDOS DONNA (via WhatsApp/Telegram)

| Comando | Ação |
|---------|------|
| `ativar alertas rh` | Inscreve telefone em `Tab_alertas_rh` |
| `desativar alertas rh` | Remove inscrição |

---

*Documento gerado por auditoria direta do código e banco de dados em produção (VPS2: 187.77.235.22)*

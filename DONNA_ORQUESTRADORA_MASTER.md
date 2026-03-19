# DONNA — PLANO DE EVOLUÇÃO PARA ORQUESTRADORA CENTRAL

## 📌 STATUS
Planejamento estratégico aprovado
Fase: transição de assistente → sistema orquestrador

---

# 🎯 OBJETIVO

Transformar a Donna de:

→ interface conversacional

Para:

→ sistema central de orquestração operacional, técnica e executiva

---

# 🧠 VISÃO DO SISTEMA

Donna passa a atuar como:

- interface única do sistema
- orquestradora de módulos
- geradora de ações
- supervisora de operação
- ponte entre humano, BI e engenharia

---

# 🏗️ ARQUITETURA CONCEITUAL

```text
Usuário
   ↓
Donna (Interface + Decisão)
   ↓
Router de Intenção
   ├── Consulta (BI / APIs)
   ├── Execução (scripts / integrações)
   ├── Engenharia (Claude)
   ├── Governança (logs / docs)
   └── Alertas (WhatsApp / Telegram)
```

---

# 🧩 CAMADAS DA DONNA

## 1. INTERFACE

**Canais:**
- WhatsApp (produção)
- Telegram (admin)
- futuro: Web/App

**Função:**
- receber comandos
- padronizar linguagem
- manter contexto

## 2. MOTOR DE INTENÇÃO

Classifica o comando em:
- consulta
- ação operacional
- auditoria
- engenharia
- governança
- alerta

## 3. MOTOR DE CONTEXTO

Consulta:
- SOUL.md
- documentação dos módulos
- changelog
- estado atual do sistema
- histórico recente
- parâmetros operacionais

## 4. MOTOR DE DECISÃO

Define:
- se executa direto
- se consulta dados
- se gera prompt para Claude
- se exige aprovação humana
- se gera alerta

## 5. MOTOR EXECUTOR

Executa:
- endpoints PHP
- scripts cron
- integrações (CEABS, WhatsApp, etc.)
- geração de prompts
- envio de mensagens

## 6. MEMÓRIA OPERACIONAL

Registra:
- comando recebido
- ação executada
- resultado
- falhas
- decisões tomadas

---

# 🗄️ ESTRUTURA DE DADOS (NOVA)

## Tabela: donna_jobs

```sql
CREATE TABLE donna_jobs (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  tipo_job VARCHAR(50),
  modulo VARCHAR(50),
  comando_origem TEXT,
  status VARCHAR(20),
  payload_entrada JSON,
  payload_saida JSON,
  criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  concluido_em TIMESTAMP NULL,
  aprovado_por VARCHAR(100)
);
```

## Tabela: donna_eventos

```sql
CREATE TABLE donna_eventos (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  job_id BIGINT,
  etapa VARCHAR(50),
  nivel VARCHAR(20),
  mensagem TEXT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
```

---

# 🤖 INTEGRAÇÃO COM CLAUDE

**Papel do Claude:**
- execução técnica
- criação/edição de código
- alterações estruturais

**Papel da Donna:**
- detectar necessidade técnica
- gerar prompt estruturado
- validar resposta
- orientar implementação

**Fluxo:**
```text
Donna detecta problema
        ↓
Donna gera prompt técnico
        ↓
Claude executa
        ↓
Donna valida
        ↓
Donna registra e reporta
```

---

# ⚙️ CATÁLOGO DE COMANDOS (V1)

### CONSULTA
- "resumo do dia"
- "riscos do ponto"
- "status do CEABS"
- "status despesas"
- "ranking auditoria"

### GOVERNANÇA
- "docs desatualizadas?"
- "o que mudou hoje?"
- "verificar integridade do sistema"

### ENGENHARIA
- "gerar prompt para corrigir X"
- "avaliar módulo Y"
- "identificar gaps"

### EXECUÇÃO
- "rodar auditoria"
- "executar validação"
- "enviar relatório"

---

# 🔐 POLÍTICA DE AUTONOMIA

**Donna pode executar sem aprovação:**
- consultas
- relatórios
- diagnósticos
- geração de prompts
- leitura de logs
- análise de dados

**Donna deve pedir aprovação:**
- alterações em produção
- mudanças no banco
- bloqueios/desbloqueios
- envio de mensagens sensíveis
- ações com impacto financeiro

---

# 📊 FORMATO PADRÃO DE RESPOSTA

Todas as respostas devem seguir:

1. **STATUS**
2. **NÚMEROS**
3. **DIAGNÓSTICO**
4. **AÇÃO**

---

# 🔄 FLUXO OPERACIONAL PADRÃO

```text
Comando recebido
        ↓
Classificação de intenção
        ↓
Consulta de contexto
        ↓
Decisão
        ↓
Execução
        ↓
Registro
        ↓
Resposta executiva
```

---

# 🚀 ROADMAP DE IMPLEMENTAÇÃO

## FASE 1 — DONNA CONSULTIVA
- leitura de BI
- leitura de logs
- geração de resumos
- comandos básicos

## FASE 2 — DONNA ORQUESTRADORA
- geração de prompts Claude
- execução de fluxos
- controle de jobs
- trilha operacional

## FASE 3 — DONNA SUPERVISORA
- monitoramento de sistema
- validação de documentação
- detecção de falhas
- análise de divergências

## FASE 4 — DONNA SEMI-AUTÔNOMA
- execução com regras
- aprovação por nível
- ações automáticas controladas
- priorização inteligente

---

# 📊 CASOS DE USO

## 1. Auditoria de Ponto

**Comando:**
> "Donna, me traga o resumo crítico do ponto"

**Ação:**
- consulta ranking
- identifica riscos
- responde executivo

## 2. Engenharia

**Comando:**
> "Donna, esse alerta está errado"

**Ação:**
- analisa dados
- gera prompt para Claude
- sugere correção

## 3. Governança

**Comando:**
> "Donna, docs estão atualizadas?"

**Ação:**
- compara código vs documentação
- identifica divergência
- gera ação

---

# 🧠 PRINCÍPIOS DA DONNA

1. Nunca agir sem rastreabilidade
2. Nunca executar ação crítica sem regra
3. Sempre priorizar clareza executiva
4. Sempre registrar decisões
5. Sempre separar consulta de ação

---

# 🏁 RESULTADO ESPERADO

Donna deixa de ser assistente e passa a ser:

- **sistema de comando**
- **sistema de decisão**
- **sistema de supervisão**
- **sistema de coordenação de IA**

---

# 🧠 FRASE CENTRAL

> **"Donna não responde — Donna coordena."**

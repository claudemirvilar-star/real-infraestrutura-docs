# AUDITORIA DE PONTO — MOTOR + RELATÓRIOS

> Ficha técnica auditada — gerada por cruzamento entre documentação e código em produção (VPS2)
> Última auditoria: 2026-03-18

---

## STATUS

| Campo | Valor |
|-------|-------|
| Status | **PRODUÇÃO ATIVA** |
| Em produção desde | 2026-03-18 |
| Motor | v1.4 |
| Relatório diário | WhatsApp 07:30 |
| Relatório semanal | WhatsApp 14:00 |
| Re-sync Tangerino | 01:00 diário |

---

## OBJETIVO

Auditar diariamente as batidas de ponto de todos os colaboradores ativos, cruzando três fontes de dados:

- **Batidas de ponto** — `espelho_tangerino_punch` + `fato_ponto_diario`
- **Produção realizada** — `Tab_medicao_nova`
- **Cadastro de colaboradores** — `Tab_colaboradores` + `cache_tangerino_employees`

Objetivos operacionais:
- Identificar colaboradores com batidas irregulares (faltantes, ímpares, incompletas)
- Detectar lançamentos de produção incorretos (dispensado mas bateu ponto)
- Sinalizar falta de apontamento na medição (bateu ponto mas não foi lançado)
- Classificar automaticamente folgas, dispensas e exceções por setor/função
- Enviar relatórios diários e semanais via WhatsApp

---

## ARQUITETURA

```
espelho_tangerino_punch ──┐
                          │
fato_ponto_diario ────────┼──► motor_auditoria_ponto_v1.php
                          │         │
Tab_medicao_nova ─────────┤         ├──► Classificação automática (9 categorias)
                          │         │
Tab_colaboradores ────────┘         ├──► relatorio_auditoria_whatsapp.php (diário)
cache_tangerino_employees           │
                                    └──► relatorio_auditoria_semanal_v2.php (semanal)
                                              │
                                              ├──► WhatsApp: Claudemir, Ana, Gabrielli,
                                              │              Leandro, Lidyane
                                              └──► WhatsApp OBRAS: correções pendentes
```

---

## REGRAS DE CLASSIFICAÇÃO

### Regra geral: 4 batidas obrigatórias

Cada registro no Tangerino (`espelho_tangerino_punch`) = 1 entrada + 1 saída = **2 batidas reais**.
O motor conta batidas individuais: `punches_total=2` no banco = **4 batidas** (E1, S1, E2, S2).

### Classificações

| Código | Gravidade | Descrição |
|--------|-----------|-----------|
| `OK` | OK | Batidas corretas conforme regra |
| `OK_DISPENSADO` | OK | Dispensado por motivo válido (CHUVA, FOLGA_TRECHO, FALTA MATERIAL, MANUTENCAO) |
| `FOLGA` | OK | Sem medição e sem batida — dia de descanso |
| `IRREGULAR_SEM_BATIDA` | CRÍTICO | Tem medição mas zero batidas |
| `IRREGULAR_2_BATIDAS` | ALERTA | Tem medição mas apenas 2 batidas (esperado 4) |
| `IRREGULAR_BATIDAS_IMPARES` | ALERTA | Batidas ímpares (1 ou 3) — faltou entrada ou saída |
| `CORRIGIR_LANCAMENTO` | ALERTA | Lançado como dispensado mas bateu ponto — corrigir produção |
| `FALTA_APONTAMENTO_PRODUCAO` | ALERTA | Bateu ponto mas não foi lançado em nenhuma medição |
| `VERIFICAR_EXCESSO` | INFO | Mais de 4 batidas — verificar |

### Regras por setor/função

| Setor/Função | Regra de ponto | Medição obrigatória? |
|-------------|----------------|---------------------|
| **OBRA** | 4 batidas em dia com medição | Sim |
| **ADM** | 4 batidas seg-sex | Não |
| **OFICINA** | 4 batidas seg-sex | Não |
| **SUPERVISOR** | 4 batidas seg-sex (independe alocação) | Não |

### Motivos não-produtivos

| Motivo | Ação |
|--------|------|
| CHUVA | Dispensar ponto |
| FOLGA_TRECHO | Dispensar ponto |
| FALTA MATERIAL | Dispensar ponto |
| MANUTENCAO | Dispensar ponto |
| VIAGEM | Exigir ponto |
| INTEGRACAO | Exigir ponto |
| EQUIPE APOIO | Exigir ponto |
| TRANSP. MATERIAL | Exigir ponto |
| RESTRICAO | Exigir ponto |

### Exceções (ignorados)

| Colaborador | Motivo |
|-------------|--------|
| YAN MATHEUS FERREIRA VILAR | Dono da empresa |
| CLAUDEMIR MIRANDA VILAR | Diretor |
| JOSE RENATO LUCHINI | ADM sem Tangerino |

### Normalização de nomes

Matching entre Tangerino e medição via normalização:
- Maiúsculas, sem acentos, sem conectivos (DA/DE/DO/DAS/DOS)
- Regras específicas: `ROCHITTI → ROCHITI`, `WILLIAN → WILLIAM`

---

## ARQUIVOS EM PRODUÇÃO (VPS2)

| Arquivo | Caminho | Função |
|---------|---------|--------|
| Motor de auditoria | `/api/mao_obra/motor_auditoria_ponto_v1.php` | Classificação automática (API JSON) |
| Relatório diário | `/api/mao_obra/relatorio_auditoria_whatsapp.php` | Envio WhatsApp D-1 |
| Relatório semanal | `/api/mao_obra/relatorio_auditoria_semanal_v2.php` | Envio WhatsApp 7 dias |
| Cron diário | `/usr/local/bin/cron_auditoria_ponto.sh` | Orquestra relatório 07:30 |
| Cron semanal | `/usr/local/bin/cron_auditoria_ponto_semanal.sh` | Orquestra relatório 14:00 |
| Cron re-sync | `/usr/local/bin/cron_sync_tangerino_semanal.sh` | Re-sync Tangerino 01:00 |
| Log diário | `/var/log/auditoria_ponto.log` | — |
| Log semanal | `/var/log/auditoria_ponto_semanal.log` | — |
| Log re-sync | `/var/log/sync_tangerino_semanal.log` | — |
| Log motor | `_log_auditoria_ponto.txt` (mesmo dir) | — |
| Log relatório | `_log_relatorio_auditoria_whatsapp.txt` | — |
| Log semanal v2 | `_log_relatorio_semanal.txt` | — |

---

## CRONS (VPS2)

| Horário | Script | Função | Log |
|---------|--------|--------|-----|
| **01:00** | `cron_sync_tangerino_semanal.sh` | Re-sync últimos 7 dias do Tangerino | `/var/log/sync_tangerino_semanal.log` |
| **07:30** | `cron_auditoria_ponto.sh` | Relatório diário D-1 via WhatsApp | `/var/log/auditoria_ponto.log` |
| **14:00** | `cron_auditoria_ponto_semanal.sh` | Relatório semanal 7 dias via WhatsApp | `/var/log/auditoria_ponto_semanal.log` |

Fluxo diário: **01:00 sincroniza → 07:30 audita D-1 → 14:00 consolida semana**

Todos os crons rodam 7 dias por semana (incluindo fins de semana).

---

## DESTINATÁRIOS WHATSAPP

### Relatório diário + semanal

| Nome | Telefone |
|------|----------|
| Claudemir | 5514996109252 |
| Ana | 5514998486848 |
| Gabrielli | 5514991185676 |
| Leandro | 5514997366868 |
| Lidyane | 5514991050378 |
| Obras | 5514996237730 |

### Relatório de correções (OBRAS)

Enviado apenas no relatório semanal (14:00) para:
- **Obras** (5514996237730) — correções pendentes + falta apontamento
- **Claudemir** (5514996109252) — cópia

---

## API DO MOTOR

### Endpoint

```
GET /api/mao_obra/motor_auditoria_ponto_v1.php
```

### Parâmetros

| Param | Obrigatório | Default | Descrição |
|-------|------------|---------|-----------|
| `data_ref` | Não | hoje | Data início (YYYY-MM-DD) |
| `data_fim` | Não | data_ref | Data fim para range |
| `status` | Não | (todos) | Filtro: OK, IRREGULAR, FOLGA, FALTA_APONTAMENTO_PRODUCAO, etc. |
| `obra` | Não | (todas) | Filtro por obra (LIKE) |
| `formato` | Não | json | `json` (completo) ou `resumo` (só contadores) |
| `debug` | Não | 0 | 1 = mostra contagens internas |

### Exemplos

```bash
# Resumo do dia
curl 'https://globalsinalizacao.online/api/mao_obra/motor_auditoria_ponto_v1.php?data_ref=2026-03-17&formato=resumo'

# Só irregulares
curl '...?data_ref=2026-03-17&status=IRREGULAR'

# Range semanal com filtro de obra
curl '...?data_ref=2026-03-10&data_fim=2026-03-17&obra=BR-376'

# Falta de apontamento
curl '...?data_ref=2026-03-17&status=FALTA_APONTAMENTO_PRODUCAO'
```

### Resposta JSON

```json
{
  "ok": true,
  "resumo": {
    "periodo": "2026-03-17",
    "total_analisados": 65,
    "total_ok": 30,
    "total_dispensados": 3,
    "total_folga": 18,
    "total_irregulares": 7,
    "total_verificar": 0,
    "total_corrigir_lancamento": 3,
    "total_falta_apontamento": 4,
    "detalhamento": { ... }
  },
  "colaboradores": [
    {
      "data_ref": "2026-03-17",
      "colaborador": "NOME",
      "setor": "OBRA",
      "funcao": "AJUDANTE DE OBRAS",
      "classificacao": "OK",
      "gravidade": "OK",
      "batidas_reais": 4,
      "horas_trabalhadas": 9.2,
      "horarios": ["E:07:35 S:12:41", "E:13:41 S:18:00"],
      "obra": "BR-376",
      "encarregado": "Lucas"
    }
  ]
}
```

---

## TABELAS CONSULTADAS

| Tabela | Função |
|--------|--------|
| `espelho_tangerino_punch` | Batidas brutas da API Tangerino (horários reais) |
| `fato_ponto_diario` | Consolidação diária por colaborador |
| `Tab_medicao_nova` | Medições de produção (funcionário1-15) |
| `Tab_colaboradores` | Cadastro + alocação + tangerino_employee_id |
| `cache_tangerino_employees` | Cache de IDs Tangerino para matching |

---

## FORMATO DO RELATÓRIO WHATSAPP

### Diário (07:30)

Relatório completo do dia anterior com:
- Barra de conformidade visual (🟩⬜)
- Contadores por categoria
- Lista detalhada de irregulares com horários
- Lista de correções de lançamento
- Lista de falta de apontamento

### Semanal (14:00)

- **Relatório geral:** mesmo formato do diário, 2 dias por mensagem, intervalo de 60s entre mensagens
- **Relatório OBRAS:** consolidado de correções pendentes + falta apontamento de toda semana (enviado separado para WhatsApp Obras)

Mensagens > 3.900 chars são automaticamente divididas.

---

## OBSERVAÇÕES TÉCNICAS

### Fuso horário
- Motor usa `date_default_timezone_set('America/Sao_Paulo')`
- Timestamps do Tangerino são em millis UTC — conversão via `date('H:i', millis/1000)` com TZ configurado
- MySQL `FROM_UNIXTIME()` usa TZ do servidor (UTC) — aplicar `-3h` para BRT

### Contagem de batidas
- Cada registro `espelho_tangerino_punch` = 1 entrada + 1 saída = 2 batidas
- Motor conta individualmente: `date_in_iso` preenchido = +1, `date_out_iso` preenchido = +1
- Inclui punches com status `APPROVED` e `PENDING`
- Fallback: se não há match no espelho, usa `punches_total * 2`

### Re-sync necessário
- Sync intraday (*/30 min) pode não capturar saídas registradas após o sync
- Re-sync diário às 01:00 (últimos 7 dias) garante dados completos
- Ordem: **01:00 sync → 07:30 relatório** = dados frescos

---

## HISTÓRICO

| Data | Versão | Mudança |
|------|--------|---------|
| 2026-03-18 | v1.0 | Motor criado — 7 classificações, cruzamento medição × ponto |
| 2026-03-18 | v1.1 | Fix: incluir punches PENDING (não só APPROVED) |
| 2026-03-18 | v1.2 | Regras ADM/OFICINA (seg-sex, sem medição), invisíveis como FOLGA |
| 2026-03-18 | v1.3 | Regra SUPERVISOR = jornada ADM. Normalização WILLIAN→WILLIAM |
| 2026-03-18 | v1.4 | FALTA_APONTAMENTO_PRODUCAO, relatório WhatsApp, crons 07:30/14:00/01:00 |
| 2026-03-18 | v1.4 | Incluídos fins de semana nos relatórios. Lidyane adicionada como destinatária |

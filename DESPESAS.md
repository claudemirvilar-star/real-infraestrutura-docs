# DESPESAS — MÓDULO DE CONTROLE DE GASTOS OPERACIONAIS

> Ficha técnica auditada — gerada por cruzamento entre documentação e código em produção (VPS2)
> Última auditoria: 2026-03-17

---

## STATUS

| Campo | Valor |
|-------|-------|
| Status | **PRODUÇÃO ATIVA** |
| Frontend | Flutter Web (App Produção) |
| Backend | PHP 8.3 + MySQL (Hostinger) |
| Despesas registradas | **234** |
| Fotos anexadas | **252** (em 187 despesas) |
| Período ativo | 2026-02-05 a 2026-03-16 |
| Valor total registrado | R$ 528.238.536,44 (*) |
| Formas de pagamento | DEBITO (223) / CREDITO (11) |

> (*) Valor alto indica provável erro de lançamento em algum registro de abastecimento. Auditar.

---

## OBJETIVO

Gerenciar o ciclo completo de despesas operacionais:
- Lançamento via app (colaborador no campo)
- Anexação obrigatória de comprovantes (fotos)
- Vinculação a usuário + centro de custo (obra)
- Fluxo de aprovação administrativa (PENDENTE → APROVADA/REPROVADA)
- Fluxo de ciência do colaborador (REPROVADA → editar/cancelar/marcar ciente)
- Exportação Excel para financeiro
- Auditoria diária via endpoint + VIEW
- Campos preparados para auditoria IA (status_ia, ia_motivos_json)

---

## ARQUITETURA

```
App Flutter (Colaborador no campo)
        │
        ├── POST criar_despesa.php (JSON)
        ├── POST upload_foto_despesa.php (multipart)
        │
        ▼
Tab_despesas + Tab_despesas_fotos
        │
        ▼
Admin (Painel de aprovação)
        │
        ├── listar_despesas_admin.php (filtros + paginação)
        ├── detalhe_despesa_admin.php (fotos + dados completos)
        ├── atualizar_status_despesa_admin.php (aprovar/reprovar)
        ├── preview_total_despesas_admin.php (contagem por filtro)
        └── exportar_despesas_admin_excel.php (XLS formatado)
        │
        ▼
Colaborador (notificado de reprovação)
        │
        ├── editar_despesa_usuario.php (corrigir e reenviar)
        ├── cancelar_despesa_usuario.php (desistir)
        └── marcar_ciente_despesa_usuario.php (tomar ciência)
        │
        ▼
Auditoria
        │
        ├── auditoria_despesas_dia.php (conferência diária via VIEW)
        └── vw_despesas_auditoria_diaria (VIEW consolidada)
```

---

## ARQUIVOS EM PRODUÇÃO (17 endpoints)

| Arquivo | Função | Método |
|---------|--------|--------|
| `criar_despesa.php` | Criar nova despesa | POST JSON |
| `upload_foto_despesa.php` | Upload de comprovante | POST multipart |
| `listar_despesas_usuario.php` | Minhas despesas (colaborador) | GET |
| `editar_despesa_usuario.php` | Editar despesa REPROVADA e reenviar | POST JSON |
| `cancelar_despesa_usuario.php` | Cancelar despesa REPROVADA | POST JSON |
| `marcar_ciente_despesa_usuario.php` | Marcar ciência em reprovação | POST JSON |
| `listar_despesas_admin.php` | Lista admin com filtros | GET |
| `detalhe_despesa_admin.php` | Detalhe + fotos | GET |
| `atualizar_status_despesa_admin.php` | Aprovar/reprovar | POST |
| `preview_total_despesas_admin.php` | Contagem por filtro (preview) | GET |
| `listar_colaboradores_admin.php` | Lista colaboradores para filtro | GET |
| `exportar_despesas_admin.php` | Exportar dados | GET |
| `exportar_despesas_admin_excel.php` | Exportar Excel formatado (XLS) | GET |
| `auditoria_despesas_dia.php` | Conferência diária (usa VIEW) | GET |
| `debug_detalhe_despesa.php` | Debug detalhe (dev) | GET |
| `debug_listar_ultimas_despesas.php` | Debug lista (dev) | GET |
| `criar_despesa_28_02.php` | Lançamento pontual 28/02 (legado) | POST |

> **CORREÇÃO vs ficha original:** A ficha lista 5 endpoints. Na realidade existem **17 endpoints**, incluindo edição, cancelamento, ciência, exportação Excel, auditoria diária e preview.

---

## BASE DE DADOS

### Tab_despesas (234 registros)

```sql
id                        BIGINT(20) UNSIGNED  PK
usuario_id                BIGINT(20) UNSIGNED  INDEX
cliente_id                BIGINT(20) UNSIGNED  INDEX  -- centro de custo (Tab_clientes_nova)
tipo_despesa              VARCHAR(60)          INDEX
forma_pagamento           VARCHAR(10)                 -- DEBITO | CREDITO
data_despesa              DATE
valor                     DECIMAL(12,2)               DEFAULT 0.00
observacao                VARCHAR(255)
veiculo                   VARCHAR(80)          INDEX   -- placa (obrigatório para ABASTECIMENTO/MANUTENCAO)
km_veiculo                INT(11)                     -- km (obrigatório para ABASTECIMENTO/MANUTENCAO)
status_aprovacao          VARCHAR(20)          INDEX   DEFAULT 'PENDENTE'  -- PENDENTE|APROVADA|REPROVADA|CANCELADA
admin_usuario_id          INT(11)                     -- quem aprovou/reprovou
admin_nome                VARCHAR(120)
admin_datahora            DATETIME
admin_motivo_reprovacao   TEXT
status                    VARCHAR(20)          INDEX   DEFAULT 'PENDENTE'
status_ia                 VARCHAR(20)          INDEX   DEFAULT 'PENDENTE'  -- para auditoria IA futura
ia_motivos_json           TEXT                        -- JSON com motivos da IA
created_at                DATETIME                    DEFAULT CURRENT_TIMESTAMP
updated_at                DATETIME
colab_ciente              TINYINT(1)                  DEFAULT 0  -- colaborador tomou ciência da reprovação
colab_ciente_datahora     DATETIME
colab_ciente_obs          VARCHAR(255)
colab_auditoria_status    TINYINT(4)                  DEFAULT 0
colab_auditoria_datahora  DATETIME
colab_auditoria_obs       VARCHAR(255)
empresa_id_app            INT(11)                     DEFAULT 1146  -- forçado
reprovacoes_count         INT(11)                     DEFAULT 0     -- contagem de reprovações
primeira_reprovacao_em    DATETIME
ultima_reprovacao_em      DATETIME
```

> **CORREÇÃO vs ficha original:** A ficha lista 9 campos simples ("id, usuario_id, cliente_id, tipo_despesa, valor, data_despesa, descricao, forma_pagamento, status"). Na realidade a tabela tem **30 campos**, incluindo campos de veículo/km, auditoria admin, ciência do colaborador, auditoria IA e contagem de reprovações.

### Tab_despesas_fotos (252 fotos)

```sql
id              BIGINT(20) UNSIGNED  PK
despesa_id      BIGINT(20) UNSIGNED  INDEX
ordem           TINYINT(3) UNSIGNED
arquivo         VARCHAR(180)
path            VARCHAR(200)
hash_sha256     CHAR(64)             INDEX  -- hash do arquivo para deduplicação
created_at      DATETIME             DEFAULT CURRENT_TIMESTAMP
```

> **CORREÇÃO vs ficha original:** A ficha lista 3 campos ("id, id_despesa, caminho_arquivo, ordem"). Na realidade são **7 campos**, com campos separados `arquivo` e `path`, e `hash_sha256` para detecção de duplicatas.

### vw_despesas_auditoria_diaria (VIEW)

VIEW consolidada que faz JOIN entre:
- `Tab_despesas` (d)
- `Tab_despesas_fotos` (pivot: foto1/foto2/foto3 + qtd_fotos)
- `Tab_colaboradores` (nome do usuário)
- `Tab_clientes_nova` (nome do centro de custo)

Retorna URLs completas das fotos (até 3) + dados de auditoria IA.

> **CORREÇÃO vs ficha original:** VIEW não mencionada na ficha. Existe e é usada pelo endpoint `auditoria_despesas_dia.php`.

---

## REGRAS DE NEGÓCIO (auditadas no código)

### REGRA 1 — Vinculação obrigatória

| Campo | Obrigatório | Contexto |
|-------|------------|----------|
| `usuario_id` | Sempre | Quem lançou |
| `cliente_id` | Sempre | Centro de custo (obra) |
| `tipo_despesa` | Sempre | Categoria |
| `valor` | Sempre | > 0 |
| `data_despesa` | Sempre | YYYY-MM-DD |
| `forma_pagamento` | Sempre | DEBITO ou CREDITO |
| `veiculo` | Se ABASTECIMENTO ou MANUTENCAO | Placa do veículo |
| `km_veiculo` | Se ABASTECIMENTO ou MANUTENCAO | km > 0 |

> **CORREÇÃO vs ficha original:** A ficha não menciona `forma_pagamento` como obrigatório, nem a regra de veículo/km para abastecimento/manutenção. Ambos são obrigatórios no código (V4).

### REGRA 2 — Fluxo de status

```
PENDENTE → APROVADA (admin aprova)
PENDENTE → REPROVADA (admin reprova com motivo)
REPROVADA → PENDENTE (colaborador edita e reenvia)
REPROVADA → CANCELADA (colaborador cancela)
REPROVADA → CIENTE (colaborador marca ciência sem editar)
```

> **CORREÇÃO vs ficha original:** A ficha mostra fluxo simples "pendente → aprovado/reprovado". O fluxo real inclui **reenvio, cancelamento e ciência** do colaborador.

### REGRA 3 — Tipos de despesa (auditados no banco)

| Tipo | Qtd | Total R$ |
|------|-----|----------|
| Abastecimento | 87 | 527.480.641 (*) |
| Hospedagem | 57 | 324.662 |
| Ferramentas | 26 | 333.837 |
| Lavagem de Roupas | 12 | 17.472 |
| Café | 10 | 306 |
| Almoço | 8 | 6.855 |
| Gastos Alojamento | 8 | 33.844 |
| Outros | 8 | 2.057 |
| Manutenção | 6 | 31.484 |
| Gelo | 5 | 234 |
| COMBUSTIVEL | 3 | 1.630 |
| Gases (Oxigênio e Acetileno) | 2 | 1.120 |
| Jantar | 1 | 3.000 |
| Uber | 1 | 1.395 |

> **NOTA:** Tipo "COMBUSTIVEL" coexiste com "Abastecimento" — possível duplicidade a padronizar.

### REGRA 4 — empresa_id_app

Forçado `1146` no backend (hardcoded). Toda despesa pertence à mesma empresa.

---

## STORAGE DE FOTOS

| Item | Valor |
|------|-------|
| Path base | `/public_html/app/fotos_despesas/YYYY/MM/` |
| Formato nome | `<ID>-<TIPO_NORMALIZADO>-<ORDEM>.jpg` |
| Exemplo | `123-ABASTECIMENTO-1.jpg` |
| Total em disco | **210 arquivos JPG** |
| Total no banco | **252 registros** (diferença = possíveis fotos deletadas do disco) |
| Hash SHA-256 | Sim — gravado em `Tab_despesas_fotos.hash_sha256` |

---

## AUDITORIA IA (PREPARADO, NÃO IMPLEMENTADO)

| Campo | Status |
|-------|--------|
| `status_ia` | Existe na tabela, **todos = PENDENTE** (234/234) |
| `ia_motivos_json` | Existe na tabela, vazio |
| VIEW de auditoria | Inclui campos IA |
| Motor IA | **NÃO implementado** |

> A estrutura está pronta para receber um motor de auditoria IA que analise fotos + dados e classifique risco.

---

## INTEGRAÇÃO VEXPENSES

| Arquivo | Função |
|---------|--------|
| `/api/bi_producao/vexpenses_sync.php` | Sync com sistema VExpenses |
| `/api/bi_producao/sync_vexpenses_despesas_credito.php` | Sync despesas crédito |

> Sem cron ativo para sync automático. Execução manual.

---

## CORREÇÕES VS FICHA CHATGPT

| Item | Ficha ChatGPT | Realidade VPS |
|------|---------------|---------------|
| Endpoints | 5 listados | **17 endpoints** |
| Schema Tab_despesas | 9 campos | **30 campos** (veículo, km, IA, ciência, reprovações) |
| Schema Tab_despesas_fotos | 3 campos simples | **7 campos** (com hash_sha256) |
| Fluxo de status | "pendente → aprovado/reprovado" | **5 status: PENDENTE, APROVADA, REPROVADA, CANCELADA + fluxo reenvio/ciência** |
| forma_pagamento | Não mencionado como obrigatório | **Obrigatório (DEBITO/CREDITO)** |
| Veículo/km | Não mencionado | **Obrigatório para ABASTECIMENTO e MANUTENCAO** |
| VIEW auditoria | Não mencionada | **`vw_despesas_auditoria_diaria` existe e é usada** |
| Auditoria IA | "ROADMAP - criar agente" | **Campos já existem no banco (status_ia, ia_motivos_json), motor não implementado** |
| Hash fotos | Não mencionado | **hash_sha256 gravado para cada foto** |
| Exportação Excel | Não mencionada | **Endpoint existente e funcional** |
| Edição/cancelamento | Não mencionado | **Fluxo completo: editar + cancelar + marcar ciente** |
| Campo descricao | "descricao" | **Campo real é `observacao`** |

---

## RISCOS OPERACIONAIS (ATUALIZADOS)

| Risco | Severidade | Status |
|-------|-----------|--------|
| Valor aberrante em Abastecimento (R$527M) | **ALTA** | PENDENTE — auditar registros |
| Tipo duplicado (COMBUSTIVEL vs Abastecimento) | Baixa | PENDENTE — padronizar |
| Fraude por foto inválida | Média | MITIGADO parcialmente (hash_sha256) |
| IA de auditoria não implementada | Média | PENDENTE (estrutura pronta) |
| Despesas sem foto (47 de 234) | Média | PENDENTE — validar obrigatoriedade |
| Lançamento duplicado | Baixa | MITIGADO parcialmente (hash foto) |

---

## ROADMAP PENDENTE

| Item | Prioridade | Status |
|------|-----------|--------|
| Auditar valor aberrante em Abastecimento | **Crítica** | Pendente |
| Motor IA de auditoria de fotos | Alta | Estrutura pronta, motor pendente |
| Padronizar tipos (COMBUSTIVEL → Abastecimento) | Média | Pendente |
| Integração financeira (contas a pagar / DRE) | Média | Pendente |
| Tab_mapa_tipo_despesa_dre | Média | Pendente |
| Cron automático VExpenses sync | Baixa | Pendente |
| Score de risco por usuário | Baixa | Pendente |

---

*Documento gerado por auditoria direta do código e banco de dados em produção (VPS2: 187.77.235.22)*

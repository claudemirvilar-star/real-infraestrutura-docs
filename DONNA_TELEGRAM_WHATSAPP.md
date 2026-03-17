# DONNA — BOT WHATSAPP + TELEGRAM

> Ficha técnica auditada — cruzamento código × banco em produção (VPS2)
> Última auditoria: 2026-03-17

---

## STATUS

| Campo | Valor |
|-------|-------|
| Status | **PRODUÇÃO ATIVA** |
| WhatsApp | Canal principal (desde implantação) |
| Telegram | @openclaw_realdefensas_bot (desde 2026-03-17) |
| Transcrição áudio | OpenAI Whisper (whisper-1) em ambos canais |

---

## CANAIS

### WhatsApp
- **Phone Number ID:** 1005476022651238
- **Token:** Permanente (System User — nunca expira)
- **Graph API:** v25.0
- **Webhook:** `https://globalsinalizacao.online/app/whatsapp/webhook.php`

### Telegram
- **Bot:** @openclaw_realdefensas_bot (Dona Paulsen - Real)
- **Bot ID:** 8523749319
- **Webhook:** `https://globalsinalizacao.online/app/telegram/webhook.php`

---

## ARQUIVOS

### WhatsApp

| Arquivo | Função |
|---------|--------|
| `webhook.php` | Recebe mensagens (texto + áudio) |
| `inbound_handler.php` | Bridge com fatal trap |
| `inbound_router.php` | Router principal (todos os comandos) |
| `whatsapp_send.php` | Envio de mensagens (texto + template) |
| `whisper_transcribe.php` | Transcrição áudio via Whisper |
| `DonaHandler.php` | Handler CEABS com confirmação 2-step |
| `DonaParser.php` | Parser de intenção (ação + placa + motivo) |
| `DonaMcpClient.php` | Cliente MCP interno |
| `WhatsAppCloud.php` | Wrapper WhatsApp Cloud API |
| `helpers.php` | Helpers (normalização, tokens) |
| `usuario_resolver.php` | Resolução telefone → usuário |
| `meta_config.php` | Config Meta (carrega de _secrets) |

### Telegram

| Arquivo | Função |
|---------|--------|
| `webhook.php` | Recebe updates (texto + áudio + voice + video_note) |
| `telegram_send.php` | Envio de mensagens |
| `whisper_transcribe_telegram.php` | Transcrição via Whisper (Bot API) |

### Secrets

| Arquivo | Conteúdo |
|---------|----------|
| `_secrets/whatsapp_config.php` | Token WhatsApp, phone_number_id, verify_token |
| `_secrets/telegram_config.php` | Token bot Telegram |
| `_secrets/openai_config.php` | Chave OpenAI (Whisper + GPT-4o-mini) |

---

## COMANDOS DISPONÍVEIS

| Comando | Ação | Canal |
|---------|------|-------|
| `ajuda` / `menu` | Lista comandos | Ambos |
| `frota` | Toda a frota com status | Ambos |
| `frota real` | Só veículos REAL | Ambos |
| `frota bth` | Só veículos locados | Ambos |
| `status <placa/apelido>` | Status + localização + motorista | Ambos |
| `localizar <placa/apelido>` | Mesmo que status | Ambos |
| `bloquear <placa/apelido>` | Bloqueio com confirmação (SIM) | Ambos |
| `desbloquear <placa/apelido>` | Desbloqueio com confirmação | Ambos |
| `relatorio ceabs` | Relatório de bloqueios | Ambos |
| `ativar alertas ceabs` | Inscreve em notificações CEABS | Ambos |
| `desativar alertas ceabs` | Remove inscrição | Ambos |
| `ativar alertas rh` | Inscreve em resumo cobrança ponto | Ambos |
| `desativar alertas rh` | Remove inscrição | Ambos |

---

## TRANSCRIÇÃO DE ÁUDIO

| Item | WhatsApp | Telegram |
|------|----------|----------|
| Tipos suportados | audio, voice | voice, audio, video_note |
| Download | Graph API Meta | Telegram Bot API (getFile) |
| Motor | OpenAI Whisper (whisper-1) | OpenAI Whisper (whisper-1) |
| Idioma | pt (português) | pt (português) |
| Limite | Sem limite definido | 20MB (Bot API) |
| Fallback | "Não consegui entender" | "Não consegui entender" |

---

## FLUXO TELEGRAM

```
Update Telegram → webhook.php
  ├── /start → boas-vindas
  ├── texto → remove / do comando → inbound_handler.php
  ├── voice/audio → Whisper → texto → inbound_handler.php
  └── video_note → Whisper → texto → inbound_handler.php
         │
         ▼
    inbound_router.php (mesmo do WhatsApp)
         │
         ▼
    telegram_send_text() → resposta
```

Nota: `from` no router = `tg:<user_id>` para diferenciar de WhatsApp.

---

*Documento gerado por auditoria direta do código e banco de dados em produção*

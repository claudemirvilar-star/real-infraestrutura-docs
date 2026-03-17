<?php
// ======================================================
// ANCHOR: TELEGRAM_SEND_AUDIO_V1
// Arquivo: /public_html/app/telegram/telegram_send_audio.php
// Objetivo: Gerar áudio via OpenAI TTS e enviar via Telegram Bot API
// Criado: 2026-03-17
// ======================================================

/**
 * Gera áudio TTS via OpenAI e envia como voice message no Telegram.
 *
 * @param string $chat_id  Chat ID do Telegram
 * @param string $text     Texto para converter em áudio (max ~4096 chars)
 * @param string $voice    Voz OpenAI: alloy, echo, fable, onyx, nova, shimmer
 * @return array ["ok" => bool, "msg" => string]
 */
function telegram_send_audio(string $chat_id, string $text, string $voice = 'nova'): array {

    $ai_cfg = require __DIR__ . "/../_secrets/openai_config.php";
    $tg_cfg = require __DIR__ . "/../_secrets/telegram_config.php";

    $ai_key    = $ai_cfg["api_key"] ?? "";
    $bot_token = $tg_cfg["bot_token"] ?? "";

    if (!$ai_key)    return ["ok" => false, "msg" => "OpenAI api_key vazia"];
    if (!$bot_token)  return ["ok" => false, "msg" => "Telegram bot_token vazio"];
    if (!$chat_id)    return ["ok" => false, "msg" => "chat_id vazio"];
    if (trim($text) === "") return ["ok" => false, "msg" => "texto vazio"];

    // ── Limpar texto para TTS (remover formatação Markdown/WhatsApp) ──
    $tts_text = _tts_clean_text($text);

    // Limitar a 4096 chars (limite OpenAI TTS)
    if (mb_strlen($tts_text) > 4096) {
        $tts_text = mb_substr($tts_text, 0, 4090) . "...";
    }

    // ══════════════════════════════════════════════════════════════
    // ETAPA 1: Gerar áudio via OpenAI TTS API
    // ══════════════════════════════════════════════════════════════

    $tts_payload = json_encode([
        "model" => "tts-1",
        "input" => $tts_text,
        "voice" => $voice,
        "response_format" => "opus",  // Formato leve, compatível com Telegram voice
    ], JSON_UNESCAPED_UNICODE);

    $ch = curl_init("https://api.openai.com/v1/audio/speech");
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            "Authorization: Bearer {$ai_key}",
            "Content-Type: application/json",
        ],
        CURLOPT_POSTFIELDS     => $tts_payload,
        CURLOPT_TIMEOUT        => 60,
    ]);

    $audio_data = curl_exec($ch);
    $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($err) {
        return ["ok" => false, "msg" => "TTS cURL erro: {$err}"];
    }

    if ($http !== 200) {
        $error_detail = substr($audio_data, 0, 300);
        return ["ok" => false, "msg" => "TTS HTTP {$http}: {$error_detail}"];
    }

    if (strlen($audio_data) < 100) {
        return ["ok" => false, "msg" => "TTS retornou áudio muito pequeno (" . strlen($audio_data) . " bytes)"];
    }

    // ══════════════════════════════════════════════════════════════
    // ETAPA 2: Salvar arquivo temporário
    // ══════════════════════════════════════════════════════════════

    $tmp_dir = __DIR__ . "/runtime/tts_tmp";
    if (!is_dir($tmp_dir)) @mkdir($tmp_dir, 0755, true);

    $tmp_file = $tmp_dir . "/dre_" . md5($chat_id . time()) . ".ogg";
    file_put_contents($tmp_file, $audio_data);

    // ══════════════════════════════════════════════════════════════
    // ETAPA 3: Enviar via Telegram Bot API (sendVoice)
    // ══════════════════════════════════════════════════════════════

    $url = "https://api.telegram.org/bot{$bot_token}/sendVoice";

    $cfile = new CURLFile($tmp_file, "audio/ogg", "analise_dre.ogg");

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POSTFIELDS     => [
            "chat_id" => $chat_id,
            "voice"   => $cfile,
        ],
        CURLOPT_TIMEOUT        => 30,
    ]);

    $resp = curl_exec($ch);
    $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    // Limpar arquivo temporário
    @unlink($tmp_file);

    if ($err) {
        return ["ok" => false, "msg" => "sendVoice cURL erro: {$err}"];
    }

    $json = json_decode($resp, true);
    $tg_ok = $json["ok"] ?? false;

    if (!$tg_ok) {
        $desc = $json["description"] ?? $resp;
        return ["ok" => false, "msg" => "sendVoice falhou: {$desc}"];
    }

    return ["ok" => true, "msg" => "Áudio enviado com sucesso"];
}

/**
 * Limpa texto para TTS — remove formatação Markdown/WhatsApp.
 */
function _tts_clean_text(string $text): string {
    // Remover emojis de seção (manter texto)
    $text = preg_replace('/[📊📈📋🔴🟢🟡⚠️✅❌💡🎯]+\s*/u', '', $text);

    // Remover *negrito* → negrito
    $text = preg_replace('/\*([^*]+)\*/', '$1', $text);

    // Remover _itálico_ → itálico
    $text = preg_replace('/\_([^_]+)\_/', '$1', $text);

    // Remover ``` blocos de código ```
    $text = preg_replace('/```[^`]*```/s', '', $text);

    // Remover `código inline`
    $text = preg_replace('/`([^`]+)`/', '$1', $text);

    // Substituir "R$" por "reais" para pronúncia natural
    $text = preg_replace('/R\$\s*/', 'reais ', $text);

    // Substituir "%" por "por cento"
    $text = str_replace('%', ' por cento', $text);

    // Limpar espaços múltiplos
    $text = preg_replace('/\s+/', ' ', $text);

    return trim($text);
}

// ══════════════════════════════════════════════════════════════
// CLI TEST MODE
// ══════════════════════════════════════════════════════════════
if (php_sapi_name() === 'cli' && isset($argv[1]) && $argv[1] === '--test') {
    $test_text = $argv[2] ?? "Teste de áudio da Donna. A análise DRE está pronta.";
    $test_chat_id = $argv[3] ?? "";

    if (!$test_chat_id) {
        echo "Uso: php telegram_send_audio.php --test \"texto\" <chat_id>\n";
        exit(1);
    }

    echo "Gerando TTS e enviando para chat_id={$test_chat_id}...\n";
    $result = telegram_send_audio($test_chat_id, $test_text);
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
    exit($result["ok"] ? 0 : 1);
}

// ======================================================
// FIM ANCHOR: TELEGRAM_SEND_AUDIO_V1
// ======================================================

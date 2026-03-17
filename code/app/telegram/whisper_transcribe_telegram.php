<?php
// ======================================================
// ANCHOR: WHISPER_TRANSCRIBE_TELEGRAM_V1
// Arquivo: /public_html/app/telegram/whisper_transcribe_telegram.php
// Objetivo: baixar áudio do Telegram e transcrever via OpenAI Whisper
// ======================================================

function whisper_transcribe_telegram(string $file_id): array {

    $tg_cfg = require __DIR__ . "/../_secrets/telegram_config.php";
    $ai_cfg = require __DIR__ . "/../_secrets/openai_config.php";

    $bot_token = $tg_cfg["bot_token"] ?? "";
    $ai_key    = $ai_cfg["api_key"] ?? "";
    $model     = $ai_cfg["model_transcription"] ?? "whisper-1";

    if (!$bot_token) return ["ok" => false, "text" => "", "erro" => "bot_token vazio"];
    if (!$ai_key)    return ["ok" => false, "text" => "", "erro" => "OpenAI api_key vazia"];
    if (!$file_id)   return ["ok" => false, "text" => "", "erro" => "file_id vazio"];

    // ======================================================
    // ETAPA 1: Obter file_path via getFile
    // ======================================================
    $url = "https://api.telegram.org/bot{$bot_token}/getFile?file_id=" . urlencode($file_id);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
    ]);
    $resp = curl_exec($ch);
    $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($err || $http !== 200) {
        return ["ok" => false, "text" => "", "erro" => "getFile falhou (HTTP {$http}): {$err}"];
    }

    $json = json_decode($resp, true);
    if (!($json["ok"] ?? false)) {
        return ["ok" => false, "text" => "", "erro" => "getFile erro: " . ($json["description"] ?? $resp)];
    }

    $file_path = $json["result"]["file_path"] ?? "";
    if (!$file_path) {
        return ["ok" => false, "text" => "", "erro" => "file_path vazio"];
    }

    // ======================================================
    // ETAPA 2: Baixar o arquivo de áudio
    // ======================================================
    $download_url = "https://api.telegram.org/file/bot{$bot_token}/{$file_path}";

    $ch = curl_init($download_url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_FOLLOWLOCATION => true,
    ]);
    $audio_data = curl_exec($ch);
    $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($err || $http !== 200 || !$audio_data) {
        return ["ok" => false, "text" => "", "erro" => "Download falhou (HTTP {$http}): {$err}"];
    }

    // Extensão pelo file_path
    $ext = pathinfo($file_path, PATHINFO_EXTENSION) ?: "ogg";

    // Salvar temporariamente
    $tmp_dir = __DIR__ . "/runtime/audio_tmp";
    if (!is_dir($tmp_dir)) @mkdir($tmp_dir, 0755, true);
    $tmp_file = $tmp_dir . "/tg_" . md5($file_id) . "." . $ext;
    file_put_contents($tmp_file, $audio_data);

    // ======================================================
    // ETAPA 3: Enviar para OpenAI Whisper
    // ======================================================
    $mime_map = [
        "ogg"  => "audio/ogg",
        "oga"  => "audio/ogg",
        "mp3"  => "audio/mpeg",
        "m4a"  => "audio/mp4",
        "wav"  => "audio/wav",
        "webm" => "audio/webm",
    ];
    $mime = $mime_map[$ext] ?? "audio/ogg";

    $cfile = new CURLFile($tmp_file, $mime, "audio.{$ext}");

    $ch = curl_init("https://api.openai.com/v1/audio/transcriptions");
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            "Authorization: Bearer {$ai_key}",
        ],
        CURLOPT_POSTFIELDS => [
            "file"            => $cfile,
            "model"           => $model,
            "language"        => "pt",
            "response_format" => "json",
        ],
        CURLOPT_TIMEOUT => 60,
    ]);

    $resp = curl_exec($ch);
    $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    // Limpar arquivo temporário
    @unlink($tmp_file);

    if ($err) {
        return ["ok" => false, "text" => "", "erro" => "Whisper cURL erro: {$err}"];
    }

    if ($http !== 200) {
        return ["ok" => false, "text" => "", "erro" => "Whisper HTTP {$http}: {$resp}"];
    }

    $result = json_decode($resp, true);
    $text = trim($result["text"] ?? "");

    if ($text === "") {
        return ["ok" => false, "text" => "", "erro" => "Whisper retornou texto vazio"];
    }

    return ["ok" => true, "text" => $text, "erro" => null];
}

// ======================================================
// FIM ANCHOR: WHISPER_TRANSCRIBE_TELEGRAM_V1
// ======================================================

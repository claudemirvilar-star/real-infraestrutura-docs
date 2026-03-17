<?php
// ======================================================
// ANCHOR: TELEGRAM_WEBHOOK_V1
// Arquivo: /public_html/app/telegram/webhook.php
// URL: https://globalsinalizacao.online/app/telegram/webhook.php
// Objetivo:
// - Receber mensagens do Telegram (texto + áudio/voice)
// - Transcrever áudio via Whisper
// - Rotear para o inbound_router.php da Donna (mesmo do WhatsApp)
// - Responder via Telegram Bot API
// ======================================================

require_once __DIR__ . "/telegram_send.php";
require_once __DIR__ . "/whisper_transcribe_telegram.php";

// ------------------------------
// LOGGER
// ------------------------------
function tg_log($label, $data = null) {
    $dir = __DIR__ . "/runtime";
    if (!is_dir($dir)) @mkdir($dir, 0755, true);

    $file = $dir . "/telegram_webhook.log";
    $ts = date("Y-m-d H:i:s");

    $line = "[$ts] $label";
    if ($data !== null) {
        if (is_string($data)) $line .= " | " . $data;
        else $line .= " | " . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    $line .= "\n";

    @file_put_contents($file, $line, FILE_APPEND);
}

// ======================================================
// Apenas POST
// ======================================================
if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    http_response_code(200);
    echo "ok";
    exit;
}

$raw = file_get_contents("php://input") ?: "";
tg_log("POST_RAW_LEN", strlen($raw));

$update = json_decode($raw, true);
if (!is_array($update)) {
    tg_log("INVALID_JSON", $raw);
    http_response_code(200);
    echo "ok";
    exit;
}

tg_log("UPDATE", $update);

// ======================================================
// Extrair mensagem
// ======================================================
$message = $update["message"] ?? null;

if (!$message) {
    tg_log("NO_MESSAGE", ["has_edited" => isset($update["edited_message"])]);
    http_response_code(200);
    echo "ok";
    exit;
}

$chat_id   = $message["chat"]["id"] ?? "";
$from_id   = $message["from"]["id"] ?? "";
$from_name = trim(($message["from"]["first_name"] ?? "") . " " . ($message["from"]["last_name"] ?? ""));
$from_user = $message["from"]["username"] ?? "";
$msg_id    = $message["message_id"] ?? "";
$msg_date  = $message["date"] ?? "";

$msg_text  = "";
$is_audio  = false;

// ======================================================
// TEXTO
// ======================================================
if (isset($message["text"])) {
    $msg_text = trim($message["text"]);

    // Remover /start e /comando do início
    if (strpos($msg_text, "/") === 0) {
        $parts = explode(" ", $msg_text, 2);
        $cmd = strtolower($parts[0]);
        if ($cmd === "/start") {
            telegram_send_text($chat_id, "🤖 *Donna* pronta!\n\nDigite *ajuda* para ver os comandos disponíveis.");
            http_response_code(200);
            echo "ok";
            exit;
        }
        // Outros /comandos: remover a barra e tratar como texto normal
        $msg_text = ltrim($msg_text, "/");
    }
}

// ======================================================
// ÁUDIO / VOICE
// ======================================================
if (isset($message["voice"]) || isset($message["audio"])) {
    $voice_info = $message["voice"] ?? $message["audio"] ?? null;
    $file_id = $voice_info["file_id"] ?? "";
    $duration = $voice_info["duration"] ?? 0;

    tg_log("AUDIO_RECEIVED", [
        "chat_id"  => $chat_id,
        "from"     => $from_name,
        "file_id"  => $file_id,
        "duration" => $duration,
        "mime"     => $voice_info["mime_type"] ?? "",
    ]);

    if ($file_id) {
        // Limite: Telegram permite download de até 20MB via Bot API
        $transcr = whisper_transcribe_telegram($file_id);

        tg_log("WHISPER_RESULT", [
            "ok"       => $transcr["ok"],
            "text_len" => strlen($transcr["text"] ?? ""),
            "erro"     => $transcr["erro"] ?? null,
        ]);

        if ($transcr["ok"] && trim($transcr["text"]) !== "") {
            $msg_text = trim($transcr["text"]);
            $is_audio = true;
        } else {
            telegram_send_text($chat_id, "Não consegui entender o áudio. Pode repetir por texto? 🎤❌");
            tg_log("AUDIO_TRANSCRIPTION_FAILED", $transcr["erro"] ?? "unknown");
            http_response_code(200);
            echo "ok";
            exit;
        }
    }
}

// ======================================================
// VIDEO NOTE (círculos de vídeo) — extrair áudio
// ======================================================
if (isset($message["video_note"])) {
    $vn = $message["video_note"];
    $file_id = $vn["file_id"] ?? "";

    tg_log("VIDEO_NOTE_RECEIVED", ["chat_id" => $chat_id, "file_id" => $file_id]);

    if ($file_id) {
        $transcr = whisper_transcribe_telegram($file_id);
        if ($transcr["ok"] && trim($transcr["text"]) !== "") {
            $msg_text = trim($transcr["text"]);
            $is_audio = true;
        } else {
            telegram_send_text($chat_id, "Não consegui transcrever o vídeo. Pode digitar? 🎤❌");
            http_response_code(200);
            echo "ok";
            exit;
        }
    }
}

// Se não tem texto (foto, sticker, etc), ignora
if ($msg_text === "") {
    tg_log("NON_TEXT_MESSAGE", ["type" => array_keys($message)]);
    http_response_code(200);
    echo "ok";
    exit;
}

tg_log("INBOUND_" . ($is_audio ? "AUDIO_TRANSCRIBED" : "TEXT"), [
    "chat_id"   => $chat_id,
    "from"      => $from_name,
    "from_user" => $from_user,
    "text"      => $msg_text,
    "is_audio"  => $is_audio,
]);

// ======================================================
// Rotear para inbound_router.php (mesmo da Donna WhatsApp)
// Simula o formato que o router espera
// ======================================================

$router_url = "https://globalsinalizacao.online/app/whatsapp/inbound_handler.php";

$router_payload = [
    "tool" => "whatsapp.inbound",
    "args" => [
        "from"     => "tg:" . $from_id,
        "text"     => $msg_text,
        "is_audio" => $is_audio,
    ],
    "meta" => [
        "source"    => "telegram:user:" . $from_id,
        "chat_id"   => $chat_id,
        "from_name" => $from_name,
        "username"  => $from_user,
    ]
];

$ch = curl_init($router_url);
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => ["Content-Type: application/json"],
    CURLOPT_POSTFIELDS     => json_encode($router_payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    CURLOPT_TIMEOUT        => 55,
]);

$resp_body = curl_exec($ch);
$resp_http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
$resp_err  = curl_error($ch);
curl_close($ch);

tg_log("ROUTER_RESPONSE", [
    "http" => $resp_http,
    "body" => $resp_body,
    "err"  => $resp_err,
]);

// Extrair reply_text da resposta do router
$reply = "";
$router_json = json_decode($resp_body, true);

if (is_array($router_json)) {
    $reply = $router_json["data"]["reply_text"]
          ?? $router_json["reply_text"]
          ?? $router_json["msg"]
          ?? "";
}

if ($reply === "") {
    $reply = "Recebi ✅ Me diga o que você precisa. Digite ajuda.";
}

// ======================================================
// Enviar resposta via Telegram
// ======================================================
$reply = trim((string)$reply);

// Converter formatação WhatsApp (*bold*) para Telegram Markdown
// WhatsApp usa *bold*, Telegram Markdown também usa *bold* — compatível!

$sendResult = telegram_send_text($chat_id, $reply);
tg_log("SEND_RESPONSE", $sendResult);

http_response_code(200);
echo "ok";
exit;

// ======================================================
// FIM ANCHOR: TELEGRAM_WEBHOOK_V1
// ======================================================

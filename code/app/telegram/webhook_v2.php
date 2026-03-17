<?php
// ======================================================
// ANCHOR: TELEGRAM_WEBHOOK_V2
// Arquivo: /public_html/app/telegram/webhook_v2.php
// URL: https://globalsinalizacao.online/app/telegram/webhook_v2.php
// Objetivo:
// - Camada acima do webhook.php original
// - Intercepta intenção DRE → análise multi-perspectiva + áudio TTS
// - Tudo que NÃO é DRE → fluxo original (inbound_handler.php)
// - ZERO alterações nos arquivos existentes
// Criado: 2026-03-17
// ======================================================

require_once __DIR__ . "/telegram_send.php";
require_once __DIR__ . "/telegram_send_audio.php";
require_once __DIR__ . "/whisper_transcribe_telegram.php";
require_once __DIR__ . "/donna_dre_intent.php";
require_once __DIR__ . "/../financeiro/donna_dre_handler.php";

// ------------------------------
// LOGGER
// ------------------------------
function tg2_log($label, $data = null) {
    $dir = __DIR__ . "/runtime";
    if (!is_dir($dir)) @mkdir($dir, 0755, true);

    $file = $dir . "/telegram_webhook_v2.log";
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
tg2_log("POST_RAW_LEN", strlen($raw));

$update = json_decode($raw, true);
if (!is_array($update)) {
    tg2_log("INVALID_JSON", substr($raw, 0, 200));
    http_response_code(200);
    echo "ok";
    exit;
}

tg2_log("UPDATE_ID", $update["update_id"] ?? "?");

// ======================================================
// Extrair mensagem (mesmo código do webhook.php original)
// ======================================================
$message = $update["message"] ?? null;

if (!$message) {
    tg2_log("NO_MESSAGE", ["has_edited" => isset($update["edited_message"])]);
    http_response_code(200);
    echo "ok";
    exit;
}

$chat_id   = $message["chat"]["id"] ?? "";
$from_id   = $message["from"]["id"] ?? "";
$from_name = trim(($message["from"]["first_name"] ?? "") . " " . ($message["from"]["last_name"] ?? ""));
$from_user = $message["from"]["username"] ?? "";
$msg_id    = $message["message_id"] ?? "";

$msg_text  = "";
$is_audio  = false;

// ======================================================
// TEXTO
// ======================================================
if (isset($message["text"])) {
    $msg_text = trim($message["text"]);

    // /start → resposta direta
    if (strpos($msg_text, "/") === 0) {
        $parts = explode(" ", $msg_text, 2);
        $cmd = strtolower($parts[0]);
        if ($cmd === "/start") {
            telegram_send_text($chat_id, "🤖 *Donna* pronta!\n\nDigite *ajuda* para ver os comandos disponíveis.\n\n📊 *Novo!* Peça análise DRE: _\"me mostra o DRE do trimestre\"_");
            http_response_code(200);
            echo "ok";
            exit;
        }
        $msg_text = ltrim($msg_text, "/");
    }
}

// ======================================================
// ÁUDIO / VOICE
// ======================================================
if (isset($message["voice"]) || isset($message["audio"])) {
    $voice_info = $message["voice"] ?? $message["audio"] ?? null;
    $file_id = $voice_info["file_id"] ?? "";

    tg2_log("AUDIO_RECEIVED", ["chat_id" => $chat_id, "from" => $from_name, "file_id" => $file_id]);

    if ($file_id) {
        $transcr = whisper_transcribe_telegram($file_id);

        tg2_log("WHISPER_RESULT", [
            "ok"   => $transcr["ok"],
            "text" => substr($transcr["text"] ?? "", 0, 100),
        ]);

        if ($transcr["ok"] && trim($transcr["text"]) !== "") {
            $msg_text = trim($transcr["text"]);
            $is_audio = true;
        } else {
            telegram_send_text($chat_id, "Não consegui entender o áudio. Pode repetir por texto? 🎤❌");
            http_response_code(200);
            echo "ok";
            exit;
        }
    }
}

// ======================================================
// VIDEO NOTE (círculos de vídeo)
// ======================================================
if (isset($message["video_note"])) {
    $vn = $message["video_note"];
    $file_id = $vn["file_id"] ?? "";

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

// Se não tem texto, ignora
if ($msg_text === "") {
    tg2_log("NO_TEXT", ["type" => array_keys($message)]);
    http_response_code(200);
    echo "ok";
    exit;
}

tg2_log("MSG_" . ($is_audio ? "AUDIO" : "TEXT"), [
    "chat_id" => $chat_id, "from" => $from_name, "text" => $msg_text
]);

// ══════════════════════════════════════════════════════════════
// INTERCEPTAÇÃO DRE — ANTES do fluxo normal
// ══════════════════════════════════════════════════════════════

if (donna_dre_detect($msg_text)) {

    tg2_log("DRE_INTENT_DETECTED", $msg_text);

    // Enviar indicador de "digitando..."
    _tg2_send_chat_action($chat_id, "typing");

    $context = [
        "from"      => "tg:" . $from_id,
        "chat_id"   => $chat_id,
        "from_name" => $from_name,
        "source"    => "telegram",
        "is_audio"  => $is_audio,
    ];

    $result = donna_dre_handle($msg_text, $context);

    tg2_log("DRE_RESULT", ["ok" => $result['ok'], "texto_len" => strlen($result['reply_text'] ?? '')]);

    // ── Enviar TEXTO ──
    if (!empty($result['reply_text'])) {
        // Telegram tem limite de 4096 chars por mensagem
        $texto = $result['reply_text'];
        if (mb_strlen($texto) > 4096) {
            // Dividir em partes
            $partes = _tg2_split_message($texto, 4000);
            foreach ($partes as $parte) {
                telegram_send_text($chat_id, $parte);
                usleep(300000); // 300ms entre mensagens para manter ordem
            }
        } else {
            telegram_send_text($chat_id, $texto);
        }
    }

    // ── Enviar ÁUDIO (TTS) ──
    if ($result['ok'] && !empty($result['audio_text'])) {
        _tg2_send_chat_action($chat_id, "upload_voice");

        $audio_result = telegram_send_audio($chat_id, $result['audio_text']);
        tg2_log("DRE_AUDIO", $audio_result);

        if (!$audio_result['ok']) {
            tg2_log("DRE_AUDIO_FALHA", $audio_result['msg']);
            // Não enviar mensagem de erro ao usuário, o texto já foi enviado
        }
    }

    http_response_code(200);
    echo "ok";
    exit;
}

// ══════════════════════════════════════════════════════════════
// FLUXO NORMAL — delegar para inbound_handler.php (igual webhook.php)
// ══════════════════════════════════════════════════════════════

tg2_log("ROUTE_NORMAL", $msg_text);

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

tg2_log("ROUTER_RESPONSE", ["http" => $resp_http, "body_len" => strlen($resp_body)]);

// Extrair reply_text
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

$sendResult = telegram_send_text($chat_id, trim((string)$reply));
tg2_log("SEND_RESPONSE", $sendResult);

http_response_code(200);
echo "ok";
exit;

// ══════════════════════════════════════════════════════════════
// FUNÇÕES AUXILIARES
// ══════════════════════════════════════════════════════════════

/**
 * Envia chat action (typing, upload_voice, etc)
 */
function _tg2_send_chat_action(string $chat_id, string $action): void {
    $cfg = require __DIR__ . "/../_secrets/telegram_config.php";
    $token = $cfg["bot_token"] ?? "";
    if (!$token || !$chat_id) return;

    $url = "https://api.telegram.org/bot{$token}/sendChatAction";
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ["Content-Type: application/json"],
        CURLOPT_POSTFIELDS     => json_encode(["chat_id" => $chat_id, "action" => $action]),
        CURLOPT_TIMEOUT        => 5,
    ]);
    curl_exec($ch);
    curl_close($ch);
}

/**
 * Divide mensagem longa em partes menores respeitando quebras de linha.
 */
function _tg2_split_message(string $text, int $max_len = 4000): array {
    $partes = [];
    $lines = explode("\n", $text);
    $current = "";

    foreach ($lines as $line) {
        if (mb_strlen($current . "\n" . $line) > $max_len && $current !== "") {
            $partes[] = trim($current);
            $current = $line;
        } else {
            $current .= ($current === "" ? "" : "\n") . $line;
        }
    }

    if (trim($current) !== "") {
        $partes[] = trim($current);
    }

    return $partes ?: [$text];
}

// ======================================================
// FIM ANCHOR: TELEGRAM_WEBHOOK_V2
// ======================================================

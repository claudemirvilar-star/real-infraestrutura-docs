<?php
// ======================================================
// ANCHOR: TELEGRAM_SEND_V1
// Arquivo: /public_html/app/telegram/telegram_send.php
// Objetivo: enviar mensagem via Telegram Bot API
// ======================================================

function telegram_send_text($chat_id, $text, $parse_mode = "Markdown") {
    $cfg = require __DIR__ . "/../_secrets/telegram_config.php";
    $token = $cfg["bot_token"] ?? "";

    if (!$token || !$chat_id || $text === "") {
        return ["ok" => false, "msg" => "Parâmetros inválidos"];
    }

    $url = "https://api.telegram.org/bot{$token}/sendMessage";

    $payload = [
        "chat_id"    => $chat_id,
        "text"       => $text,
        "parse_mode" => $parse_mode,
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ["Content-Type: application/json"],
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_TIMEOUT        => 20,
    ]);

    $body = curl_exec($ch);
    $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($err) {
        return ["ok" => false, "msg" => "cURL erro: $err", "http" => $http];
    }

    $json = json_decode($body, true);
    return [
        "ok"   => ($json["ok"] ?? false),
        "http" => $http,
        "json" => $json,
    ];
}

// ======================================================
// FIM ANCHOR: TELEGRAM_SEND_V1
// ======================================================

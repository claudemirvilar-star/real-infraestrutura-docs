<?php
// ======================================================
// ANCHOR: WHATSAPP_INBOUND_HANDLER_BRIDGE_V3_FATALSAFE
// Arquivo: /public_html/app/whatsapp/inbound_handler.php
// Objetivo:
// - Handler simples (ponte) -> inbound_router.php
// - Nunca mais 500 mudo: captura fatal e devolve JSON
// ======================================================

header("Content-Type: application/json; charset=utf-8");

$__LOG_DIR  = __DIR__ . "/_logs";
$__LOG_FILE = $__LOG_DIR . "/whatsapp_inbound_errors.log";

if (!is_dir($__LOG_DIR)) {
  @mkdir($__LOG_DIR, 0755, true);
}

@ini_set("log_errors", "1");
@ini_set("error_log", $__LOG_FILE);

$__debug = (isset($_GET["debug"]) && $_GET["debug"] === "1");
if ($__debug) {
  @ini_set("display_errors", "1");
  @ini_set("display_startup_errors", "1");
  error_reporting(E_ALL);
} else {
  error_reporting(E_ALL);
}

// ======================================================
// ANCHOR: WHATSAPP_INBOUND_FATAL_TRAP_V2_VERBOSE
// ======================================================
register_shutdown_function(function() use ($__LOG_FILE, $__debug) {
  $err = error_get_last();
  if (!$err) return;

  $isFatal = in_array($err["type"], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true);
  if (!$isFatal) return;

  // grava no log (sempre)
  $line = "[".date("c")."] FATAL type=".$err["type"]." file=".$err["file"]." line=".$err["line"]." msg=".$err["message"]."\n";
  @file_put_contents($__LOG_FILE, $line, FILE_APPEND);

  // resposta JSON útil (sem ficar 500 mudo)
  if (!headers_sent()) {
    http_response_code(500);

    // ✅ AQUI ESTÁ A MUDANÇA:
    // Sempre devolve arquivo/linha/mensagem do fatal
    // (isso te diz exatamente onde está o erro, geralmente inbound_router.php)
    echo json_encode([
      "ok" => false,
      "msg" => "Erro interno no inbound_handler (fatal).",
      "http_status" => 500,
      "tool" => "whatsapp.inbound",
      "data" => [
        "fatal_type" => (int)($err["type"] ?? 0),
        "fatal_file" => (string)($err["file"] ?? ""),
        "fatal_line" => (int)($err["line"] ?? 0),
        "fatal_msg"  => (string)($err["message"] ?? ""),
        "hint" => "O erro quase sempre está no inbound_router.php. Veja fatal_file/fatal_line."
      ],
      "errors" => ["fatal_error"]
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  }
});
// ======================================================
// FIM ANCHOR: WHATSAPP_INBOUND_FATAL_TRAP_V2_VERBOSE
// ======================================================

if (isset($_GET["__whoami"]) && $_GET["__whoami"] === "1") {
  echo json_encode([
    "ok" => true,
    "whoami" => "inbound_handler.php",
    "path" => __FILE__,
    "router" => __DIR__ . "/inbound_router.php",
    "time" => date("c"),
  ], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
  exit;
}

$routerPath = __DIR__ . "/inbound_router.php";
if (!file_exists($routerPath)) {
  http_response_code(500);
  echo json_encode([
    "ok" => false,
    "msg" => "Router não encontrado: inbound_router.php",
    "http_status" => 500,
    "tool" => "whatsapp.inbound",
    "data" => ["expected" => $routerPath],
    "errors" => ["router_missing"]
  ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

ob_start();
require $routerPath;
$out = ob_get_clean();

$json = json_decode($out, true);

if(isset($json["data"]["reply_text"])){

  $reply = $json["data"]["reply_text"];

  file_put_contents(
    __DIR__."/_logs/whatsapp_debug.log",
    "[".date("c")."] RESP: ".$reply."\n",
    FILE_APPEND
  );

}

echo $out;
exit;

// fallback (não deveria chegar)
http_response_code(500);
echo json_encode([
  "ok" => false,
  "msg" => "Router não encerrou a execução (inesperado).",
  "http_status" => 500,
  "tool" => "whatsapp.inbound",
  "data" => [],
  "errors" => ["router_no_exit"]
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
exit;

// ======================================================
// FIM ANCHOR: WHATSAPP_INBOUND_HANDLER_BRIDGE_V3_FATALSAFE
// ======================================================
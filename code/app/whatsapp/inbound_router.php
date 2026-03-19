<?php
// ======================================================
// META WEBHOOK VERIFICATION (GET)
// ======================================================
if ($_SERVER["REQUEST_METHOD"] === "GET") {
    $hub_mode         = $_GET["hub_mode"]         ?? $_GET["hub.mode"]         ?? "";
    $hub_verify_token = $_GET["hub_verify_token"]  ?? $_GET["hub.verify_token"]  ?? "";
    $hub_challenge    = $_GET["hub_challenge"]     ?? $_GET["hub.challenge"]     ?? "";

    if ($hub_mode === "subscribe" && $hub_verify_token === "DONNA_VERIFY_2026_REAL") {
        http_response_code(200);
        header("Content-Type: text/plain; charset=utf-8");
        echo $hub_challenge;
        exit;
    }

    http_response_code(403);
    header("Content-Type: text/plain; charset=utf-8");
    echo "forbidden";
    exit;
}

// ======================================================
// DONA WHATSAPP ROUTER - V6 GOVERNANÇA
// Integra governança de bloqueio/desbloqueio
// ======================================================

header("Content-Type: application/json; charset=utf-8");

error_reporting(E_ALL);
ini_set("display_errors", 0);

require_once __DIR__ . "/../frota/normalizar_telefone.php";
require_once __DIR__ . "/../frota/governanca_validar.php";
require_once __DIR__ . "/donna_brain_claude.php";
require_once __DIR__ . "/substituir_handler.php";

// ------------------------------------------------------
// HELPERS
// ------------------------------------------------------

function j($arr, $http = 200) {
    http_response_code($http);
    echo json_encode($arr, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function read_json_body() {
    $raw = file_get_contents("php://input");
    $json = json_decode($raw, true);
    if (!is_array($json)) $json = [];
    return [$raw, $json];
}

function maps_link($lat, $lon) {
    if (!is_numeric($lat) || !is_numeric($lon)) return "";
    return "https://www.google.com/maps?q=" . $lat . "," . $lon;
}

// ------------------------------------------------------
// PENDÊNCIAS
// ------------------------------------------------------

function pending_file() {
    return __DIR__ . "/pending_actions.json";
}

function pending_load() {
    $f = pending_file();
    if (!file_exists($f)) return [];
    $c = file_get_contents($f);
    $j = json_decode($c, true);
    if (!is_array($j)) $j = [];
    return $j;
}

function pending_save($from, $data) {
    $all = pending_load();
    $all[$from] = $data;
    file_put_contents(pending_file(), json_encode($all));
}

function pending_get($from) {
    $all = pending_load();
    return $all[$from] ?? null;
}

function pending_clear($from) {
    $all = pending_load();
    unset($all[$from]);
    file_put_contents(pending_file(), json_encode($all));
}

// ------------------------------------------------------
// MCP CALL
// ------------------------------------------------------

function mcp_call($tool, $args, $ctx) {
    $url = "https://globalsinalizacao.online/app/mcp/call.php";

    $payload = [
        "tool"       => $tool,
        "args"       => $args,
        "source"     => "donna",
        "actor"      => $ctx["source"] ?? "",
        "user_id"    => $ctx["user_id"] ?? 0,
        "empresa_id" => $ctx["empresa_id"] ?? 0,
        "role"       => $ctx["role"] ?? "USER",
        "telefone"   => $ctx["telefone"] ?? "",
        "from"       => $ctx["from"] ?? "",
        "origem"     => "WHATSAPP",
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ["Content-Type: application/json"],
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_TIMEOUT        => 50,
    ]);

    $resp = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $j = json_decode($resp, true);
    return ["http" => $http, "json" => $j];
}


// ------------------------------------------------------
// ENRIQUECIMENTO Tab_frota
// ------------------------------------------------------

function frota_buscar_dados(string $placaNorm): array {
    global $conn;
    if (!$conn) {
        require_once __DIR__ . "/../db_conexao.php";
    }
    $db = $conn ?? null;
    if (!$db) return [];

    $stmt = $db->prepare(
        "SELECT apelido, placa, vinculo_operacional, cliente_locacao_atual, "
        . "responsavel_atual_nome, responsavel_atual_telefone, motorista_atual_nome, motorista_atual_telefone, encarregado_atual_nome, encarregado_atual_telefone, status_bloqueio "
        . "FROM Tab_frota "
        . "WHERE REPLACE(REPLACE(REPLACE(placa, '-', ''), ' ', ''), '.', '') = ? "
        . "LIMIT 1"
    );
    if (!$stmt) return [];
    $stmt->bind_param("s", $placaNorm);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();
    if (!$row) return [];

    return [
        "apelido"              => $row["apelido"] ?: null,
        "vinculo"              => $row["vinculo_operacional"] ?: null,
        "cliente"              => $row["cliente_locacao_atual"] ?: null,
        "responsavel"          => $row["responsavel_atual_nome"] ?: null,
        "telefone_responsavel" => $row["responsavel_atual_telefone"] ?: null,
        "motorista_nome"        => $row["motorista_atual_nome"] ?: null,
        "motorista_telefone"    => $row["motorista_atual_telefone"] ?: null,
        "encarregado_nome"      => $row["encarregado_atual_nome"] ?: null,
        "encarregado_telefone"  => $row["encarregado_atual_telefone"] ?: null,
        "status_bloqueio"      => $row["status_bloqueio"] ?: "LIVRE",
    ];
}

function frota_buscar_por_apelido(string $apelido): ?array {
    global $conn;
    if (!$conn) {
        require_once __DIR__ . "/../db_conexao.php";
    }
    $db = $conn ?? null;
    if (!$db) return null;

    // 1. Busca exata (case-insensitive)
    $stmt = $db->prepare(
        "SELECT apelido, placa FROM Tab_frota WHERE LOWER(apelido) = LOWER(?) LIMIT 1"
    );
    if (!$stmt) return null;
    $stmt->bind_param("s", $apelido);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();
    if ($row) return ["apelido" => $row["apelido"], "placa" => $row["placa"]];

    // 2. Normalizar: "thor27" -> "THOR 27", "thor 27" -> "THOR 27"
    //    Insere espaco entre letras e numeros (ex: THOR27 -> THOR 27)
    $normalizado = preg_replace('/([A-Za-z])([0-9])/', '$1 $2', trim($apelido));
    $normalizado = preg_replace('/([0-9])([A-Za-z])/', '$1 $2', $normalizado);
    $normalizado = preg_replace('/\s+/', ' ', $normalizado); // colapsar espacos

    if (strtolower($normalizado) !== strtolower($apelido)) {
        $stmt = $db->prepare(
            "SELECT apelido, placa FROM Tab_frota WHERE LOWER(apelido) = LOWER(?) LIMIT 1"
        );
        if (!$stmt) return null;
        $stmt->bind_param("s", $normalizado);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        $stmt->close();
        if ($row) return ["apelido" => $row["apelido"], "placa" => $row["placa"]];
    }

    // 3. Busca parcial LIKE (fallback)
    $like = '%' . $db->real_escape_string($apelido) . '%';
    $stmt = $db->prepare(
        "SELECT apelido, placa FROM Tab_frota WHERE LOWER(apelido) LIKE LOWER(?) LIMIT 1"
    );
    if (!$stmt) return null;
    $stmt->bind_param("s", $like);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();
    if ($row) return ["apelido" => $row["apelido"], "placa" => $row["placa"]];

    return null;
}

function resolver_placa_ou_apelido(string $input): array {
    // Se parece placa (3 letras + 4 alfanum), usa direto
    $clean = preg_replace("/[^A-Z0-9]/", "", strtoupper($input));
    if (preg_match('/^[A-Z]{3}[0-9A-Z]{4}$/', $clean)) {
        return ["placa" => strtoupper($input), "apelido" => null, "encontrado" => true];
    }
    // Senão, busca por apelido
    $r = frota_buscar_por_apelido(trim($input));
    if ($r) {
        return ["placa" => $r["placa"], "apelido" => $r["apelido"], "encontrado" => true];
    }
    return ["placa" => strtoupper($input), "apelido" => null, "encontrado" => false];
}

function fmt_brasilia(?string $dt_raw): string {
    if (!$dt_raw || $dt_raw === "-") return "—";
    try {
        $raw = str_replace("Z", "+00:00", trim($dt_raw));
        $dt = new DateTimeImmutable($raw);
        $br = new DateTimeZone("America/Sao_Paulo");
        return $dt->setTimezone($br)->format("d-m-Y H:i:s");
    } catch (Exception $e) {
        return (string)$dt_raw;
    }
}

// ------------------------------------------------------
// ENTRADA
// ------------------------------------------------------

list($raw, $payload) = read_json_body();

$args = $payload["args"] ?? [];

$from = $args["from"] ?? "";
$text = $args["text"] ?? "";

if (!$from || !$text) {
    j(["ok" => false, "msg" => "payload inválido"], 400);
}

$telefone = normalizar_telefone($from);
$source = "whatsapp:wa_id:" . $from;

$ctx = [
    "source"     => $source,
    "user_id"    => 0,
    "empresa_id" => 0,
    "role"       => "ADM",
    "telefone"   => $telefone,
    "from"       => $from,
];

// ------------------------------------------------------
// COMANDOS
// ------------------------------------------------------

$cmd = strtolower(trim($text));
// Normalizar acentos para roteamento
$cmd = strtr($cmd, [
    "á"=>"a","à"=>"a","ã"=>"a","â"=>"a",
    "é"=>"e","è"=>"e","ê"=>"e",
    "í"=>"i","ì"=>"i","î"=>"i",
    "ó"=>"o","ò"=>"o","õ"=>"o","ô"=>"o",
    "ú"=>"u","ù"=>"u","û"=>"u",
    "ç"=>"c"
]);
$reply = "";

// ------------------------------------------------------
// INTERCEPTOR: PENDING DE SUBSTITUIÇÃO (multi-step)
// ------------------------------------------------------

$_pend_sub = pending_get($from);
if ($_pend_sub && ($_pend_sub["acao"] ?? "") === "substituir") {
    // Verificar timeout
    if (substituir_timeout_check($_pend_sub)) {
        pending_clear($from);
        // Continuar processamento normal (pending expirou)
        $_pend_sub = null;
    }
}

// Comandos que devem ser processados normalmente mesmo com pending ativo
$_escape_cmds = ["cancelar", "sim", "ajuda", "menu", "frota", "status", "localizar", "bloquear", "desbloquear"];
$_is_escape = false;
foreach ($_escape_cmds as $_esc) {
    if (strpos($cmd, $_esc) === 0) { $_is_escape = true; break; }
}

if ($_pend_sub && ($_pend_sub["acao"] ?? "") === "substituir" && !$_is_escape) {
    $_step = $_pend_sub["step"] ?? "";
    $pend_sub_tipo = $_pend_sub["tipo"] ?? "motorista";
    $_contact_name = $args["contact_name"] ?? null;
    $_contact_phone = $args["contact_phone"] ?? null;

    if ($_step === "aguardando_contato") {
        if ($_contact_name && $_contact_phone) {
            // Flow A: contato compartilhado
            $reply = substituir_contato($from, $_contact_name, $_contact_phone);
        } else {
            // Flow B: nome digitado
            // Se parece telefone, não aceitar como nome
            $_text_digits = preg_replace("/[^0-9]/", "", $text);
            if (strlen($_text_digits) >= 8 && strlen($_text_digits) <= 15 && strlen($_text_digits) > (strlen($text) / 2)) {
                $reply = "❌ Parece um telefone. Primeiro digite o *nome* do novo " . $pend_sub_tipo . ".";
            } elseif (strtolower(trim($text)) === "__contato_compartilhado__") {
                // Contato compartilhado mas sem contact_name/phone (fallback)
                $reply = "❌ Não consegui ler o contato. Tente novamente ou digite o *nome* do novo " . $pend_sub_tipo . ".";
            } else {
                $reply = substituir_nome($from, $text);
            }
        }
    } elseif ($_step === "aguardando_telefone") {
        $reply = substituir_telefone($from, $text);
    } elseif ($_step === "aguardando_confirmacao") {
        // Se não é SIM nem cancelar, avisar
        $reply = "Responda *SIM* para confirmar ou *cancelar* para desistir.";
    }

    if ($reply !== "") {
        j(["ok" => true, "data" => ["reply_text" => $reply]]);
    }
}

// ------------------------------------------------------
// CANCELAR (limpa pending ativo)
// ------------------------------------------------------

if ($cmd === "cancelar") {
    $_pend_cancel = pending_get($from);
    if ($_pend_cancel) {
        pending_clear($from);
        $reply = "❌ Operação cancelada.";
    } else {
        $reply = "Nenhuma operação pendente para cancelar.";
    }
    j(["ok" => true, "data" => ["reply_text" => $reply]]);
}
// ------------------------------------------------------
// AJUDA
// ------------------------------------------------------

if ($cmd == "ajuda" || $cmd == "menu") {
    $reply =
        "🤖 *Donna — Comandos*\n\n" .
        "*Frota e Veículos:*\n" .
        "frota — toda a frota\n" .
        "frota real — só veículos Real\n" .
        "frota bth — só veículos locados\n" .
        "status <placa ou apelido>\n" .
        "localizar <placa ou apelido>\n\n" .
        "*Bloqueio/Desbloqueio:*\n" .
        "bloquear <placa ou apelido>\n" .
        "desbloquear <placa ou apelido>\n" .
        "relatorio ceabs\n\n" .
        "*Alertas:*\n" .
        "ativar alertas ceabs\n" .
        "desativar alertas ceabs\n" .
        "ativar alertas rh\n" .
        "desativar alertas rh\n\n" .
        "*Substituição:*\n" .
        "substituir motorista <placa ou apelido>\n" .
        "substituir encarregado <placa ou apelido>\n" .
        "cancelar — cancela operação pendente";
}

// ------------------------------------------------------
// FROTA
// ------------------------------------------------------

elseif (preg_match('/^frota(\s+(real|bth))?$/i', $cmd, $_mFrota)) {
    $_filtro_frota = strtolower(trim($_mFrota[2] ?? ""));
    $gov = governanca_validar_frota_completa($telefone, "WHATSAPP");
    if (!$gov["autorizado"]) {
        $reply = "⛔ " . $gov["motivo"];
        j(["ok" => true, "data" => ["reply_text" => $reply]]);
    }
    $r = mcp_call("ceabs.frota.status", ["target" => "ALL"], $ctx);

    if ($r["json"]["ok"] ?? false) {
        $list = $r["json"]["data"];
        $_titulo_frota = "🚚 Frota";
        if ($_filtro_frota === "real") $_titulo_frota = "🚚 Frota REAL";
        elseif ($_filtro_frota === "bth") $_titulo_frota = "🚚 Frota Locados (BTH)";
        $out = $_titulo_frota . "\n\n";
        foreach ($list as $v) {
            $placa = $v["placa"] ?? "";
            // Filtrar por vinculo se solicitado
            if ($_filtro_frota !== "") {
                $placaNormF = preg_replace("/[^A-Z0-9]/", "", strtoupper($placa));
                $metaF = frota_buscar_dados($placaNormF);
                $vincF = strtoupper($metaF["vinculo"] ?? "");
                if ($_filtro_frota === "real" && $vincF !== "REAL") continue;
                if ($_filtro_frota === "bth" && $vincF !== "LOCATARIA") continue;
            }
            $lat = $v["latitude"] ?? "";
            $lon = $v["longitude"] ?? "";
            $link = maps_link($lat, $lon);
            $placaNorm = preg_replace("/[^A-Z0-9]/", "", strtoupper($placa));
            $meta = frota_buscar_dados($placaNorm);
            $apelido = $meta["apelido"] ?? null;
            $label = $apelido ? ($apelido . " (" . $placa . ")") : $placa;
            $bloqueio = $meta["status_bloqueio"] ?? "LIVRE";
            $mot_nome = $meta["motorista_nome"] ?? null;
            $mot_tel  = $meta["motorista_telefone"] ?? null;
            $enc_nome = $meta["encarregado_nome"] ?? null;
            $enc_tel  = $meta["encarregado_telefone"] ?? null;

            $fmt_tel = function($tel) {
                if (!$tel) return null;
                $t = preg_replace("/[^0-9]/", "", $tel);
                if (strlen($t) >= 12 && substr($t, 0, 2) === "55") {
                    $t = substr($t, 2);
                }
                return "0" . $t;
            };

            $mot_tel_fmt = $fmt_tel($mot_tel);
            $enc_tel_fmt = $fmt_tel($enc_tel);

            $municipio = $v["municipio"] ?? null;
            $uf = $v["uf"] ?? null;
            $cidade = ($municipio && $uf) ? ($municipio . "/" . $uf) : null;

            $status_icon = ($bloqueio === "LIVRE") ? "✅" : "🔒";
            $out .= "🚚 *" . $label . "* " . $status_icon . "\n";
            if ($mot_nome) $out .= "👤 Mot: " . $mot_nome . ($mot_tel_fmt ? " | " . $mot_tel_fmt : "") . "\n";
            if ($enc_nome) $out .= "👷 Enc: " . $enc_nome . ($enc_tel_fmt ? " | " . $enc_tel_fmt : "") . "\n";
            if ($cidade) $out .= "📍 " . $cidade . "\n";
            if ($link) $out .= "🗺️ " . $link . "\n";
            $out .= "\n";
        }

        // Resumo final: ativos, offline, problemas
        $total_ceabs = count($list);
        $total_ok = 0;
        $total_atencao = 0;
        $total_critico = 0;
        $problemas = [];
        $agora = new DateTime('now', new DateTimeZone('America/Sao_Paulo'));

        foreach ($list as $v2) {
            $dt_evt = $v2["data_evento"] ?? null;
            if (!$dt_evt) { $total_critico++; continue; }
            try {
                $evt = new DateTime(str_replace("Z", "", $dt_evt));
                $diff_h = ($agora->getTimestamp() - $evt->getTimestamp()) / 3600;
            } catch (Exception $e) { $diff_h = 9999; }

            if ($diff_h < 24) {
                $total_ok++;
            } elseif ($diff_h < 72) {
                $total_atencao++;
                $p2 = $v2["placa"] ?? "?";
                $pN2 = preg_replace("/[^A-Z0-9]/", "", strtoupper($p2));
                $m2 = frota_buscar_dados($pN2);
                $ap2 = $m2["apelido"] ?? null;
                $lb2 = $ap2 ? ($ap2 . " (" . $p2 . ")") : $p2;
                $problemas[] = "⚠️ " . $lb2 . " — sem sinal há " . (int)$diff_h . "h";
            } else {
                $total_critico++;
                $p2 = $v2["placa"] ?? "?";
                $pN2 = preg_replace("/[^A-Z0-9]/", "", strtoupper($p2));
                $m2 = frota_buscar_dados($pN2);
                $ap2 = $m2["apelido"] ?? null;
                $lb2 = $ap2 ? ($ap2 . " (" . $p2 . ")") : $p2;
                $dias = (int)($diff_h / 24);
                $problemas[] = "🔴 " . $lb2 . " — sem sinal há " . $dias . " dias";
            }
        }

        $out .= "─────────────────────\n";
        $out .= "📊 *Resumo CEABS*\n";
        $out .= "✅ Ativos: " . $total_ok . "/" . $total_ceabs . "\n";
        if ($total_atencao > 0) $out .= "⚠️ Atenção: " . $total_atencao . "\n";
        if ($total_critico > 0) $out .= "🔴 Crítico: " . $total_critico . "\n";
        if (count($problemas) > 0) {
            $out .= "\n🛠️ *Manutenção necessária:*\n";
            foreach ($problemas as $prob) {
                $out .= $prob . "\n";
            }
        }

        $reply = $out;
    } else {
        $reply = "Erro consultando frota";
    }
}

// ------------------------------------------------------
// CONFIRMAÇÃO (SIM)
// ------------------------------------------------------

elseif ($cmd == "sim") {
    $pend = pending_get($from);

    if (!$pend) {
        $reply = "Nada para confirmar";
    } else {
        $acao = $pend["acao"];
        $placa = $pend["placa"];
        $pedido = $pend["pedido_id"];

        // Buscar apelido do veículo
        $placaNorm = preg_replace("/[^A-Z0-9]/", "", strtoupper($placa));
        $meta = frota_buscar_dados($placaNorm);
        $apelido = $meta["apelido"] ?? null;
        $label = $apelido ? ($apelido . " (" . $placa . ")") : $placa;

        if ($acao == "bloquear") {
            $r = mcp_call("ceabs.veiculo.bloquear", [
                "placa"     => $placa,
                "pedido_id" => $pedido,
                "telefone"  => $telefone,
                "origem"    => "WHATSAPP",
            ], $ctx);

            $json = $r["json"] ?? [];
            $ok = $json["ok"] ?? false;
            $httpStatus = $json["http_status"] ?? $r["http"];

            if ($ok === false && $httpStatus === 403) {
                $reply = "⛔ Bloqueio NEGADO para " . $label . "\n" . ($json["msg"] ?? "Sem permissão");
            } elseif ($ok) {
                $reply = "✅ Bloqueio executado com sucesso!\n🚛 " . $label . "\n🔒 Veículo BLOQUEADO";
            } else {
                $msgErro = $json["msg"] ?? "Erro desconhecido";
                $reply = "❌ Falha no bloqueio de " . $label . "\nErro: " . $msgErro . "\nTente novamente ou contate o suporte.";
            }
        } elseif ($acao == "desbloquear") {
            $r = mcp_call("ceabs.veiculo.desbloquear", [
                "placa"     => $placa,
                "pedido_id" => $pedido,
                "telefone"  => $telefone,
                "origem"    => "WHATSAPP",
            ], $ctx);

            $json = $r["json"] ?? [];
            $ok = $json["ok"] ?? false;
            $httpStatus = $json["http_status"] ?? $r["http"];

            if ($ok === false && $httpStatus === 403) {
                $reply = "⛔ Desbloqueio NEGADO para " . $label . "\n" . ($json["msg"] ?? "Sem permissão");
            } elseif ($ok) {
                $reply = "✅ Desbloqueio executado com sucesso!\n🚛 " . $label . "\n🔓 Veículo LIBERADO";
            } else {
                $msgErro = $json["msg"] ?? "Erro desconhecido";
                $reply = "❌ Falha no desbloqueio de " . $label . "\nErro: " . $msgErro . "\nTente novamente ou contate o suporte.";
            }
        } elseif ($acao === "substituir") {
            if (($pend["step"] ?? "") !== "aguardando_confirmacao") {
                $reply = "⚠️ Substituição incompleta. Use *cancelar* e comece novamente.";
            } else {
                $reply = substituir_confirmar($from);
            }
        }

        if ($acao !== "substituir") {
            pending_clear($from);
        }
    }
}

// ------------------------------------------------------
// ATIVAR ALERTAS RH
// ------------------------------------------------------

elseif (preg_match('/^ativar\s+alertas?\s+rh/i', $cmd)) {
    $_nome = null;
    if (isset($conn) || @require_once(__DIR__ . "/../db_conexao.php")) {
        if (isset($conn) && $conn instanceof mysqli) {
            $_st = $conn->prepare("SELECT nome_pessoa FROM Tab_frota_autorizacoes WHERE telefone = ? LIMIT 1");
            if ($_st) { $_st->bind_param('s', $telefone); $_st->execute(); $_r = $_st->get_result(); $_row = $_r ? $_r->fetch_assoc() : null; $_nome = $_row['nome_pessoa'] ?? null; $_st->close(); }

            $_st2 = $conn->prepare("INSERT INTO Tab_alertas_rh (telefone, nome, ativo) VALUES (?, ?, 1) ON DUPLICATE KEY UPDATE ativo = 1, nome = VALUES(nome)");
            if ($_st2) { $_st2->bind_param('ss', $telefone, $_nome); $_st2->execute(); $_st2->close(); }
        }
    }
    $reply = "✅ Alertas RH *ATIVADOS*\n\nVocê receberá o resumo de cobranças de ponto (mesmo relatório enviado ao RH).\n\nPara desativar: digite *desativar alertas rh*";
}

// ------------------------------------------------------
// DESATIVAR ALERTAS RH
// ------------------------------------------------------

elseif (preg_match('/^desativar\s+alertas?\s+rh/i', $cmd)) {
    if (isset($conn) || @require_once(__DIR__ . "/../db_conexao.php")) {
        if (isset($conn) && $conn instanceof mysqli) {
            $_st = $conn->prepare("UPDATE Tab_alertas_rh SET ativo = 0 WHERE telefone = ?");
            if ($_st) { $_st->bind_param('s', $telefone); $_st->execute(); $_st->close(); }
        }
    }
    $reply = "🔕 Alertas RH *DESATIVADOS*\n\nVocê não receberá mais o resumo de cobranças.\n\nPara reativar: digite *ativar alertas rh*";
}

// ------------------------------------------------------
// ATIVAR ALERTAS BLOQUEIO
// ------------------------------------------------------

elseif (preg_match('/^ativar\s+alertas?\s+ceabs/i', $cmd)) {
    // Buscar nome do solicitante em Tab_frota_autorizacoes
    $_nome = null;
    if (isset($conn) || @require_once(__DIR__ . "/../db_conexao.php")) {
        if (isset($conn) && $conn instanceof mysqli) {
            $_st = $conn->prepare("SELECT nome_pessoa FROM Tab_frota_autorizacoes WHERE telefone = ? LIMIT 1");
            if ($_st) { $_st->bind_param('s', $telefone); $_st->execute(); $_r = $_st->get_result(); $_row = $_r ? $_r->fetch_assoc() : null; $_nome = $_row['nome_pessoa'] ?? null; $_st->close(); }

            $_st2 = $conn->prepare("INSERT INTO Tab_alertas_bloqueio (telefone, nome, ativo) VALUES (?, ?, 1) ON DUPLICATE KEY UPDATE ativo = 1, nome = VALUES(nome)");
            if ($_st2) { $_st2->bind_param('ss', $telefone, $_nome); $_st2->execute(); $_st2->close(); }
        }
    }
    $reply = "✅ Alertas de bloqueio/desbloqueio *ATIVADOS*\n\nVocê receberá notificações sempre que um veículo for bloqueado ou desbloqueado.\n\nPara desativar: digite *desativar alertas ceabs*";
}

// ------------------------------------------------------
// DESATIVAR ALERTAS BLOQUEIO
// ------------------------------------------------------

elseif (preg_match('/^desativar\s+alertas?\s+ceabs/i', $cmd)) {
    if (isset($conn) || @require_once(__DIR__ . "/../db_conexao.php")) {
        if (isset($conn) && $conn instanceof mysqli) {
            $_st = $conn->prepare("UPDATE Tab_alertas_bloqueio SET ativo = 0 WHERE telefone = ?");
            if ($_st) { $_st->bind_param('s', $telefone); $_st->execute(); $_st->close(); }
        }
    }
    $reply = "🔕 Alertas de bloqueio/desbloqueio *DESATIVADOS*\n\nVocê não receberá mais notificações.\n\nPara reativar: digite *ativar alertas ceabs*";
}

// ------------------------------------------------------
// RELATÓRIO BLOQUEIO
// ------------------------------------------------------

elseif (preg_match('/^relat.rio\s+ceabs|^status\s+ceabs|^relat.rio\s+bloqueio/i', $cmd)) {
    if (isset($conn) || @require_once(__DIR__ . "/../db_conexao.php")) {
        if (isset($conn) && $conn instanceof mysqli) {
            $_res = $conn->query("SELECT placa, apelido, status_bloqueio, bloqueado_por_nome, bloqueado_em, motivo_bloqueio FROM Tab_frota ORDER BY status_bloqueio DESC, placa ASC");
            $bloqueados = [];
            $livres = 0;
            while ($_row = $_res->fetch_assoc()) {
                if ($_row['status_bloqueio'] !== 'LIVRE' && $_row['status_bloqueio'] !== null && $_row['status_bloqueio'] !== '') {
                    $bloqueados[] = $_row;
                } else {
                    $livres++;
                }
            }

            if (count($bloqueados) === 0) {
                $reply = "📊 *RELATÓRIO DE BLOQUEIO*\n\n✅ Nenhum veículo bloqueado no momento.\n🚗 Total de veículos livres: " . $livres;
            } else {
                $reply = "📊 *RELATÓRIO DE BLOQUEIO*\n\n🔒 *Veículos bloqueados:* " . count($bloqueados) . "\n✅ *Veículos livres:* " . $livres . "\n";
                foreach ($bloqueados as $b) {
                    $_label = $b['apelido'] ? ($b['apelido'] . ' (' . $b['placa'] . ')') : $b['placa'];
                    $_dt = $b['bloqueado_em'] ? (new DateTime($b['bloqueado_em'], new DateTimeZone('America/Sao_Paulo')))->format('d/m/Y H:i') : '—';
                    $reply .= "\n🔒 *" . $_label . "*\n";
                    $reply .= "   👤 " . ($b['bloqueado_por_nome'] ?: '—') . "\n";
                    $reply .= "   🕐 " . $_dt . "\n";
                    if ($b['motivo_bloqueio']) $reply .= "   📝 " . $b['motivo_bloqueio'] . "\n";
                }
            }
        } else {
            $reply = "❌ Erro ao consultar banco de dados";
        }
    }
}


// ------------------------------------------------------
// BLOQUEAR
// ------------------------------------------------------

elseif (strpos($cmd, "bloquear") === 0) {
    $input_raw = trim(str_replace("bloquear", "", $cmd));
    $resolved = resolver_placa_ou_apelido($input_raw);

    if (!$resolved["encontrado"]) {
        $reply = "❌ Veículo não encontrado: " . strtoupper($input_raw) . "\nDigite a placa ou o apelido exato (ex: THOR 23)";
    } else {
        $placa = $resolved["placa"];
        $pedido = time();

        $placaNorm = preg_replace("/[^A-Z0-9]/", "", strtoupper($placa));
        $meta = frota_buscar_dados($placaNorm);
        $apelido = $resolved["apelido"] ?? $meta["apelido"] ?? null;
        $label = $apelido ? ($apelido . " (" . $placa . ")") : $placa;

        pending_save($from, [
            "acao"      => "bloquear",
            "placa"     => $placa,
            "pedido_id" => $pedido,
        ]);

        $reply =
            "🔒 Confirma BLOQUEIO?\n" .
            "🚛 " . $label . "\n" .
            "📋 Pedido " . $pedido . "\n\n" .
            "Responda SIM para confirmar";
    }
}

// ------------------------------------------------------
// DESBLOQUEAR
// ------------------------------------------------------

elseif (strpos($cmd, "desbloquear") === 0) {
    $input_raw = trim(str_replace("desbloquear", "", $cmd));
    $resolved = resolver_placa_ou_apelido($input_raw);

    if (!$resolved["encontrado"]) {
        $reply = "❌ Veículo não encontrado: " . strtoupper($input_raw) . "\nDigite a placa ou o apelido exato (ex: THOR 23)";
    } else {
        $placa = $resolved["placa"];
        $pedido = time();

        $placaNorm = preg_replace("/[^A-Z0-9]/", "", strtoupper($placa));
        $meta = frota_buscar_dados($placaNorm);
        $apelido = $resolved["apelido"] ?? $meta["apelido"] ?? null;
        $label = $apelido ? ($apelido . " (" . $placa . ")") : $placa;

        pending_save($from, [
            "acao"      => "desbloquear",
            "placa"     => $placa,
            "pedido_id" => $pedido,
        ]);

        $reply =
            "🔓 Confirma DESBLOQUEIO?\n" .
            "🚛 " . $label . "\n" .
            "📋 Pedido " . $pedido . "\n\n" .
            "Responda SIM para confirmar";
    }
}

// ------------------------------------------------------
// STATUS <placa>
// ------------------------------------------------------

elseif (preg_match('/^(status|localizar)\s+(.+)$/i', $cmd, $m)) {
    $input_raw = trim($m[2]);
    $resolved = resolver_placa_ou_apelido($input_raw);

    if (!$resolved["encontrado"]) {
        $reply = "❌ Veículo não encontrado: " . strtoupper($input_raw) . "
Digite a placa ou o apelido exato (ex: THOR 23)";
        j(["ok" => true, "data" => ["reply_text" => $reply]]);
    }

    $placa = $resolved["placa"];
    $gov = governanca_validar($telefone, $placa, "CONSULTAR", "WHATSAPP");
    if (!$gov["autorizado"]) {
        $reply = "⛔ " . $gov["motivo"];
        j(["ok" => true, "data" => ["reply_text" => $reply]]);
    }
    $r = mcp_call("ceabs.veiculo.status", ["placa" => $placa], $ctx);

    if ($r["json"]["ok"] ?? false) {
        $d = $r["json"]["data"] ?? [];
        $lat = $d["latitude"] ?? "";
        $lon = $d["longitude"] ?? "";
        $link = maps_link($lat, $lon);

        // Enriquecer com Tab_frota
        $placaNorm = preg_replace("/[^A-Z0-9]/", "", strtoupper($placa));
        $meta = frota_buscar_dados($placaNorm);

        $apelido  = $meta["apelido"] ?? null;
        $vinculo  = $meta["vinculo"] ?? null;
        $mot_nome = $meta["motorista_nome"] ?? null;
        $mot_tel  = $meta["motorista_telefone"] ?? null;
        $enc_nome = $meta["encarregado_nome"] ?? null;
        $enc_tel  = $meta["encarregado_telefone"] ?? null;
        $cliente   = $meta["cliente"] ?? null;
        $bloqueio  = $meta["status_bloqueio"] ?? null;

        $nd = "—";
        $header = $apelido ? ($apelido . " | " . $placa) : $placa;

        $reply = "📍 " . $header . "\n";
        $reply .= "Vínculo: " . ($vinculo ?: $nd) . "\n";
        if (isset($d["municipio"])) $reply .= "Município: " . $d["municipio"] . "/" . ($d["uf"] ?? "") . "\n";
        $reply .= "Local: " . ($d["logradouro"] ?? $nd) . "\n";
        if ($link) $reply .= "🗺️ " . $link . "\n";
        $ign = isset($d["ignicao"]) ? ($d["ignicao"] ? "ligada" : "desligada") : $nd;
        $reply .= "Ignição: " . $ign . "\n";
        $reply .= "Velocidade: " . ($d["velocidade"] ?? $nd) . " km/h\n";
        $reply .= "Evento: " . fmt_brasilia($d["data_evento"] ?? null) . "\n";
        $reply .= "Motorista: " . ($mot_nome ?: $nd) . (($mot_tel) ? " | Tel: " . $mot_tel : "") . "\n";
        $reply .= "Encarregado: " . ($enc_nome ?: $nd) . (($enc_tel) ? " | Tel: " . $enc_tel : "") . "\n";
        $reply .= "Cliente: " . ($cliente ?: $nd) . "\n";
        $reply .= "Status: " . ($bloqueio ?: $nd) . "\n";
    } else {
        $reply = "Erro consultando " . $placa;
    }
}

// ------------------------------------------------------
// SUBSTITUIR MOTORISTA / ENCARREGADO
// ------------------------------------------------------

elseif (preg_match('/^substituir\s+(motorista|encarregado)\s+(.+)$/i', $cmd, $_mSub)) {
    $_tipo_sub = strtolower(trim($_mSub[1]));
    $_veiculo_sub = trim($_mSub[2]);

    // Validar permissão ADM (escopo GLOBAL)
    $gov_sub = governanca_validar_frota_completa($telefone, "WHATSAPP");
    if (!$gov_sub["autorizado"]) {
        $reply = "⛔ Você não tem permissão para substituir motorista/encarregado.";
    } else {
        $reply = substituir_iniciar($from, $_tipo_sub, $_veiculo_sub, $telefone);
    }
}

// ------------------------------------------------------
// CHECK VIDEO
// ------------------------------------------------------

elseif (strpos($cmd, "check_video") === 0) {
    $video_raw = trim(substr($text, 12));
    $video_id = preg_replace("/[^A-Za-z0-9._-]/", "", $video_raw);

    if (!$video_id) {
        $reply = "Informe o ID do vídeo";
    } else {
        $cmd_exec = "/usr/local/bin/check_video_status " . escapeshellarg($video_id) . " 2>&1";
        $out = shell_exec($cmd_exec);
        $out = trim((string)$out);

        $j2 = json_decode($out, true);

        if (is_array($j2) && isset($j2["status"])) {
            $video_resp = $j2["video"] ?? $video_id;
            $status = $j2["status"] ?? "desconhecido";
            $source_status = $j2["source"] ?? "n/a";
            $reply =
                "🎥 Status do vídeo " . $video_resp . "\n\n" .
                "Status: " . $status . "\n" .
                "Fonte: " . $source_status;
        } elseif ($out === "") {
            $reply = "Vídeo " . $video_id . " consultado, mas sem retorno.";
        } else {
            $reply = "🎥 Status do vídeo " . $video_id . "\n\n" . $out;
        }
    }
}

// ------------------------------------------------------
// DEFAULT
// ------------------------------------------------------

else {
    // ======================================================
    // DONNA BRAIN - CLAUDE IA (linguagem natural)
    // Se nenhum comando fixo bateu, Claude interpreta
    // ======================================================
    $brainCtx = [
        "from" => $from,
        "from_name" => "",
        "is_audio" => ($args["is_audio"] ?? false),
    ];
    $reply = donna_brain_respond($text, $brainCtx);
}

// ------------------------------------------------------

j([
    "ok"   => true,
    "data" => ["reply_text" => $reply],
]);

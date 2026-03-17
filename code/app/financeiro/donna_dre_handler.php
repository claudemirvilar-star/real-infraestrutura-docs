<?php
// ======================================================
// ANCHOR: DONNA_DRE_HANDLER_V1
// Arquivo: /public_html/app/financeiro/donna_dre_handler.php
// Objetivo: Orquestrador DRE — parser linguagem natural + análise + resposta
// Criado: 2026-03-17
// ======================================================

require_once __DIR__ . "/donna_dre_analise.php";

/**
 * Handler principal DRE para a Donna.
 * Interpreta texto em linguagem natural, extrai parâmetros e gera análise.
 *
 * @param string $text    Texto do usuário
 * @param array  $context [from, chat_id, from_name, source, is_audio]
 * @return array [
 *   'reply_text' => string (texto formatado para envio),
 *   'audio_text' => string (texto limpo para TTS),
 *   'ok'         => bool,
 *   'erro'       => string|null,
 * ]
 */
function donna_dre_handle(string $text, array $context = []): array {

    $log_dir = dirname(__DIR__) . "/telegram/runtime";
    if (!is_dir($log_dir)) @mkdir($log_dir, 0755, true);
    $log_file = $log_dir . "/donna_dre_handler.log";

    $ts = date("Y-m-d H:i:s");
    @file_put_contents($log_file, "[$ts] HANDLE text=\"{$text}\"\n", FILE_APPEND);

    // ── Parse linguagem natural → parâmetros ──
    $params = _dre_parse_params($text);

    @file_put_contents($log_file,
        "[$ts] PARAMS " . json_encode($params, JSON_UNESCAPED_UNICODE) . "\n",
        FILE_APPEND
    );

    // ── Chamar motor de análise ──
    $result = donna_dre_analise($params);

    if (!$result['ok']) {
        @file_put_contents($log_file, "[$ts] ERRO_ANALISE: {$result['erro']}\n", FILE_APPEND);
        return [
            'reply_text' => "Não consegui gerar a análise DRE agora.\nErro: {$result['erro']}\n\nTente novamente em instantes.",
            'audio_text' => '',
            'ok'         => false,
            'erro'       => $result['erro'],
        ];
    }

    @file_put_contents($log_file, "[$ts] OK texto_len=" . strlen($result['texto']) . "\n", FILE_APPEND);

    return [
        'reply_text' => $result['texto'],
        'audio_text' => $result['audio_texto'],
        'ok'         => true,
        'erro'       => null,
    ];
}

/**
 * Parser de linguagem natural → parâmetros DRE.
 *
 * Exemplos:
 *   "dre"                          → mês atual, todas empresas, competência
 *   "dre de janeiro a março"       → 2026-01 ~ 2026-03
 *   "dre de fevereiro"             → 2026-02 ~ 2026-02
 *   "dre mês passado"              → mês anterior
 *   "dre último trimestre"         → últimos 3 meses
 *   "dre regime caixa"             → regime caixa
 *   "dre empresa 1146"             → empresa específica
 */
function _dre_parse_params(string $text): array {

    $text_lower = mb_strtolower(trim($text), 'UTF-8');
    $text_norm  = _dre_handler_remove_acentos($text_lower);

    // ── Defaults ──
    $ano_atual   = (int) date('Y');
    $mes_atual   = (int) date('m');
    $periodo_de  = date('Y-m');
    $periodo_ate = date('Y-m');
    $empresa     = 'all';
    $regime      = 'competencia';

    // ── Mapa de meses ──
    $meses = [
        'janeiro' => 1, 'jan' => 1, 'fevereiro' => 2, 'fev' => 2,
        'marco' => 3, 'mar' => 3, 'abril' => 4, 'abr' => 4,
        'maio' => 5, 'mai' => 5, 'junho' => 6, 'jun' => 6,
        'julho' => 7, 'jul' => 7, 'agosto' => 8, 'ago' => 8,
        'setembro' => 9, 'set' => 9, 'outubro' => 10, 'out' => 10,
        'novembro' => 11, 'nov' => 11, 'dezembro' => 12, 'dez' => 12,
    ];

    // ── "<mês> a <mês>" (preposição opcional) ──
    $meses_pattern = implode('|', array_keys($meses));
    if (preg_match("/(?:de\s+|desde\s+)?({$meses_pattern})\s+(?:a|ate)\s+({$meses_pattern})/", $text_norm, $m)) {
        $m1 = $meses[$m[1]] ?? null;
        $m2 = $meses[$m[2]] ?? null;
        if ($m1 && $m2) {
            $periodo_de  = sprintf('%04d-%02d', $ano_atual, $m1);
            $periodo_ate = sprintf('%04d-%02d', $ano_atual, $m2);
        }
    }
    // ── "<mês>" sozinho (preposição opcional) ──
    elseif (preg_match("/(?:de\s+|em\s+)?\\b({$meses_pattern})\\b/", $text_norm, $m)) {
        $m1 = $meses[$m[1]] ?? null;
        if ($m1) {
            $periodo_de  = sprintf('%04d-%02d', $ano_atual, $m1);
            $periodo_ate = $periodo_de;
        }
    }
    // ── Mês numérico: "de 01 a 03", "01/2026 a 03/2026" ──
    elseif (preg_match('/(\d{1,2})\s*(?:a|ate)\s*(\d{1,2})/', $text_norm, $m)) {
        $m1 = (int) $m[1];
        $m2 = (int) $m[2];
        if ($m1 >= 1 && $m1 <= 12 && $m2 >= 1 && $m2 <= 12) {
            $periodo_de  = sprintf('%04d-%02d', $ano_atual, $m1);
            $periodo_ate = sprintf('%04d-%02d', $ano_atual, $m2);
        }
    }
    // ── YYYY-MM a YYYY-MM ──
    elseif (preg_match('/(\d{4}-\d{2})\s*(?:a|ate)\s*(\d{4}-\d{2})/', $text_norm, $m)) {
        $periodo_de  = $m[1];
        $periodo_ate = $m[2];
    }
    // ── "mês passado" / "último mês" ──
    elseif (preg_match('/\b(mes passado|ultimo mes)\b/', $text_norm)) {
        $prev = strtotime('first day of last month');
        $periodo_de  = date('Y-m', $prev);
        $periodo_ate = $periodo_de;
    }
    // ── "último trimestre" / "últimos 3 meses" ──
    elseif (preg_match('/\b(ultimo trimestre|ultimos\s*3\s*mes)\b/', $text_norm)) {
        $periodo_ate = date('Y-m');
        $periodo_de  = date('Y-m', strtotime('-2 months'));
    }
    // ── "este mês" / "mês atual" ──
    elseif (preg_match('/\b(este mes|mes atual)\b/', $text_norm)) {
        $periodo_de  = date('Y-m');
        $periodo_ate = date('Y-m');
    }
    // ── "último semestre" / "últimos 6 meses" ──
    elseif (preg_match('/\b(ultimo semestre|ultimos\s*6\s*mes)\b/', $text_norm)) {
        $periodo_ate = date('Y-m');
        $periodo_de  = date('Y-m', strtotime('-5 months'));
    }
    // ── Se não detectou período, default = últimos 3 meses ──
    else {
        $periodo_ate = date('Y-m');
        $periodo_de  = date('Y-m', strtotime('-2 months'));
    }

    // ── Regime ──
    if (preg_match('/\bregime\s*(caixa|competencia)\b/', $text_norm, $m)) {
        $regime = $m[1];
    } elseif (strpos($text_norm, 'caixa') !== false && strpos($text_norm, 'regime') !== false) {
        $regime = 'caixa';
    }

    // ── Empresa ──
    if (preg_match('/\bempresa\s*(\d+(?:,\d+)*)\b/', $text_norm, $m)) {
        $empresa = $m[1];
    }
    // Nomes conhecidos
    $empresa_map = [
        'real infra'         => '1146',
        'real infraestrutura' => '1146',
        'real defensa'       => '1',
        'real defensas'      => '1',
        'real sinalizacao'   => '1145',
        'real engenharia'    => '1147',
    ];
    foreach ($empresa_map as $nome => $id) {
        if (strpos($text_norm, $nome) !== false) {
            $empresa = $id;
            break;
        }
    }

    return [
        'periodo_de'  => $periodo_de,
        'periodo_ate' => $periodo_ate,
        'empresa'     => $empresa,
        'regime'      => $regime,
    ];
}

/**
 * Remove acentos para matching normalizado.
 */
function _dre_handler_remove_acentos(string $str): string {
    $map = [
        'á'=>'a','à'=>'a','ã'=>'a','â'=>'a','ä'=>'a',
        'é'=>'e','è'=>'e','ê'=>'e','ë'=>'e',
        'í'=>'i','ì'=>'i','î'=>'i','ï'=>'i',
        'ó'=>'o','ò'=>'o','õ'=>'o','ô'=>'o','ö'=>'o',
        'ú'=>'u','ù'=>'u','û'=>'u','ü'=>'u',
        'ç'=>'c','ñ'=>'n',
    ];
    return strtr($str, $map);
}

// ======================================================
// FIM ANCHOR: DONNA_DRE_HANDLER_V1
// ======================================================

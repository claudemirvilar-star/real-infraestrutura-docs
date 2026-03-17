<?php
// ======================================================
// ANCHOR: MOTOR_MO_REAL_V1_DEFINITIVO_SEM_GERENCIAL
// Arquivo: public_html/api/mao_obra/motor_mo_real_v1.php
// Objetivo (3-5 linhas):
// - Motor MO REAL (oficial) por dia: idempotente (apaga e recalcula)
// - Usa alocações do mo_alocacao_medicao_lib.php (Tab_medicao_nova + pesos + usuarios)
// - Calcula custo por colaborador-dia via mo_custo_colab_lib.php (Tangerino-only)
// - Gerencial DESATIVADO: sempre 0 (compatibilidade mantida)
// URL:
// - https://globalsinalizacao.online/api/mao_obra/motor_mo_real_v1.php?data_ref=YYYY-MM-DD&debug=1&gravar=0
// - https://globalsinalizacao.online/api/mao_obra/motor_mo_real_v1.php?data_ref=YYYY-MM-DD&debug=0&gravar=1&limpar=1
// ======================================================

header('Content-Type: application/json; charset=utf-8');
ini_set('display_errors', '0');
error_reporting(E_ALL);
set_time_limit(180);

// ---- includes
require_once __DIR__ . '/mo_alocacao_medicao_lib.php';
require_once __DIR__ . '/mo_custo_colab_lib.php';

// ======================================================
// ANCHOR: MO_REAL_INPUTS_V1
// ======================================================
$data_ref = isset($_GET['data_ref']) ? trim((string)$_GET['data_ref']) : '';
if ($data_ref === '' && isset($_GET['data'])) $data_ref = trim((string)$_GET['data']);

$debug  = isset($_GET['debug']) ? intval($_GET['debug']) : 0;
$gravar = isset($_GET['gravar']) ? intval($_GET['gravar']) : 1;
$limpar = isset($_GET['limpar']) ? intval($_GET['limpar']) : 1;
$custoDiaOverride = isset($_GET['custo_dia']) ? floatval($_GET['custo_dia']) : null;

if ($data_ref === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $data_ref)) {
  http_response_code(400);
  echo json_encode([
    'ok' => false,
    'erro' => 'Parâmetro obrigatório: data_ref=YYYY-MM-DD (ou data=YYYY-MM-DD)',
    'exemplo' => 'https://globalsinalizacao.online/api/mao_obra/motor_mo_real_v1.php?data_ref=2026-02-15&debug=1&gravar=0'
  ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
  exit;
}
// ======================================================
// FIM ANCHOR: MO_REAL_INPUTS_V1
// ======================================================


// ======================================================
// ANCHOR: MO_REAL_DB_EXEC_PDO_MYSQLI_V1
// Objetivo (3-5 linhas):
// - Executar SQL com params em PDO OU MySQLi
// - Em PDO: rowCount()
// - Em MySQLi: affected_rows
// ======================================================
function mo_db_exec($sql, $params = []) {
  $ctx = mo_db_get_driver_and_handle();
  $driver = $ctx['driver'];
  $db = $ctx['db'];

  // PDO
  if ($driver === 'pdo') {
    $st = $db->prepare($sql);
    $st->execute($params);
    return $st->rowCount();
  }

  // MySQLi
  if (!($db instanceof mysqli)) {
    throw new Exception("Driver invalido em mo_db_exec (esperado mysqli).");
  }

  $stmt = $db->prepare($sql);
  if (!$stmt) throw new Exception("mysqli prepare falhou: " . $db->error);

  if (!empty($params)) {
    $types = '';
    $bind = [];
    foreach ($params as $p) {
      if (is_int($p)) $types .= 'i';
      else if (is_float($p) || is_double($p)) $types .= 'd';
      else $types .= 's';
      $bind[] = $p;
    }
    $refs = [];
    $refs[] = &$types;
    foreach ($bind as $k => $v) $refs[] = &$bind[$k];
    call_user_func_array([$stmt, 'bind_param'], $refs);
  }

  $stmt->execute();
  $affected = $stmt->affected_rows;
  $stmt->close();
  return $affected;
}
// ======================================================
// FIM ANCHOR: MO_REAL_DB_EXEC_PDO_MYSQLI_V1
// ======================================================


// ======================================================
// ANCHOR: MO_REAL_SAFE_UPSERT_V1
// Objetivo (3-5 linhas):
// - UPSERT seguro: insere/atualiza somente colunas que existem na tabela
// - Evita quebrar se o schema variar (cliente, detalhes_json, updated_at, etc.)
// ======================================================
function mo_table_cols($table) {
  $ctx = mo_db_get_driver_and_handle();
  $driver = $ctx['driver'];
  $db = $ctx['db'];
  $cols = [];

  if ($driver === 'pdo') {
    $st = $db->prepare("SHOW COLUMNS FROM `$table`");
    $st->execute();
    while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
      $cols[$r['Field']] = true;
    }
    return $cols;
  }

  if (!($db instanceof mysqli)) return $cols;
  $res = $db->query("SHOW COLUMNS FROM `$table`");
  if (!$res) return $cols;
  while ($r = $res->fetch_assoc()) $cols[$r['Field']] = true;
  $res->free();
  return $cols;
}

function mo_safe_upsert($table, $data, $unique_keys = []) {
  $cols = mo_table_cols($table);
  if (empty($cols)) throw new Exception("Tabela nao encontrada: $table");

  $use = [];
  foreach ($data as $k => $v) {
    if (isset($cols[$k])) $use[$k] = $v;
  }
  if (empty($use)) {
    throw new Exception("Nenhuma coluna compativel para inserir em $table");
  }

  $fields = array_keys($use);
  $place = implode(",", array_fill(0, count($fields), "?"));

  $sets = [];
  foreach ($fields as $f) {
    if (in_array($f, $unique_keys, true)) continue;
    $sets[] = "`$f` = VALUES(`$f`)";
  }
  if (empty($sets)) {
    $sets[] = "`" . $fields[0] . "` = VALUES(`" . $fields[0] . "`)";
  }

  $sql = "INSERT INTO `$table` (`" . implode("`,`", $fields) . "`) VALUES ($place)
          ON DUPLICATE KEY UPDATE " . implode(", ", $sets);

  return mo_db_exec($sql, array_values($use));
}
// ======================================================
// FIM ANCHOR: MO_REAL_SAFE_UPSERT_V1
// ======================================================


// ======================================================
// ANCHOR: MO_REAL_HELPER_BUSCAR_CPF_TAB_COLABORADORES_V1
// Objetivo (3-5 linhas):
// - Buscar CPF do colaborador na Tab_colaboradores
// - Em PDO: prepare/bind normal
// - Em MySQLi: usar query (sem stmt) para evitar bugs de stmt no Hostinger
// - Retorna somente CPF com 11 dígitos ou string vazia
// ======================================================
function mo_buscar_cpf_por_nome_tab_colaboradores($nome) {
  $nome = trim((string)$nome);
  if ($nome === '') return '';

  $nomeNorm = function_exists('mb_strtolower') ? mb_strtolower($nome, 'UTF-8') : strtolower($nome);
  $nomeNorm = preg_replace('/\s+/', ' ', $nomeNorm);

  $ctx = mo_db_get_driver_and_handle();
  $driver = $ctx['driver'];
  $db = $ctx['db'];

  $normCpf = function($cpfRaw) {
    $cpfNum = preg_replace('/\D+/', '', (string)$cpfRaw);
    return (strlen($cpfNum) === 11) ? $cpfNum : '';
  };

  if ($driver === 'pdo') {
    $sqlExact = "SELECT cpf FROM Tab_colaboradores WHERE LOWER(TRIM(nome)) = ? LIMIT 1";
    $st = $db->prepare($sqlExact);
    $st->execute([$nomeNorm]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if ($r && isset($r['cpf'])) {
      $cpf = $normCpf($r['cpf']);
      if ($cpf !== '') return $cpf;
    }

    $partes = explode(' ', $nomeNorm);
    if (count($partes) < 2) return '';

    $like = '%' . $partes[0] . '%' . end($partes) . '%';
    $sqlLike = "SELECT cpf FROM Tab_colaboradores WHERE LOWER(nome) LIKE ? LIMIT 1";
    $st2 = $db->prepare($sqlLike);
    $st2->execute([$like]);
    $r2 = $st2->fetch(PDO::FETCH_ASSOC);
    if ($r2 && isset($r2['cpf'])) {
      $cpf = $normCpf($r2['cpf']);
      if ($cpf !== '') return $cpf;
    }

    return '';
  }

  if (!($db instanceof mysqli)) return '';

  $nomeEsc = $db->real_escape_string($nomeNorm);

  $sql1 = "SELECT cpf FROM Tab_colaboradores WHERE LOWER(TRIM(nome)) = '{$nomeEsc}' LIMIT 1";
  $res1 = $db->query($sql1);
  if ($res1 && ($row = $res1->fetch_assoc())) {
    $cpf = $normCpf($row['cpf'] ?? '');
    $res1->free();
    if ($cpf !== '') return $cpf;
  } elseif ($res1) {
    $res1->free();
  }

  $partes = explode(' ', $nomeNorm);
  if (count($partes) < 2) return '';

  $like = '%' . $partes[0] . '%' . end($partes) . '%';
  $likeEsc = $db->real_escape_string($like);

  $sql2 = "SELECT cpf FROM Tab_colaboradores WHERE LOWER(nome) LIKE '{$likeEsc}' LIMIT 1";
  $res2 = $db->query($sql2);
  if ($res2 && ($row = $res2->fetch_assoc())) {
    $cpf = $normCpf($row['cpf'] ?? '');
    $res2->free();
    if ($cpf !== '') return $cpf;
  } elseif ($res2) {
    $res2->free();
  }

  return '';
}
// ======================================================
// FIM ANCHOR: MO_REAL_HELPER_BUSCAR_CPF_TAB_COLABORADORES_V1
// ======================================================


// ======================================================
// ANCHOR: MO_REAL_OBRA_NORMALIZE_HELPER_V1
// Objetivo (3-5 linhas):
// - Normalizar nomes de obra para casar gravação colab_obra com obra_producao_total
// - Evita modal "Sem colaboradores retornados para esta obra/dia."
// ======================================================
function mo_norm_obra($s) {
  $s = trim((string)$s);
  if ($s === '') return '';
  $s = preg_replace('/\s+/', ' ', $s);

  if (function_exists('iconv')) {
    $t = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
    if ($t !== false && $t !== null) $s = $t;
  }
  return strtoupper($s);
}
// ======================================================
// FIM ANCHOR: MO_REAL_OBRA_NORMALIZE_HELPER_V1
// ======================================================

// ======================================================
// ANCHOR: MO_REAL_HELPER_NRO_REGISTRO_USUARIO_V1
// Objetivo (3-5 linhas):
// - Buscar nro_registro por nome na Tab_colaboradores (para o endpoint enriquecer Tangerino)
// - Pegar usuario dominante do alloc (usuario_id/usuario_nome) para o modal
// ======================================================
function mo_buscar_nro_registro_por_nome($nome) {
  $nome = trim((string)$nome);
  if ($nome === '') return null;

  $ctx = mo_db_get_driver_and_handle();
  $driver = $ctx['driver'];
  $db = $ctx['db'];

  if ($driver === 'pdo') {
    $st = $db->prepare("SELECT nro_registro FROM Tab_colaboradores WHERE LOWER(TRIM(nome)) = LOWER(TRIM(?)) LIMIT 1");
    $st->execute([$nome]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if ($r && isset($r['nro_registro'])) {
      $v = (int)$r['nro_registro'];
      return $v > 0 ? $v : null;
    }
    return null;
  }

  if (!($db instanceof mysqli)) return null;
  $nomeEsc = $db->real_escape_string($nome);
  $sql = "SELECT nro_registro FROM Tab_colaboradores WHERE LOWER(TRIM(nome)) = LOWER(TRIM('{$nomeEsc}')) LIMIT 1";
  $res = $db->query($sql);
  if ($res && ($row = $res->fetch_assoc())) {
    $res->free();
    $v = (int)($row['nro_registro'] ?? 0);
    return $v > 0 ? $v : null;
  }
  if ($res) $res->free();
  return null;
}

function mo_pick_usuario_dominante_alloc($alloc, $colabKey, $obra = null) {
  $out = ['usuario_id' => null, 'usuario_nome' => null];

  // tenta por obra
  if ($obra !== null && isset($alloc['colab_obra_usuario'][$colabKey][$obra])) {
    $u = $alloc['colab_obra_usuario'][$colabKey][$obra];
    $out['usuario_id'] = isset($u['usuario_id']) ? (int)$u['usuario_id'] : (isset($u['id_usuario']) ? (int)$u['id_usuario'] : null);
    $out['usuario_nome'] = trim((string)($u['usuario_nome'] ?? $u['usuario'] ?? ''));
    return $out;
  }

  // tenta dominante geral
  if (isset($alloc['colab_usuario'][$colabKey])) {
    $u = $alloc['colab_usuario'][$colabKey];
    $out['usuario_id'] = isset($u['usuario_id']) ? (int)$u['usuario_id'] : (isset($u['id_usuario']) ? (int)$u['id_usuario'] : null);
    $out['usuario_nome'] = trim((string)($u['usuario_nome'] ?? $u['usuario'] ?? ''));
    return $out;
  }

  return $out;
}
// ======================================================
// FIM ANCHOR: MO_REAL_HELPER_NRO_REGISTRO_USUARIO_V1
// ======================================================

// ======================================================
// ANCHOR: MO_REAL_LOG_INICIO_V1
// Objetivo: registrar inicio da execucao em log_motor_mo
// ======================================================
$__mo_log_id = null;
$__mo_log_ts_start = microtime(true);
if ($gravar === 1) {
  try {
    $__logCtx = mo_db_get_driver_and_handle();
    if ($__logCtx['driver'] === 'pdo') {
      $__logSt = $__logCtx['db']->prepare("INSERT INTO log_motor_mo (data_ref, inicio, status, modo) VALUES (?, NOW(), 'RUNNING', 'motor_mo_real_v1')");
      $__logSt->execute([$data_ref]);
      $__mo_log_id = $__logCtx['db']->lastInsertId();
    } else {
      $__logStmt = $__logCtx['db']->prepare("INSERT INTO log_motor_mo (data_ref, inicio, status, modo) VALUES (?, NOW(), 'RUNNING', 'motor_mo_real_v1')");
      if ($__logStmt) {
        $__logStmt->bind_param('s', $data_ref);
        $__logStmt->execute();
        $__mo_log_id = $__logCtx['db']->insert_id;
        $__logStmt->close();
      }
    }
  } catch (Throwable $_logErr) {
    // log best-effort, nao impede o motor
  }
}
// ======================================================
// FIM ANCHOR: MO_REAL_LOG_INICIO_V1
// ======================================================

try {

  // ======================================================
  // ANCHOR: MO_REAL_TRANSACAO_BEGIN_V1
  // Objetivo: garantir atomicidade do ciclo DELETE+INSERT do dia
  // Se o processo morrer no meio, ROLLBACK automatico preserva dados anteriores
  // ======================================================
  $__usou_transacao = false;
  if ($gravar === 1) {
    $__txCtx = mo_db_get_driver_and_handle();
    if ($__txCtx['driver'] === 'pdo') {
      $__txCtx['db']->beginTransaction();
    } else {
      $__txCtx['db']->begin_transaction();
    }
    $__usou_transacao = true;
  }
  // ======================================================
  // FIM ANCHOR: MO_REAL_TRANSACAO_BEGIN_V1
  // ======================================================

  // ======================================================
  // ANCHOR: MO_REAL_IDEMPOTENTE_DELETE_V1
  // ======================================================
  if ($gravar === 1) {
    mo_db_exec("DELETE FROM fato_mo_diaria_colab_obra WHERE data_ref = ?", [$data_ref]);
    mo_db_exec("DELETE FROM fato_mo_diaria_colab WHERE data_ref = ?", [$data_ref]);
    mo_db_exec("DELETE FROM fato_resultado_diario_obra WHERE data_ref = ?", [$data_ref]);
    mo_db_exec("DELETE FROM espelho_alertas_ponto_diario WHERE data_ref = ?", [$data_ref]);
  }
  // ======================================================
  // FIM ANCHOR: MO_REAL_IDEMPOTENTE_DELETE_V1
  // ======================================================

  // ======================================================
  // ANCHOR: MO_REAL_ALLOC_LOAD_V1
  // ======================================================
  $alloc = mo_listar_alocacoes_por_dia($data_ref);
  if (!is_array($alloc)) $alloc = [];

  if (!isset($alloc['colab_pesos']) || !is_array($alloc['colab_pesos'])) $alloc['colab_pesos'] = [];
  // ======================================================
  // FIM ANCHOR: MO_REAL_ALLOC_LOAD_V1
  // ======================================================

  // ======================================================
  // ANCHOR: MO_REAL_OBRA_MAP_V1
  // Objetivo: construir mapa obra_norm => obra_original (do obra_producao_total)
  // ======================================================
  $__obraMap = [];
  foreach (($alloc['obra_producao_total'] ?? []) as $obraK => $prodV) {
    $obraNome = trim((string)$obraK);
    if ($obraNome === '' || strtolower($obraNome) === 'null') continue;
    $__obraMap[ mo_norm_obra($obraNome) ] = $obraNome;
  }
  // ======================================================
  // FIM ANCHOR: MO_REAL_OBRA_MAP_V1
  // ======================================================

  // fallback: se colab_pesos vier vazio, tenta montar a partir de medicoes
  $__fallback_usado = false;
  $__fallback_qtd_pares = 0;
  if (count($alloc['colab_pesos']) === 0 && isset($alloc['medicoes']) && is_array($alloc['medicoes']) && count($alloc['medicoes']) > 0) {
    foreach ($alloc['medicoes'] as $m) {
      $ck = trim((string)($m['colabKey'] ?? $m['colaborador'] ?? $m['nome'] ?? $m['funcionario'] ?? ''));
      if ($ck === '') continue;

      $obra = trim((string)($m['obra'] ?? $m['obra_nome'] ?? $m['cliente'] ?? $m['2_cliente'] ?? ''));
      if ($obra === '') $obra = '(SEM_OBRA)';

      if (!isset($alloc['colab_pesos'][$ck])) $alloc['colab_pesos'][$ck] = [];
      if (!isset($alloc['colab_pesos'][$ck][$obra])) {
        $alloc['colab_pesos'][$ck][$obra] = 1.0;
        $__fallback_qtd_pares++;
      }
    }
    $__fallback_usado = ($__fallback_qtd_pares > 0);
  }

  // debug do alloc (compacto)
  $__alloc_debug = null;
  if ($debug === 1) {
    $keysTop = array_keys($alloc);
    $__alloc_debug = [
      'alloc_keys_top' => array_slice($keysTop, 0, 40),
      'qtd_medicoes' => (isset($alloc['medicoes']) && is_array($alloc['medicoes'])) ? count($alloc['medicoes']) : 0,
      'qtd_colab_pesos' => count($alloc['colab_pesos']),
      'amostra_colabs' => array_slice(array_keys($alloc['colab_pesos']), 0, 12),
      'fallback_usado' => $__fallback_usado,
      'fallback_qtd_pares' => $__fallback_qtd_pares,
      'tangerino_total' => (isset($alloc['tangerino']) && is_array($alloc['tangerino'])) ? count($alloc['tangerino']) : 0,
      'ponto_total' => (isset($alloc['ponto']) && is_array($alloc['ponto'])) ? count($alloc['ponto']) : 0,
    ];
  }

  $totalMoOficial = 0.0;
  $obraMo = []; // obra => soma MO oficial (obra base: a mesma string que gravamos em colab_obra)

  foreach (($alloc['colab_pesos'] ?? []) as $colabKey => $mapaPesos) {
    $colabKey = (string)$colabKey;

    $nomeNorm = strtolower(trim($colabKey));
    $nomeNorm = preg_replace('/\s+/', ' ', $nomeNorm);

    $colaborador_id = abs(crc32($nomeNorm));
    if ($colaborador_id === 0) continue;

    $cpf = mo_buscar_cpf_por_nome_tab_colaboradores($colabKey);

    // tRow (tangerino preferencial)
    $tRowRaw = null;
    if (isset($alloc['tangerino']) && is_array($alloc['tangerino']) && isset($alloc['tangerino'][$colabKey]) && is_array($alloc['tangerino'][$colabKey])) {
      $tRowRaw = $alloc['tangerino'][$colabKey];
    } else if (isset($alloc['ponto']) && is_array($alloc['ponto']) && isset($alloc['ponto'][$colabKey]) && is_array($alloc['ponto'][$colabKey])) {
      $tRowRaw = $alloc['ponto'][$colabKey];
    }

    $tRow = null;
    if (is_array($tRowRaw)) {
      $tRow = [
        'tangerino_employee_id' => $tRowRaw['tangerino_employee_id'] ?? $tRowRaw['employee_id'] ?? null,
        'e1' => $tRowRaw['e1'] ?? null,
        's1' => $tRowRaw['s1'] ?? null,
        'e2' => $tRowRaw['e2'] ?? null,
        's2' => $tRowRaw['s2'] ?? null,
        'batidas_total' => $tRowRaw['batidas_total'] ?? 0,
      ];
    }

    // calcula custo
    $calc = mo_calcular_custo_colab_dia(
      intval($colaborador_id),
      ($cpf !== '' ? $cpf : null),
      $data_ref,
      $custoDiaOverride,
      $tRow
    );

    // força gerencial = 0
    $calc['mo_gerencial'] = 0.0;

    $moOf = floatval($calc['mo_oficial'] ?? 0);
    $totalMoOficial += $moOf;

    // grava colab-dia (UPSERT normal)
    if ($gravar === 1) {
      mo_db_exec("
        INSERT INTO fato_mo_diaria_colab
        (data_ref, colaborador_id, cpf, nome, mo_oficial, mo_gerencial, origem_mo, updated_at)
        VALUES (?, ?, ?, ?, ?, 0, 'motor_mo_real_v1', NOW())
        ON DUPLICATE KEY UPDATE
          cpf = VALUES(cpf),
          nome = VALUES(nome),
          mo_oficial = VALUES(mo_oficial),
          mo_gerencial = 0,
          origem_mo = VALUES(origem_mo),
          updated_at = NOW()
      ", [
        $data_ref,
        intval($colaborador_id),
        ($cpf !== '' ? $cpf : null),
        $colabKey,
        $moOf
      ]);
    }

    // obra (primeira do mapaPesos)
    $obra = null;
    if (is_array($mapaPesos) && !empty($mapaPesos)) {
      $obra = (string)array_key_first($mapaPesos);
    }

    if ($obra !== null && $obra !== '') {

      // ======================================================
      // ANCHOR: MO_REAL_OBRA_FINAL_MODAL_MATCH_V1
      // Objetivo: garantir que obra gravada em colab_obra bate com obra_producao_total
      // ======================================================
      $obraFinal = $obra;
      $obraN = mo_norm_obra($obraFinal);
      if (isset($__obraMap[$obraN])) {
        $obraFinal = $__obraMap[$obraN];
      }
      // ======================================================
      // FIM ANCHOR: MO_REAL_OBRA_FINAL_MODAL_MATCH_V1
      // ======================================================

      $obraMo[$obraFinal] = ($obraMo[$obraFinal] ?? 0) + $moOf;

      // ======================================================
      // ANCHOR: MO_REAL_WRITE_COLAB_OBRA_V2_MODAL_FIX
      // Objetivo:
      // - Gravar colaborador-obra-dia com obra casada (modal encontra)
      // - Gravar detalhes_json com batidas (modal mostra pontos)
      // - UPSERT seguro
      // ======================================================
      if ($gravar === 1) {
        $batidas = [];
        if (is_array($tRow)) {
          if (!empty($tRow['e1'])) $batidas[] = $tRow['e1'];
          if (!empty($tRow['s1'])) $batidas[] = $tRow['s1'];
          if (!empty($tRow['e2'])) $batidas[] = $tRow['e2'];
          if (!empty($tRow['s2'])) $batidas[] = $tRow['s2'];
        }

        $detObra = json_encode([
          'colabKey' => $colabKey,
          'cpf' => ($cpf !== '' ? $cpf : null),
          'obra_original' => $obra,
          'obra_final' => $obraFinal,
          'batidas' => $batidas,
          'e1' => (is_array($tRow) ? ($tRow['e1'] ?? null) : null),
          's1' => (is_array($tRow) ? ($tRow['s1'] ?? null) : null),
          'e2' => (is_array($tRow) ? ($tRow['e2'] ?? null) : null),
          's2' => (is_array($tRow) ? ($tRow['s2'] ?? null) : null),
          'batidas_total' => (is_array($tRow) ? intval($tRow['batidas_total'] ?? 0) : 0),
          'employee_id' => (is_array($tRow) ? ($tRow['tangerino_employee_id'] ?? null) : null),
          'origem_batida' => 'tangerino',
          'gerencial' => 'DESATIVADO',
        ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

        mo_safe_upsert("fato_mo_diaria_colab_obra", [
          'data_ref' => $data_ref,
          'colaborador_id' => intval($colaborador_id),
          'cpf' => ($cpf !== '' ? $cpf : null),
          'nome' => $colabKey,
          'obra' => $obraFinal,
          'cliente' => $obraFinal, // compatibilidade com telas que usam cliente
          'mo_oficial_rateada' => $moOf,
          'mo_gerencial_rateada' => 0,
          'detalhes_json' => $detObra,
          // --- campos que o endpoint usa para enriquecer Tangerino/UI do modal
'nro_registro' => mo_buscar_nro_registro_por_nome($colabKey),

// usuario (para o modal mostrar quem lançou/encarregado, se existir no alloc)
'usuario_id' => (function() use ($alloc, $colabKey, $obraFinal) {
  $u = mo_pick_usuario_dominante_alloc($alloc, $colabKey, $obraFinal);
  return ($u['usuario_id'] ?? null);
})(),
'usuario_nome' => (function() use ($alloc, $colabKey, $obraFinal) {
  $u = mo_pick_usuario_dominante_alloc($alloc, $colabKey, $obraFinal);
  return ($u['usuario_nome'] ?? null);
})(),
          'updated_at' => date('Y-m-d H:i:s'),
        ], ['data_ref','colaborador_id','obra']);
      }
      // ======================================================
      // FIM ANCHOR: MO_REAL_WRITE_COLAB_OBRA_V2_MODAL_FIX
      // ======================================================
    }
  }

  // ======================================================
  // ANCHOR: MO_REAL_WRITE_RESULTADO_OBRA_UPSERT_V1
  // Objetivo: UPSERT agregado por obra para evitar duplicidade (data_ref + obra)
  // ======================================================
  foreach (($alloc['obra_producao_total'] ?? []) as $obraK => $prodV) {
    $obraNome = trim((string)$obraK);
    if ($obraNome === '' || strtolower($obraNome) === 'null') continue;

    $prod = floatval(is_array($prodV) ? ($prodV['producao_total'] ?? 0) : $prodV);
    $moOf = floatval($obraMo[$obraNome] ?? 0);

    $resultado = $prod - $moOf;
    $margem = ($prod > 0) ? (($resultado / $prod) * 100.0) : null;

    if ($gravar === 1) {
      mo_db_exec("
        INSERT INTO fato_resultado_diario_obra
        (data_ref, obra,
         producao_total_dia, mo_oficial_total_dia, mo_gerencial_total_dia,
         resultado_oficial_dia, margem_percentual_oficial_dia,
         resultado_gerencial_dia, margem_percentual_gerencial_dia)
        VALUES (?, ?, ?, ?, 0, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
          producao_total_dia = VALUES(producao_total_dia),
          mo_oficial_total_dia = VALUES(mo_oficial_total_dia),
          mo_gerencial_total_dia = 0,
          resultado_oficial_dia = VALUES(resultado_oficial_dia),
          margem_percentual_oficial_dia = VALUES(margem_percentual_oficial_dia),
          resultado_gerencial_dia = VALUES(resultado_gerencial_dia),
          margem_percentual_gerencial_dia = VALUES(margem_percentual_gerencial_dia)
      ", [
        $data_ref,
        $obraNome,
        $prod,
        $moOf,
        $resultado,
        $margem,
        $resultado, // espelho do oficial (compat)
        $margem     // espelho do oficial (compat)
      ]);
    }
  }
  // ======================================================
  // FIM ANCHOR: MO_REAL_WRITE_RESULTADO_OBRA_UPSERT_V1
  // ======================================================

  // ======================================================
  // ANCHOR: MO_REAL_TRANSACAO_COMMIT_V1
  // ======================================================
  if ($__usou_transacao) {
    $__txCtx = mo_db_get_driver_and_handle();
    if ($__txCtx['driver'] === 'pdo') {
      $__txCtx['db']->commit();
    } else {
      $__txCtx['db']->commit();
    }
  }
  // ======================================================
  // FIM ANCHOR: MO_REAL_TRANSACAO_COMMIT_V1
  // ======================================================

  // ======================================================
  // ANCHOR: MO_REAL_LOG_SUCESSO_V1
  // ======================================================
  if ($__mo_log_id) {
    try {
      $__durSeg = round(microtime(true) - $__mo_log_ts_start, 2);
      $__logCtx2 = mo_db_get_driver_and_handle();
      $__qtdObras = count($obraMo);
      if ($__logCtx2['driver'] === 'pdo') {
        $__logUp = $__logCtx2['db']->prepare("UPDATE log_motor_mo SET fim=NOW(), duracao_seg=?, mo_total=?, total_colaboradores=?, total_obras=?, status='OK', assinatura=? WHERE id=?");
        $__logUp->execute([$__durSeg, round($totalMoOficial, 2), count($alloc['colab_pesos'] ?? []), $__qtdObras, 'MOTOR_MO_REAL_V1_TRANSACAO_OK_2026_03_10', $__mo_log_id]);
      } else {
        $__logUp = $__logCtx2['db']->prepare("UPDATE log_motor_mo SET fim=NOW(), duracao_seg=?, mo_total=?, total_colaboradores=?, total_obras=?, status='OK', assinatura=? WHERE id=?");
        if ($__logUp) {
          $__logUp->bind_param('ddiisi', $__durSeg, $totalMoOficial, $__colabCount, $__qtdObras, $__assin, $__mo_log_id);
          $__colabCount = count($alloc['colab_pesos'] ?? []);
          $__assin = 'MOTOR_MO_REAL_V1_TRANSACAO_OK_2026_03_10';
          $__logUp->execute();
          $__logUp->close();
        }
      }
    } catch (Throwable $_logErr) {
      // log best-effort
    }
  }
  // ======================================================
  // FIM ANCHOR: MO_REAL_LOG_SUCESSO_V1
  // ======================================================

  echo json_encode([
    'ok' => true,
    'modo' => 'motor_mo_real_v1_definitivo',
    'data_ref' => $data_ref,
    'gravar' => $gravar,
    'limpar' => $limpar,
    'total_mo_oficial_dia' => round($totalMoOficial, 2),
    'total_mo_gerencial_dia' => 0,
    '__assinatura' => 'MOTOR_MO_REAL_V1_TRANSACAO_OK_2026_03_10',
    '__arquivo' => __FILE__,
    'alloc_debug' => ($debug === 1 ? $__alloc_debug : null),
  ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

} catch (Throwable $e) {
  // ======================================================
  // ANCHOR: MO_REAL_TRANSACAO_ROLLBACK_V1
  // ======================================================
  if (!empty($__usou_transacao)) {
    try {
      $__txCtx = mo_db_get_driver_and_handle();
      if ($__txCtx['driver'] === 'pdo') {
        $__txCtx['db']->rollBack();
      } else {
        $__txCtx['db']->rollback();
      }
    } catch (Throwable $_rollbackErr) {
      // rollback best-effort; conexao pode ja estar morta
    }
  }
  // ======================================================
  // FIM ANCHOR: MO_REAL_TRANSACAO_ROLLBACK_V1
  // ======================================================

  // ======================================================
  // ANCHOR: MO_REAL_LOG_ERRO_V1
  // ======================================================
  if (!empty($__mo_log_id)) {
    try {
      $__durSeg = round(microtime(true) - $__mo_log_ts_start, 2);
      $__errMsg = substr($e->getMessage(), 0, 500);
      $__logCtx3 = mo_db_get_driver_and_handle();
      if ($__logCtx3['driver'] === 'pdo') {
        $__logEr = $__logCtx3['db']->prepare("UPDATE log_motor_mo SET fim=NOW(), duracao_seg=?, status='ERRO', erro=? WHERE id=?");
        $__logEr->execute([$__durSeg, $__errMsg, $__mo_log_id]);
      } else {
        $__logEr = $__logCtx3['db']->prepare("UPDATE log_motor_mo SET fim=NOW(), duracao_seg=?, status='ERRO', erro=? WHERE id=?");
        if ($__logEr) {
          $__logEr->bind_param('dsi', $__durSeg, $__errMsg, $__mo_log_id);
          $__logEr->execute();
          $__logEr->close();
        }
      }
    } catch (Throwable $_logErr2) {
      // log best-effort
    }
  }
  // ======================================================
  // FIM ANCHOR: MO_REAL_LOG_ERRO_V1
  // ======================================================

  http_response_code(500);
  echo json_encode([
    'ok' => false,
    'erro' => $e->getMessage(),
    'file' => $e->getFile(),
    'line' => $e->getLine(),
  ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
}
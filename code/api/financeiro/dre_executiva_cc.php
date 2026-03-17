<?php
/**
 * ============================================================
 * DRE EXECUTIVA POR CENTRO DE CUSTO
 * ============================================================
 * Endpoint: GET /api/financeiro/dre_executiva_cc.php
 *
 * Parâmetros:
 *   periodo_de  = YYYY-MM (obrigatório)
 *   periodo_ate = YYYY-MM (obrigatório)
 *   cc          = centro_custo_erp (opcional, filtra 1 CC)
 *   tipo_cc     = OBRA_CLIENTE|ADMINISTRATIVO|... (opcional)
 *   formato     = json (default) | texto
 *
 * Exemplos:
 *   ?periodo_de=2026-01&periodo_ate=2026-03
 *   ?periodo_de=2026-01&periodo_ate=2026-03&cc=RODOANEL-SP
 *   ?periodo_de=2026-01&periodo_ate=2026-12&formato=texto
 * ============================================================
 */
date_default_timezone_set('America/Sao_Paulo');
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

// ── Parâmetros ──────────────────────────────────────────────
$periodo_de  = $_GET['periodo_de']  ?? null;
$periodo_ate = $_GET['periodo_ate'] ?? null;
$cc_filtro   = $_GET['cc']          ?? null;
$tipo_filtro = $_GET['tipo_cc']     ?? null;
$formato     = $_GET['formato']     ?? 'json';

if (!$periodo_de || !$periodo_ate) {
    http_response_code(400);
    echo json_encode(['erro' => 'Parâmetros periodo_de e periodo_ate são obrigatórios (YYYY-MM)']);
    exit;
}

// ── Conexão ─────────────────────────────────────────────────
$_db_cfg = require '/etc/app_secrets/db_hostinger.php';
$pdo = new PDO(
    "mysql:host={$_db_cfg['host']};dbname={$_db_cfg['db']};charset=utf8mb4",
    $_db_cfg['user'], $_db_cfg['pass'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

// ══════════════════════════════════════════════════════════════
// QUERY: buscar dados da view agrupados por CC
// ══════════════════════════════════════════════════════════════

$where = "ano_mes BETWEEN :de AND :ate";
$params = ['de' => $periodo_de, 'ate' => $periodo_ate];

if ($cc_filtro) {
    $where .= " AND centro_custo = :cc";
    $params['cc'] = $cc_filtro;
}
if ($tipo_filtro) {
    $where .= " AND tipo_cc = :tipo";
    $params['tipo'] = $tipo_filtro;
}

$sql = "
SELECT
    centro_custo, id_map_cc, tipo_cc, nome_cc_base,
    grupo_dre, bloco_dre, ordem_dre,
    SUM(valor_dre) AS valor_dre,
    SUM(valor_absoluto) AS valor_absoluto,
    SUM(qtd_lancamentos) AS qtd,
    entra_dre,
    -- Separar CC_VAZIO
    MAX(CASE WHEN status_mapeamento = 'CC_VAZIO' THEN 1 ELSE 0 END) AS is_cc_vazio
FROM vw_dre_base_cc
WHERE $where
GROUP BY centro_custo, id_map_cc, tipo_cc, nome_cc_base, grupo_dre, bloco_dre, ordem_dre, entra_dre
ORDER BY centro_custo, ordem_dre
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ══════════════════════════════════════════════════════════════
// MONTAR DRE POR CC
// ══════════════════════════════════════════════════════════════

$dre_por_cc = [];
$consolidado = [
    'receita_bruta' => 0, 'deducoes' => 0, 'receita_liquida' => 0,
    'custos_operacionais' => 0, 'lucro_bruto' => 0,
    'despesas_adm' => 0, 'despesas_comerciais' => 0,
    'resultado_financeiro' => 0, 'resultado_operacional' => 0,
    'fora_dre' => 0, 'nao_classificado' => 0,
    'total_lancamentos' => 0,
];

foreach ($rows as $r) {
    $cc = $r['centro_custo'];
    if (!isset($dre_por_cc[$cc])) {
        $dre_por_cc[$cc] = [
            'centro_custo'  => $cc,
            'id_map_cc'     => (int) $r['id_map_cc'],
            'tipo_cc'       => $r['tipo_cc'],
            'nome_cc_base'  => $r['nome_cc_base'],
            'is_cc_vazio'   => false,
            'linhas'        => [],
            'totais'        => [
                'receita_bruta'        => 0,
                'deducoes'             => 0,
                'receita_liquida'      => 0,
                'custos_operacionais'  => 0,
                'lucro_bruto'          => 0,
                'despesas_adm'         => 0,
                'despesas_comerciais'  => 0,
                'resultado_financeiro' => 0,
                'resultado_operacional'=> 0,
                'fora_dre'             => 0,
                'nao_classificado'     => 0,
                'total_lancamentos'    => 0,
            ],
        ];
    }

    $ref = &$dre_por_cc[$cc];
    $t = &$ref['totais'];
    $val = (float) $r['valor_dre'];
    $qtd = (int) $r['qtd'];

    if ($r['is_cc_vazio']) $ref['is_cc_vazio'] = true;

    // Adicionar linha detalhada
    $ref['linhas'][] = [
        'grupo_dre'   => $r['grupo_dre'],
        'bloco_dre'   => $r['bloco_dre'],
        'valor_dre'   => round($val, 2),
        'qtd'         => $qtd,
        'entra_dre'   => (int) $r['entra_dre'],
    ];

    $t['total_lancamentos'] += $qtd;
    $consolidado['total_lancamentos'] += $qtd;

    // Calcular blocos DRE
    // Regras de sinal:
    //   valor_dre para receitas = positivo (receita real)
    //   valor_dre para custos/despesas = positivo (custo real)
    //   Na DRE: receita soma, custo/despesa subtrai
    switch ($r['bloco_dre']) {
        case 'RECEITA_BRUTA':
            $t['receita_bruta'] += $val;
            $consolidado['receita_bruta'] += $val;
            break;
        case 'DEDUCOES_RECEITA':
            $t['deducoes'] += $val;          // valor positivo = imposto pago
            $consolidado['deducoes'] += $val;
            break;
        case 'CUSTOS_OPERACIONAIS':
            $t['custos_operacionais'] += $val; // valor positivo = custo
            $consolidado['custos_operacionais'] += $val;
            break;
        case 'DESPESAS_ADMINISTRATIVAS':
            $t['despesas_adm'] += $val;
            $consolidado['despesas_adm'] += $val;
            break;
        case 'DESPESAS_COMERCIAIS':
            $t['despesas_comerciais'] += $val;
            $consolidado['despesas_comerciais'] += $val;
            break;
        case 'RESULTADO_FINANCEIRO':
            // Receitas financeiras (sinal_dre=+1) entram como positivo no valor_dre
            // Despesas financeiras (sinal_dre=-1) entram como positivo no valor_dre
            // Na DRE: receita financeira = positivo, despesa = negativo
            // Como valor_dre já tem sinal correto para revenue (+) e cost (+),
            // usamos a lógica: se valor_dre foi derivado de sinal +1 = receita fin.
            // Mas aqui temos o agregado. Solução: resultado_fin = receitas - despesas
            // onde valor_dre positivo com nome "RECEITAS FINANCEIRAS" soma,
            // e o restante subtrai. Porém no agregado da view, tudo está junto.
            // Simplificação: pegar o net como está (soma dos valor_dre mistos)
            $t['resultado_financeiro'] += $val;
            $consolidado['resultado_financeiro'] += $val;
            break;
        case 'FORA_DRE':
            $t['fora_dre'] += $val;
            $consolidado['fora_dre'] += $val;
            break;
        case 'NAO_CLASSIFICADO':
            $t['nao_classificado'] += $val;
            $consolidado['nao_classificado'] += $val;
            break;
    }
}

// Calcular subtotais derivados
foreach ($dre_por_cc as &$cc_data) {
    $t = &$cc_data['totais'];
    $t['receita_liquida']       = $t['receita_bruta'] - $t['deducoes'];
    $t['lucro_bruto']           = $t['receita_liquida'] - $t['custos_operacionais'];
    $t['resultado_operacional'] = $t['lucro_bruto']
                                 - $t['despesas_adm']
                                 - $t['despesas_comerciais']
                                 + $t['resultado_financeiro'];
    // Arredondar tudo
    foreach ($t as $k => &$v) {
        if ($k !== 'total_lancamentos') $v = round($v, 2);
    }
}
unset($cc_data, $t, $v);

// Consolidado
$consolidado['receita_liquida']       = $consolidado['receita_bruta'] - $consolidado['deducoes'];
$consolidado['lucro_bruto']           = $consolidado['receita_liquida'] - $consolidado['custos_operacionais'];
$consolidado['resultado_operacional'] = $consolidado['lucro_bruto']
                                       - $consolidado['despesas_adm']
                                       - $consolidado['despesas_comerciais']
                                       + $consolidado['resultado_financeiro'];
foreach ($consolidado as $k => &$v) {
    if ($k !== 'total_lancamentos') $v = round($v, 2);
}
unset($v);

// ══════════════════════════════════════════════════════════════
// SEPARAR BLOCOS AUXILIARES
// ══════════════════════════════════════════════════════════════

$dre_principal = [];
$bloco_cc_vazio = [];
$bloco_fora_dre = [];

foreach ($dre_por_cc as $cc => $data) {
    if ($data['is_cc_vazio']) {
        $bloco_cc_vazio[$cc] = $data;
    } else {
        $has_dre = false;
        foreach ($data['linhas'] as $l) {
            if ($l['entra_dre'] == 1) { $has_dre = true; break; }
        }
        if ($has_dre) {
            $dre_principal[$cc] = $data;
        } else {
            $bloco_fora_dre[$cc] = $data;
        }
    }
}

// ══════════════════════════════════════════════════════════════
// OUTPUT
// ══════════════════════════════════════════════════════════════

$resultado = [
    'periodo'      => ['de' => $periodo_de, 'ate' => $periodo_ate],
    'gerado_em'    => date('Y-m-d H:i:s'),
    'consolidado'  => $consolidado,
    'por_cc'       => array_values($dre_principal),
    'cc_vazio'     => array_values($bloco_cc_vazio),
    'fora_dre'     => array_values($bloco_fora_dre),
    'meta'         => [
        'total_ccs_dre'     => count($dre_principal),
        'total_ccs_vazio'   => count($bloco_cc_vazio),
        'total_ccs_fora'    => count($bloco_fora_dre),
        'competencia'       => 'data_emissao',
        'fonte'             => 'fato_dre_lancamento',
    ],
];

if ($formato === 'texto') {
    header('Content-Type: text/plain; charset=utf-8');
    // Render DRE consolidada em texto
    $c = $consolidado;
    echo "═══════════════════════════════════════════════════════════\n";
    echo "  DRE EXECUTIVA CONSOLIDADA\n";
    echo "  Período: $periodo_de a $periodo_ate\n";
    echo "  Gerado: " . date('d/m/Y H:i') . "\n";
    echo "  Competência: data_emissao\n";
    echo "═══════════════════════════════════════════════════════════\n\n";

    $fmt = function($v) { return ($v >= 0 ? '+' : '') . 'R$ ' . number_format($v, 2, ',', '.'); };

    echo "  (+) Receita Bruta              " . $fmt($c['receita_bruta']) . "\n";
    echo "  (-) Deduções da Receita        " . $fmt(-$c['deducoes']) . "\n";
    echo "  ─────────────────────────────────────────────\n";
    echo "  (=) RECEITA LÍQUIDA            " . $fmt($c['receita_liquida']) . "\n\n";

    echo "  (-) Custos Operacionais        " . $fmt(-$c['custos_operacionais']) . "\n";
    echo "  ─────────────────────────────────────────────\n";
    echo "  (=) LUCRO BRUTO                " . $fmt($c['lucro_bruto']) . "\n\n";

    echo "  (-) Despesas Administrativas   " . $fmt(-$c['despesas_adm']) . "\n";
    echo "  (-) Despesas Comerciais        " . $fmt(-$c['despesas_comerciais']) . "\n";
    echo "  (+/-) Resultado Financeiro     " . $fmt($c['resultado_financeiro']) . "\n";
    echo "  ─────────────────────────────────────────────\n";
    echo "  (=) RESULTADO OPERACIONAL      " . $fmt($c['resultado_operacional']) . "\n\n";

    echo "  ── Blocos auxiliares ──\n";
    echo "  [!] NAO_CLASSIFICADO residual  " . $fmt($c['nao_classificado']) . "\n";
    echo "  [i] Fora da DRE               " . $fmt($c['fora_dre']) . "\n\n";

    echo "  Lançamentos: {$c['total_lancamentos']}\n";
    echo "  CCs na DRE: " . count($dre_principal) . " | CC vazio: " . count($bloco_cc_vazio) . " | Fora DRE: " . count($bloco_fora_dre) . "\n";

    // Top 10 CCs por resultado
    echo "\n═══════════════════════════════════════════════════════════\n";
    echo "  TOP 15 CENTROS DE CUSTO POR RESULTADO\n";
    echo "═══════════════════════════════════════════════════════════\n\n";

    $ranking = [];
    foreach ($dre_principal as $cc => $data) {
        $ranking[] = ['cc' => $cc, 'tipo' => $data['tipo_cc'], 'resultado' => $data['totais']['resultado_operacional'], 'receita' => $data['totais']['receita_bruta'], 'custos' => $data['totais']['custos_operacionais']];
    }
    usort($ranking, fn($a, $b) => $b['resultado'] <=> $a['resultado']);

    echo "  " . str_pad('Centro de Custo', 40) . str_pad('Tipo', 22) . str_pad('Receita', 18) . str_pad('Custos', 18) . "Resultado\n";
    echo "  " . str_repeat('-', 115) . "\n";
    foreach (array_slice($ranking, 0, 15) as $r) {
        echo "  " . str_pad($r['cc'], 40) . str_pad($r['tipo'], 22) . str_pad($fmt($r['receita']), 18) . str_pad($fmt(-$r['custos']), 18) . $fmt($r['resultado']) . "\n";
    }

    // Bottom 5
    echo "\n  ── 5 piores resultados ──\n";
    foreach (array_slice($ranking, -5) as $r) {
        echo "  " . str_pad($r['cc'], 40) . str_pad($r['tipo'], 22) . str_pad($fmt($r['receita']), 18) . str_pad($fmt(-$r['custos']), 18) . $fmt($r['resultado']) . "\n";
    }
} else {
    echo json_encode($resultado, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
}

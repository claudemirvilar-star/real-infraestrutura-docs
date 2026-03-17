<?php
/**
 * ============================================================
 * DRE EXECUTIVA POR EMPRESA
 * ============================================================
 * Endpoint: GET /api/financeiro/dre_executiva_empresa.php
 *
 * Parâmetros:
 *   empresa     = id_estabelecimento (obrigatório, CSV para multi: 1146,1,1147)
 *   periodo_de  = YYYY-MM (obrigatório)
 *   periodo_ate = YYYY-MM (obrigatório)
 *   regime      = competencia | caixa (obrigatório)
 *   formato     = json (default) | texto
 *
 * Fonte: fato_dre_lancamento (materializada, com governança)
 *
 * Estrutura de resposta compatível com dre.php antigo:
 *   {ok, empresas, regime, periodo, consolidado, dre:[{grupo, subtotal, tooltip, itens, subgrupos}]}
 *
 * Regras:
 *   - competencia usa ano_mes (derivado de data_emissao)
 *   - caixa usa ano_mes_caixa (derivado de COALESCE(data_pagamento/recebimento, data_vencimento))
 *   - entra_dre=0 fica FORA do resultado (FORA_DRE, PATRIMONIAL, PESSOAL_SOCIO)
 *   - status_mapeamento=EXCLUIDO_ESPELHO é sempre ignorado
 *   - CC_VAZIO e NAO_CLASSIFICADO ficam visíveis em blocos separados
 *
 * Criado: 2026-03-15
 * ============================================================
 */
date_default_timezone_set('America/Sao_Paulo');
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

// ── Parâmetros ──────────────────────────────────────────────
$empresa_raw  = $_GET['empresa']     ?? null;
$periodo_de   = $_GET['periodo_de']  ?? null;
$periodo_ate  = $_GET['periodo_ate'] ?? null;
$regime       = $_GET['regime']      ?? null;
$formato      = $_GET['formato']     ?? 'json';

// ── Validação ───────────────────────────────────────────────
$erros = [];
if (!$empresa_raw)  $erros[] = 'Parâmetro empresa é obrigatório (ex: 1146 ou 1146,1)';
if (!$periodo_de)   $erros[] = 'Parâmetro periodo_de é obrigatório (YYYY-MM)';
if (!$periodo_ate)  $erros[] = 'Parâmetro periodo_ate é obrigatório (YYYY-MM)';
if (!$regime || !in_array($regime, ['competencia','caixa'])) {
    $erros[] = 'Parâmetro regime é obrigatório (competencia | caixa)';
}
if ($erros) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'erros' => $erros], JSON_UNESCAPED_UNICODE);
    exit;
}

// Parse empresas (CSV de ids)
$empresas = array_filter(array_map('trim', explode(',', $empresa_raw)));
if (empty($empresas)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'erros' => ['Nenhuma empresa válida informada']], JSON_UNESCAPED_UNICODE);
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
// COLUNA TEMPORAL conforme regime
// ══════════════════════════════════════════════════════════════
$col_periodo = ($regime === 'caixa') ? 'ano_mes_caixa' : 'ano_mes';

// ══════════════════════════════════════════════════════════════
// QUERY: buscar dados agrupados por classif_contabil
// ══════════════════════════════════════════════════════════════

// Placeholders para empresa
$emp_placeholders = implode(',', array_fill(0, count($empresas), '?'));

$sql = "
SELECT
    classif_contabil_erp,
    grupo_dre,
    subgrupo,
    entra_dre,
    status_mapeamento,
    SUM(valor_dre) AS valor_dre,
    SUM(valor_original) AS valor_original,
    COUNT(*) AS qtd
FROM fato_dre_lancamento
WHERE id_estabelecimento IN ($emp_placeholders)
  AND `$col_periodo` BETWEEN ? AND ?
  AND status_mapeamento != 'EXCLUIDO_ESPELHO'
GROUP BY classif_contabil_erp, grupo_dre, subgrupo, entra_dre, status_mapeamento
ORDER BY grupo_dre, subgrupo, classif_contabil_erp
";

$params = array_merge($empresas, [$periodo_de, $periodo_ate]);
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ══════════════════════════════════════════════════════════════
// MAPA DE GRUPO_DRE -> BLOCO DRE (para o consolidado)
// ══════════════════════════════════════════════════════════════

$bloco_map = [
    'RECEITA_SERVICOS'         => 'RECEITA_BRUTA',
    'RECEITA_LOCACAO'          => 'RECEITA_BRUTA',
    'RECEITA_VENDAS'           => 'RECEITA_BRUTA',
    'DEDUCAO_IMPOSTO'          => 'DEDUCOES',
    'CUSTO_MO_DIRETA'          => 'CUSTOS_OPERACIONAIS',
    'CUSTO_MATERIAL'           => 'CUSTOS_OPERACIONAIS',
    'CUSTO_EQUIPAMENTO'        => 'CUSTOS_OPERACIONAIS',
    'CUSTO_OPERACIONAL_OUTROS' => 'CUSTOS_OPERACIONAIS',
    'DESPESA_ADMINISTRATIVA'   => 'DESPESAS_ADMINISTRATIVAS',
    'DESPESA_COMERCIAL'        => 'DESPESAS_COMERCIAIS',
    'DESPESA_FINANCEIRA'       => 'RESULTADO_FINANCEIRO',
    'INVESTIMENTO'             => 'INVESTIMENTOS',
    'NAO_CLASSIFICADO'         => 'NAO_CLASSIFICADO',
    'PATRIMONIAL'              => 'FORA_DRE',
    'PESSOAL_SOCIO'            => 'FORA_DRE',
];

// ══════════════════════════════════════════════════════════════
// MAPA DE GRUPO_DRE -> LABEL AMIGÁVEL (para o Flutter)
// Compatibilidade com o dre.php antigo
// ══════════════════════════════════════════════════════════════

// Grupos de nível superior (como o Flutter espera)
$grupo_label_map = [
    'RECEITA_SERVICOS'         => 'Receita Bruta',
    'RECEITA_LOCACAO'          => 'Receita Bruta',
    'RECEITA_VENDAS'           => 'Receita Bruta',
    'DEDUCAO_IMPOSTO'          => 'Impostos',
    'CUSTO_MO_DIRETA'          => 'CUSTOS',
    'CUSTO_MATERIAL'           => 'CUSTOS',
    'CUSTO_EQUIPAMENTO'        => 'CUSTOS',
    'CUSTO_OPERACIONAL_OUTROS' => 'CUSTOS',
    'DESPESA_ADMINISTRATIVA'   => 'DESPESAS OPERACIONAIS',
    'DESPESA_COMERCIAL'        => 'DESPESAS OPERACIONAIS',
    'DESPESA_FINANCEIRA'       => 'DESPESAS OPERACIONAIS',
    'INVESTIMENTO'             => 'INVESTIMENTOS',
    'NAO_CLASSIFICADO'         => 'NAO_CLASSIFICADO',
    'PATRIMONIAL'              => 'FORA_DRE',
    'PESSOAL_SOCIO'            => 'FORA_DRE',
];

// Subgrupo label amigável (nível 2 dentro de CUSTOS e DESPESAS)
$subgrupo_label_map = [
    'CUSTO_MO_DIRETA'          => 'Pessoal - Encargos Trabalhistas',
    'CUSTO_MATERIAL'           => 'Gastos Gerais de Obra',
    'CUSTO_EQUIPAMENTO'        => 'Custo de Manutenção',
    'CUSTO_OPERACIONAL_OUTROS' => 'Gastos Gerais de Obra',
    'DESPESA_ADMINISTRATIVA'   => 'Administração',
    'DESPESA_COMERCIAL'        => 'Despesas Comerciais',
    'DESPESA_FINANCEIRA'       => 'Despesas Financeiras',
    'INVESTIMENTO'             => 'Investimentos',
    'NAO_CLASSIFICADO'         => 'Não Classificado',
];

// ══════════════════════════════════════════════════════════════
// CONSTRUIR ESTRUTURA HIERÁRQUICA (grupo -> subgrupos -> itens)
// ══════════════════════════════════════════════════════════════

$consolidado = [
    'receita_bruta'           => 0,
    'deducoes'                => 0,
    'receita_liquida'         => 0,
    'custos_operacionais'     => 0,
    'lucro_bruto'             => 0,
    'despesas_administrativas' => 0,
    'despesas_comerciais'     => 0,
    'resultado_financeiro'    => 0,
    'resultado_operacional'   => 0,
    'investimentos'           => 0,
    'resultado_final'         => 0,
    'fora_dre'                => 0,
    'nao_classificado'        => 0,
    'cc_vazio'                => 0,
    'total_lancamentos'       => 0,
];

// Acumuladores hierárquicos: grupo_pai -> subgrupo -> items
$grupos_tree = [];

// Contagem cc_vazio separada
$total_cc_vazio_abs = 0;
$cc_vazio_qtd = 0;

foreach ($rows as $r) {
    $grupo_dre   = $r['grupo_dre'] ?? 'NAO_CLASSIFICADO';
    $subgrupo    = $r['subgrupo'] ?? 'GENERICO';
    $classif     = $r['classif_contabil_erp'];
    $entra_dre   = (int) $r['entra_dre'];
    $status      = $r['status_mapeamento'];
    $val_dre     = (float) $r['valor_dre'];
    $val_abs     = abs((float) $r['valor_original']);
    $qtd         = (int) $r['qtd'];

    $consolidado['total_lancamentos'] += $qtd;

    // CC_VAZIO tracking (across all groups)
    if ($status === 'CC_VAZIO') {
        $total_cc_vazio_abs += $val_abs;
        $cc_vazio_qtd += $qtd;
    }

    // Acumular no consolidado por bloco
    $bloco = $bloco_map[$grupo_dre] ?? 'FORA_DRE';

    // Só soma no consolidado se entra_dre=1
    // REGRA DE SINAIS:
    //   valor_dre = valor_original * sinal_dre
    //   Receitas (sinal=+1):  valor_dre positivo = receita real
    //   Custos (sinal=-1):    valor_dre negativo = custo real, positivo = estorno
    //   Fora DRE (sinal=0):  valor_dre = 0
    //
    //   No consolidado, custos/deduções/despesas usam SUM(valor_dre) e negam no final,
    //   preservando estornos (valor_original negativo × sinal -1 = valor_dre positivo).
    //   Isso garante alinhamento com a vw_dre_base_cc.
    if ($entra_dre === 1) {
        switch ($bloco) {
            case 'RECEITA_BRUTA':
                $consolidado['receita_bruta'] += $val_dre;
                break;
            case 'DEDUCOES':
                // val_dre é negativo para impostos (sinal=-1), somamos e negamos no final
                $consolidado['deducoes'] += $val_dre;
                break;
            case 'CUSTOS_OPERACIONAIS':
                // val_dre é negativo para custos (sinal=-1), estornos ficam positivos
                $consolidado['custos_operacionais'] += $val_dre;
                break;
            case 'DESPESAS_ADMINISTRATIVAS':
                $consolidado['despesas_administrativas'] += $val_dre;
                break;
            case 'DESPESAS_COMERCIAIS':
                $consolidado['despesas_comerciais'] += $val_dre;
                break;
            case 'RESULTADO_FINANCEIRO':
                // Receitas financeiras (sinal +1) somam positivo
                // Despesas financeiras (sinal -1) somam negativo
                $consolidado['resultado_financeiro'] += $val_dre;
                break;
            case 'INVESTIMENTOS':
                $consolidado['investimentos'] += $val_dre;
                break;
            case 'NAO_CLASSIFICADO':
                $consolidado['nao_classificado'] += $val_dre;
                break;
        }
    } else {
        // entra_dre=0 -> fora_dre
        $consolidado['fora_dre'] += $val_abs;
    }

    // ── Construir árvore para output de grupos ──
    $grupo_pai_label = $grupo_label_map[$grupo_dre] ?? 'Outros';
    $sub_label       = $subgrupo_label_map[$grupo_dre] ?? $grupo_dre;

    // Inicializar grupo pai
    if (!isset($grupos_tree[$grupo_pai_label])) {
        $grupos_tree[$grupo_pai_label] = [
            'subtotal' => 0,
            'subgrupos' => [],
            'itens' => [],
        ];
    }

    // Para grupos que precisam de subgrupos (CUSTOS, DESPESAS OPERACIONAIS)
    $precisa_sub = in_array($grupo_pai_label, ['CUSTOS', 'DESPESAS OPERACIONAIS']);

    if ($precisa_sub) {
        if (!isset($grupos_tree[$grupo_pai_label]['subgrupos'][$sub_label])) {
            $grupos_tree[$grupo_pai_label]['subgrupos'][$sub_label] = [
                'subtotal' => 0,
                'itens' => [],
            ];
        }
        $grupos_tree[$grupo_pai_label]['subgrupos'][$sub_label]['subtotal'] += $val_dre;
        $grupos_tree[$grupo_pai_label]['subgrupos'][$sub_label]['itens'][] = [
            'codigo'    => $classif,
            'descricao' => $classif,
            'valor'     => round($val_dre, 2),
        ];
    } else {
        $grupos_tree[$grupo_pai_label]['itens'][] = [
            'codigo'    => $classif,
            'descricao' => $classif,
            'valor'     => round($val_dre, 2),
        ];
    }

    $grupos_tree[$grupo_pai_label]['subtotal'] += $val_dre;
}

// ── Negar acumulados de custo/despesa (estavam negativos por sinal_dre=-1) ──
// Após esta etapa, todos os campos são POSITIVOS no consolidado:
//   receita_bruta = positivo (receita real)
//   deducoes = positivo (imposto real pago)
//   custos_operacionais = positivo (custo real)
//   etc.
$consolidado['deducoes']               = abs($consolidado['deducoes']);
$consolidado['custos_operacionais']    = abs($consolidado['custos_operacionais']);
$consolidado['despesas_administrativas'] = abs($consolidado['despesas_administrativas']);
$consolidado['despesas_comerciais']    = abs($consolidado['despesas_comerciais']);
$consolidado['investimentos']          = abs($consolidado['investimentos']);
// resultado_financeiro mantém sinal: positivo = receita financeira líquida, negativo = despesa líquida

// ── Calcular derivados ──
$consolidado['receita_liquida']       = $consolidado['receita_bruta'] - $consolidado['deducoes'];
$consolidado['lucro_bruto']           = $consolidado['receita_liquida'] - $consolidado['custos_operacionais'];
$consolidado['resultado_operacional'] = $consolidado['lucro_bruto']
                                        - $consolidado['despesas_administrativas']
                                        - $consolidado['despesas_comerciais']
                                        + $consolidado['resultado_financeiro'];
// Investimentos ficam ABAIXO do resultado operacional (não contaminam o operacional)
$consolidado['resultado_final']       = $consolidado['resultado_operacional']
                                        - $consolidado['investimentos'];
$consolidado['cc_vazio']              = $total_cc_vazio_abs;

// Arredondar consolidado
foreach ($consolidado as &$v) {
    if (is_float($v)) $v = round($v, 2);
}
unset($v);

// ══════════════════════════════════════════════════════════════
// FORMATAR ARRAY DRE PARA OUTPUT (compatível com dre.php)
// ══════════════════════════════════════════════════════════════

// Ordem de apresentação dos grupos
$ordem_grupos = [
    'Receita Bruta',
    'Impostos',
    'Receita Líquida após Impostos',
    'CUSTOS',
    'LUCRO BRUTO',
    'DESPESAS OPERACIONAIS',
    'RESULTADO OPERACIONAL',
    'INVESTIMENTOS',
    'RESULTADO FINAL',
    'NAO_CLASSIFICADO',
    'FORA_DRE',
];

$dre_output = [];

foreach ($ordem_grupos as $grupo_nome) {
    // Grupos calculados (sem dados diretos na fato)
    if ($grupo_nome === 'Receita Líquida após Impostos') {
        $dre_output[] = [
            'grupo'    => $grupo_nome,
            'subtotal' => round($consolidado['receita_liquida'], 2),
            'tooltip'  => 'Receita Bruta menos Impostos/Deduções',
            'itens'    => [],
            'subgrupos' => [],
        ];
        continue;
    }

    if ($grupo_nome === 'LUCRO BRUTO') {
        $dre_output[] = [
            'grupo'    => $grupo_nome,
            'subtotal' => round($consolidado['lucro_bruto'], 2),
            'tooltip'  => 'Receita Líquida menos Custos Operacionais',
            'itens'    => [],
            'subgrupos' => [],
        ];
        continue;
    }

    if ($grupo_nome === 'RESULTADO OPERACIONAL') {
        $dre_output[] = [
            'grupo'    => $grupo_nome,
            'subtotal' => round($consolidado['resultado_operacional'], 2),
            'tooltip'  => 'Lucro Bruto menos Despesas Operacionais + Resultado Financeiro',
            'itens'    => [],
            'subgrupos' => [],
        ];
        continue;
    }

    if ($grupo_nome === 'INVESTIMENTOS') {
        $tree = $grupos_tree['INVESTIMENTOS'] ?? ['subtotal' => 0, 'itens' => [], 'subgrupos' => []];
        if ($consolidado['investimentos'] == 0 && empty($tree['itens'])) continue;

        // Agrupar itens por classif
        $itens_inv = [];
        foreach ($tree['itens'] as $item) {
            $chave = $item['codigo'];
            if (!isset($itens_inv[$chave])) {
                $itens_inv[$chave] = $item;
            } else {
                $itens_inv[$chave]['valor'] = round($itens_inv[$chave]['valor'] + $item['valor'], 2);
            }
        }

        $dre_output[] = [
            'grupo'    => 'INVESTIMENTOS',
            'subtotal' => round($tree['subtotal'], 2),
            'tooltip'  => 'Aquisição de veículos, equipamentos e máquinas (CAPEX) — não impacta resultado operacional',
            'itens'    => array_values($itens_inv),
            'subgrupos' => [],
        ];
        continue;
    }

    if ($grupo_nome === 'RESULTADO FINAL') {
        $dre_output[] = [
            'grupo'    => 'RESULTADO FINAL',
            'subtotal' => round($consolidado['resultado_final'], 2),
            'tooltip'  => 'Resultado Operacional menos Investimentos',
            'itens'    => [],
            'subgrupos' => [],
        ];
        continue;
    }

    if ($grupo_nome === 'NAO_CLASSIFICADO') {
        $val = $consolidado['nao_classificado'];
        if ($val == 0 && !isset($grupos_tree[$grupo_nome])) continue;
        $tree = $grupos_tree[$grupo_nome] ?? ['subtotal' => 0, 'itens' => [], 'subgrupos' => []];
        $dre_output[] = [
            'grupo'    => 'Não Classificado',
            'subtotal' => round($tree['subtotal'], 2),
            'tooltip'  => 'Lançamentos com classificação contábil não mapeada na dim_classif_contabil',
            'itens'    => $tree['itens'],
            'subgrupos' => [],
        ];
        continue;
    }

    if ($grupo_nome === 'FORA_DRE') {
        $tree = $grupos_tree[$grupo_nome] ?? ['subtotal' => 0, 'itens' => [], 'subgrupos' => []];
        if (empty($tree['itens']) && $tree['subtotal'] == 0) continue;
        $dre_output[] = [
            'grupo'    => 'Fora da DRE',
            'subtotal' => round($consolidado['fora_dre'], 2),
            'tooltip'  => 'Lançamentos patrimoniais, pessoais ou de sócio — não impactam resultado operacional (entra_dre=0)',
            'itens'    => $tree['itens'],
            'subgrupos' => [],
        ];
        continue;
    }

    // Grupos normais (Receita Bruta, Impostos, CUSTOS, DESPESAS OPERACIONAIS)
    if (!isset($grupos_tree[$grupo_nome])) continue;
    $tree = $grupos_tree[$grupo_nome];

    // Formatar subgrupos
    $subs_output = [];
    foreach ($tree['subgrupos'] as $sub_nome => $sub_data) {
        // Agrupar itens por classif (somar duplicatas se houver)
        $itens_agrupados = [];
        foreach ($sub_data['itens'] as $item) {
            $chave = $item['codigo'];
            if (!isset($itens_agrupados[$chave])) {
                $itens_agrupados[$chave] = $item;
            } else {
                $itens_agrupados[$chave]['valor'] = round($itens_agrupados[$chave]['valor'] + $item['valor'], 2);
            }
        }

        $subs_output[] = [
            'grupo'    => $sub_nome,
            'subtotal' => round($sub_data['subtotal'], 2),
            'tooltip'  => '',
            'itens'    => array_values($itens_agrupados),
            'subgrupos' => [],
        ];
    }

    // Agrupar itens diretos do grupo por classif
    $itens_diretos_agrupados = [];
    foreach ($tree['itens'] as $item) {
        $chave = $item['codigo'];
        if (!isset($itens_diretos_agrupados[$chave])) {
            $itens_diretos_agrupados[$chave] = $item;
        } else {
            $itens_diretos_agrupados[$chave]['valor'] = round($itens_diretos_agrupados[$chave]['valor'] + $item['valor'], 2);
        }
    }

    // Tooltip por grupo
    $tooltips = [
        'Receita Bruta'        => 'Soma de receitas de serviços, locação e vendas (sinal_dre=+1)',
        'Impostos'             => 'Deduções sobre receita: impostos, ISS, ICMS (sinal_dre=-1)',
        'CUSTOS'               => 'Custos operacionais: MO direta, materiais, equipamentos, outros custos de obra',
        'DESPESAS OPERACIONAIS' => 'Despesas administrativas, comerciais e financeiras',
        'INVESTIMENTOS'        => 'Aquisição de veículos, equipamentos e máquinas (CAPEX)',
    ];

    $dre_output[] = [
        'grupo'     => $grupo_nome,
        'subtotal'  => round($tree['subtotal'], 2),
        'tooltip'   => $tooltips[$grupo_nome] ?? '',
        'itens'     => array_values($itens_diretos_agrupados),
        'subgrupos' => $subs_output,
    ];
}

// ══════════════════════════════════════════════════════════════
// OUTPUT
// ══════════════════════════════════════════════════════════════

$resultado = [
    'ok'           => true,
    'empresas'     => $empresas,
    'regime'       => $regime,
    'periodo'      => ['de' => $periodo_de, 'ate' => $periodo_ate],
    'gerado_em'    => date('Y-m-d H:i:s'),
    'consolidado'  => $consolidado,
    'dre'          => $dre_output,
    'meta'         => [
        'fonte'              => 'fato_dre_lancamento',
        'competencia'        => ($regime === 'competencia') ? 'ano_mes (data_emissao)' : 'ano_mes_caixa (COALESCE data_pagamento/recebimento, data_vencimento)',
        'total_cc_vazio_qtd' => $cc_vazio_qtd,
        'total_cc_vazio_abs' => round($total_cc_vazio_abs, 2),
    ],
];

if ($formato === 'texto') {
    header('Content-Type: text/plain; charset=utf-8');

    $c = $consolidado;
    $fmt = function($v) { return ($v >= 0 ? '+' : '') . 'R$ ' . number_format($v, 2, ',', '.'); };

    echo "═══════════════════════════════════════════════════════════\n";
    echo "  DRE EXECUTIVA POR EMPRESA\n";
    echo "  Empresas: " . implode(', ', $empresas) . "\n";
    echo "  Período: $periodo_de a $periodo_ate\n";
    echo "  Regime: $regime\n";
    echo "  Gerado: " . date('d/m/Y H:i') . "\n";
    echo "  Fonte: fato_dre_lancamento\n";
    echo "═══════════════════════════════════════════════════════════\n\n";

    echo "  (+) Receita Bruta              " . $fmt($c['receita_bruta']) . "\n";
    echo "  (-) Deduções da Receita        " . $fmt(-$c['deducoes']) . "\n";
    echo "  ─────────────────────────────────────────────\n";
    echo "  (=) RECEITA LÍQUIDA            " . $fmt($c['receita_liquida']) . "\n\n";

    echo "  (-) Custos Operacionais        " . $fmt(-$c['custos_operacionais']) . "\n";
    echo "  ─────────────────────────────────────────────\n";
    echo "  (=) LUCRO BRUTO                " . $fmt($c['lucro_bruto']) . "\n\n";

    echo "  (-) Despesas Administrativas   " . $fmt(-$c['despesas_administrativas']) . "\n";
    echo "  (-) Despesas Comerciais        " . $fmt(-$c['despesas_comerciais']) . "\n";
    echo "  (+/-) Resultado Financeiro     " . $fmt($c['resultado_financeiro']) . "\n";
    echo "  ─────────────────────────────────────────────\n";
    echo "  (=) RESULTADO OPERACIONAL      " . $fmt($c['resultado_operacional']) . "\n\n";

    if ($c['investimentos'] > 0) {
        echo "  (-) Investimentos (CAPEX)      " . $fmt(-$c['investimentos']) . "\n";
        echo "  ─────────────────────────────────────────────\n";
        echo "  (=) RESULTADO FINAL            " . $fmt($c['resultado_final']) . "\n\n";
    }

    echo "  ── Blocos auxiliares ──\n";
    echo "  [!] NAO_CLASSIFICADO           " . $fmt($c['nao_classificado']) . "\n";
    echo "  [i] Fora da DRE               R$ " . number_format($c['fora_dre'], 2, ',', '.') . "\n";
    echo "  [?] CC Vazio (abs)            R$ " . number_format($c['cc_vazio'], 2, ',', '.') . " ({$cc_vazio_qtd} lçtos)\n\n";

    echo "  Lançamentos: {$c['total_lancamentos']}\n";
} else {
    echo json_encode($resultado, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
}

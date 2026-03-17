<?php
// ======================================================
// ANCHOR: DONNA_DRE_ANALISE_V1
// Arquivo: /public_html/app/financeiro/donna_dre_analise.php
// Objetivo: Motor de análise DRE multi-perspectiva (CFO + CEO + Controller)
// Chama API DRE existente + Claude API para análise inteligente
// Criado: 2026-03-17
// ======================================================

/**
 * Gera análise DRE multi-perspectiva usando dados reais + Claude.
 *
 * @param array $params [
 *   'periodo_de'  => 'YYYY-MM',
 *   'periodo_ate' => 'YYYY-MM',
 *   'empresa'     => 'all' | '1146' | '1146,1',
 *   'regime'      => 'competencia' | 'caixa',
 * ]
 * @return array [
 *   'ok'          => bool,
 *   'texto'       => string (análise formatada para Telegram/WhatsApp),
 *   'audio_texto' => string (versão limpa para TTS),
 *   'consolidado' => array (dados numéricos brutos),
 *   'erro'        => string|null,
 * ]
 */
function donna_dre_analise(array $params): array {

    $log_dir = dirname(__DIR__) . "/telegram/runtime";
    if (!is_dir($log_dir)) @mkdir($log_dir, 0755, true);
    $log_file = $log_dir . "/donna_dre_analise.log";

    $ts = date("Y-m-d H:i:s");

    // ── Parâmetros com defaults ──
    $periodo_de  = $params['periodo_de']  ?? date('Y-m');
    $periodo_ate = $params['periodo_ate'] ?? date('Y-m');
    $empresa     = $params['empresa']     ?? 'all';
    $regime      = $params['regime']      ?? 'competencia';

    // Mapear "all" para todas as empresas
    $empresa_ids = ($empresa === 'all') ? '1,1145,1146,1147' : $empresa;

    @file_put_contents($log_file,
        "[$ts] INICIO periodo={$periodo_de}~{$periodo_ate} empresa={$empresa_ids} regime={$regime}\n",
        FILE_APPEND
    );

    // ══════════════════════════════════════════════════════════════
    // ETAPA 1: Buscar dados DRE via API existente (HTTP local)
    // ══════════════════════════════════════════════════════════════

    $api_url = "https://globalsinalizacao.online/api/financeiro/dre_executiva_empresa.php?"
        . http_build_query([
            'empresa'     => $empresa_ids,
            'periodo_de'  => $periodo_de,
            'periodo_ate' => $periodo_ate,
            'regime'      => $regime,
            'formato'     => 'json',
        ]);

    $ch = curl_init($api_url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $api_resp = curl_exec($ch);
    $api_http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $api_err  = curl_error($ch);
    curl_close($ch);

    if ($api_err || $api_http !== 200) {
        $msg = "Erro ao buscar DRE (HTTP {$api_http}): {$api_err}";
        @file_put_contents($log_file, "[$ts] ERRO_API: {$msg}\n", FILE_APPEND);
        return ['ok' => false, 'texto' => '', 'audio_texto' => '', 'consolidado' => [], 'erro' => $msg];
    }

    $dre_data = json_decode($api_resp, true);
    if (!($dre_data['ok'] ?? false)) {
        $erros = implode(', ', $dre_data['erros'] ?? ['resposta inválida']);
        @file_put_contents($log_file, "[$ts] ERRO_DRE: {$erros}\n", FILE_APPEND);
        return ['ok' => false, 'texto' => '', 'audio_texto' => '', 'consolidado' => [], 'erro' => "API DRE: {$erros}"];
    }

    $consolidado = $dre_data['consolidado'] ?? [];
    $dre_grupos  = $dre_data['dre'] ?? [];

    // ══════════════════════════════════════════════════════════════
    // ETAPA 2: Montar resumo textual dos dados para o prompt
    // ══════════════════════════════════════════════════════════════

    $c = $consolidado;
    $fmt = function($v) {
        $sign = $v >= 0 ? '+' : '';
        return $sign . 'R$ ' . number_format(abs($v), 2, ',', '.');
    };

    $dados_texto = "═══ DRE EXECUTIVA ═══\n"
        . "Empresas: {$empresa_ids}\n"
        . "Período: {$periodo_de} a {$periodo_ate}\n"
        . "Regime: {$regime}\n"
        . "Lançamentos: {$c['total_lancamentos']}\n\n"
        . "(+) Receita Bruta:            " . $fmt($c['receita_bruta']) . "\n"
        . "(-) Deduções (impostos):      " . $fmt(-$c['deducoes']) . "\n"
        . "(=) Receita Líquida:          " . $fmt($c['receita_liquida']) . "\n\n"
        . "(-) Custos Operacionais:      " . $fmt(-$c['custos_operacionais']) . "\n"
        . "(=) Lucro Bruto:              " . $fmt($c['lucro_bruto']) . "\n\n"
        . "(-) Desp. Administrativas:    " . $fmt(-$c['despesas_administrativas']) . "\n"
        . "(-) Desp. Comerciais:         " . $fmt(-$c['despesas_comerciais']) . "\n"
        . "(±) Resultado Financeiro:     " . $fmt($c['resultado_financeiro']) . "\n"
        . "(=) Resultado Operacional:    " . $fmt($c['resultado_operacional']) . "\n\n";

    if (($c['investimentos'] ?? 0) > 0) {
        $dados_texto .= "(-) Investimentos (CAPEX):    " . $fmt(-$c['investimentos']) . "\n";
    }

    $dados_texto .= "(=) Resultado Final:          " . $fmt($c['resultado_final']) . "\n\n";

    if (($c['nao_classificado'] ?? 0) != 0) {
        $dados_texto .= "[!] Não classificado:         " . $fmt($c['nao_classificado']) . "\n";
    }
    if (($c['cc_vazio'] ?? 0) > 0) {
        $dados_texto .= "[?] CC Vazio (abs):           R$ " . number_format($c['cc_vazio'], 2, ',', '.') . "\n";
    }

    // Adicionar detalhes dos grupos principais (top itens por valor)
    $dados_texto .= "\n── Detalhamento por grupo ──\n";
    foreach ($dre_grupos as $grupo) {
        $nome = $grupo['grupo'] ?? '';
        $sub = $grupo['subtotal'] ?? 0;
        if ($sub == 0 && empty($grupo['itens']) && empty($grupo['subgrupos'])) continue;

        $dados_texto .= "\n{$nome}: " . $fmt($sub) . "\n";

        // Subgrupos
        foreach (($grupo['subgrupos'] ?? []) as $sg) {
            $dados_texto .= "  └ {$sg['grupo']}: " . $fmt($sg['subtotal']) . "\n";
            // Top 3 itens do subgrupo por valor absoluto
            $itens_sorted = $sg['itens'] ?? [];
            usort($itens_sorted, fn($a, $b) => abs($b['valor']) <=> abs($a['valor']));
            $top = array_slice($itens_sorted, 0, 3);
            foreach ($top as $item) {
                $dados_texto .= "      • {$item['descricao']}: " . $fmt($item['valor']) . "\n";
            }
        }

        // Itens diretos (top 5)
        $itens_dir = $grupo['itens'] ?? [];
        if (!empty($itens_dir) && empty($grupo['subgrupos'])) {
            usort($itens_dir, fn($a, $b) => abs($b['valor']) <=> abs($a['valor']));
            $top = array_slice($itens_dir, 0, 5);
            foreach ($top as $item) {
                $dados_texto .= "  • {$item['descricao']}: " . $fmt($item['valor']) . "\n";
            }
        }
    }

    // ══════════════════════════════════════════════════════════════
    // ETAPA 3: Calcular KPIs derivados para o prompt
    // ══════════════════════════════════════════════════════════════

    $rec_bruta = $c['receita_bruta'] ?: 1; // Evitar divisão por zero
    $rec_liq   = $c['receita_liquida'] ?: 1;

    $kpis = [
        'margem_bruta'       => round(($c['lucro_bruto'] / $rec_liq) * 100, 1),
        'margem_operacional' => round(($c['resultado_operacional'] / $rec_liq) * 100, 1),
        'margem_liquida'     => round(($c['resultado_final'] / $rec_liq) * 100, 1),
        'carga_tributaria'   => round(($c['deducoes'] / $rec_bruta) * 100, 1),
        'custo_receita'      => round(($c['custos_operacionais'] / $rec_liq) * 100, 1),
        'sga_receita'        => round((($c['despesas_administrativas'] + $c['despesas_comerciais']) / $rec_liq) * 100, 1),
        'ebitda_estimado'    => $c['resultado_operacional'] - ($c['resultado_financeiro'] ?? 0),
    ];

    $kpis_texto = "\n── KPIs Derivados ──\n"
        . "Margem Bruta: {$kpis['margem_bruta']}%\n"
        . "Margem Operacional: {$kpis['margem_operacional']}%\n"
        . "Margem Líquida: {$kpis['margem_liquida']}%\n"
        . "Carga Tributária: {$kpis['carga_tributaria']}% da receita bruta\n"
        . "Custo/Receita Líquida: {$kpis['custo_receita']}%\n"
        . "SG&A/Receita Líquida: {$kpis['sga_receita']}%\n"
        . "EBITDA estimado: " . $fmt($kpis['ebitda_estimado']) . "\n";

    $dados_completos = $dados_texto . $kpis_texto;

    // ══════════════════════════════════════════════════════════════
    // ETAPA 4: Chamar Claude API com prompt multi-perspectiva
    // ══════════════════════════════════════════════════════════════

    $cfg = require dirname(__DIR__) . "/_secrets/anthropic_config.php";
    $apiKey = $cfg["api_key"] ?? "";
    $model  = $cfg["model_default"] ?? "claude-sonnet-4-20250514";

    if (!$apiKey) {
        @file_put_contents($log_file, "[$ts] ERRO: chave Anthropic vazia\n", FILE_APPEND);
        return ['ok' => false, 'texto' => '', 'audio_texto' => '', 'consolidado' => $consolidado, 'erro' => 'Chave Anthropic não configurada'];
    }

    $system_prompt = <<<'PROMPT'
Você é um painel de 3 conselheiros de diretoria analisando a DRE real de um grupo de engenharia/infraestrutura rodoviária (Grupo Real Infraestrutura). Cada um fala na primeira pessoa, com tom profissional e direto.

📊 *DIRETOR FINANCEIRO (CFO):*
- Foco: margens (bruta, operacional, líquida), análise vertical (%receita), fluxo de caixa implícito, EBITDA estimado
- Framework: Common-Size Analysis + DuPont
- KPIs: margem bruta, margem EBITDA, custo/receita ratio, SG&A/receita
- Tom: preciso, numérico, orientado a risco e retorno

📈 *DIRETOR EXECUTIVO (CEO):*
- Foco: crescimento, tendências, mix de receita, decisões estratégicas
- Framework: Análise Horizontal + SCQA (Situação-Complicação-Questão-Resposta)
- KPIs: crescimento receita, eficiência operacional, concentração
- Tom: assertivo, visionário, orientado a ação

📋 *CONTROLLER CONTÁBIL:*
- Foco: conformidade, regime competência vs caixa, classificações, alertas fiscais
- Framework: Reconciliação competência×caixa + Checklist CPC/IFRS
- KPIs: alíquota efetiva impostos, deduções/receita bruta, itens não classificados, CC vazio
- Tom: técnico, normativo, orientado a conformidade

Regras OBRIGATÓRIAS:
1. NUNCA inventar dados — usar APENAS os números fornecidos na DRE
2. Calcular KPIs derivados (margens, %, ratios) a partir dos dados reais
3. Cada perspectiva: 3-5 parágrafos CURTOS
4. Fechar com *AÇÕES RECOMENDADAS* (3-5 bullets prioritizados)
5. Formato WhatsApp/Telegram: usar *negrito* para destaques
6. Máximo 3000 caracteres total
7. Usar linguagem profissional mas acessível (o destinatário é o diretor/dono)
8. Se houver itens "Não Classificado" ou "CC Vazio" relevantes, alertar
PROMPT;

    $user_message = "Analise a DRE abaixo do Grupo Real Infraestrutura:\n\n" . $dados_completos;

    $payload = [
        "model"      => $model,
        "max_tokens" => 1500,
        "system"     => $system_prompt,
        "messages"   => [
            ["role" => "user", "content" => $user_message]
        ]
    ];

    $ch = curl_init("https://api.anthropic.com/v1/messages");
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            "Content-Type: application/json",
            "x-api-key: " . $apiKey,
            "anthropic-version: 2023-06-01",
        ],
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_TIMEOUT        => 60,
    ]);

    $resp = curl_exec($ch);
    $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    @file_put_contents($log_file,
        "[$ts] CLAUDE http={$http} err={$err} resp_len=" . strlen($resp) . "\n",
        FILE_APPEND
    );

    if ($err || $http !== 200) {
        $detail = substr($resp, 0, 300);
        @file_put_contents($log_file, "[$ts] CLAUDE_ERRO: {$detail}\n", FILE_APPEND);
        return ['ok' => false, 'texto' => '', 'audio_texto' => '', 'consolidado' => $consolidado, 'erro' => "Claude API HTTP {$http}: {$err}"];
    }

    $json = json_decode($resp, true);
    $analise_texto = trim($json["content"][0]["text"] ?? "");

    if ($analise_texto === "") {
        return ['ok' => false, 'texto' => '', 'audio_texto' => '', 'consolidado' => $consolidado, 'erro' => "Claude retornou texto vazio"];
    }

    // ── Header com contexto ──
    $header = "📊 *Análise DRE — Grupo Real Infraestrutura*\n"
        . "Período: *{$periodo_de} a {$periodo_ate}* | Regime: *{$regime}*\n"
        . "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";

    $texto_final = $header . $analise_texto;

    // Versão para TTS (sem formatação)
    $audio_texto = "Análise DRE do Grupo Real Infraestrutura. "
        . "Período: {$periodo_de} a {$periodo_ate}. Regime {$regime}. "
        . $analise_texto;

    @file_put_contents($log_file,
        "[$ts] OK texto_len=" . strlen($texto_final) . " audio_len=" . strlen($audio_texto) . "\n",
        FILE_APPEND
    );

    return [
        'ok'          => true,
        'texto'       => $texto_final,
        'audio_texto' => $audio_texto,
        'consolidado' => $consolidado,
        'erro'        => null,
    ];
}

// ======================================================
// FIM ANCHOR: DONNA_DRE_ANALISE_V1
// ======================================================

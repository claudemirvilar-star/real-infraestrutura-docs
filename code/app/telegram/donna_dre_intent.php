<?php
// ======================================================
// ANCHOR: DONNA_DRE_INTENT_V1
// Arquivo: /public_html/app/telegram/donna_dre_intent.php
// Objetivo: Detectar intenção DRE em mensagem do usuário
// Criado: 2026-03-17
// ======================================================

/**
 * Detecta se a mensagem do usuário é uma solicitação de análise DRE.
 *
 * @param string $text Texto da mensagem (já em minúsculo)
 * @return bool true se é intenção DRE
 */
function donna_dre_detect(string $text): bool {
    $text = mb_strtolower(trim($text), 'UTF-8');

    // Remover acentos para matching mais robusto
    $text_norm = _dre_remove_acentos($text);

    // ── Keywords primárias (match direto = DRE) ──
    $keywords_fortes = [
        'dre',
        'demonstracao de resultado',
        'demonstracao do resultado',
        'demonstrativo de resultado',
        'resultado do exercicio',
        'resultado financeiro da empresa',
        'analise financeira',
        'relatorio financeiro',
        'relatorio dre',
    ];

    foreach ($keywords_fortes as $kw) {
        if (strpos($text_norm, $kw) !== false) {
            return true;
        }
    }

    // ── Combinações (precisa de 2+ termos para evitar falso positivo) ──
    $tem_verbo = (bool) preg_match('/\b(mostra|mostre|quero|preciso|gera|gerar|faz|faca|envia|manda|analisa|analise|como esta|como vai|me da|pega)\b/', $text_norm);
    $tem_financeiro = (bool) preg_match('/\b(receita|faturamento|faturou|faturamos|lucro|prejuizo|margem|custos? totais?|despesas? totais?|resultado|balanco)\b/', $text_norm);
    $tem_periodo = (bool) preg_match('/\b(janeiro|fevereiro|marco|abril|maio|junho|julho|agosto|setembro|outubro|novembro|dezembro|trimestre|semestre|mes passado|este mes|ultimo mes|ultimos?\s+\d+\s+mes)\b/', $text_norm);
    $tem_empresa = (bool) preg_match('/\b(empresa|real\s*(infra|defensa|sinalizacao|engenharia)|grupo\s*real|todas?\s*as?\s*empresas?)\b/', $text_norm);

    // verbo + financeiro = DRE
    if ($tem_verbo && $tem_financeiro) return true;

    // financeiro + período = DRE
    if ($tem_financeiro && $tem_periodo) return true;

    // "quanto faturamos/lucramos" etc
    if (preg_match('/\b(quanto|qual)\b.*\b(fatur|lucr|receit|gast|despesa|custo)/i', $text_norm)) {
        return true;
    }

    // "como estão as despesas/receitas/custos" etc
    if (preg_match('/\b(como)\b.*\b(despesa|receita|custo|faturamento|lucro|margem|resultado)/i', $text_norm)) {
        return true;
    }

    // financeiro sozinho com contexto temporal
    if ($tem_financeiro && preg_match('/\b(esse|este|nesse|neste|mes|trimestre|semestre|ano)\b/', $text_norm)) {
        return true;
    }

    return false;
}

/**
 * Remove acentos para matching normalizado.
 */
function _dre_remove_acentos(string $str): string {
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
// FIM ANCHOR: DONNA_DRE_INTENT_V1
// ======================================================

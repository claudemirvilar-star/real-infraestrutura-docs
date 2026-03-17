<?php
// ======================================================
// ANCHOR: DONNA_BRAIN_CLAUDE_V1
// Arquivo: /public_html/app/whatsapp/donna_brain_claude.php
// Objetivo:
// - Cérebro inteligente da Donna usando Claude API
// - Interpreta linguagem natural
// - Consulta dados do sistema quando necessário
// - Retorna resposta formatada para WhatsApp/Telegram
// ======================================================

function donna_brain_respond(string $userText, array $context = []): string {

    $cfg = require __DIR__ . "/../_secrets/anthropic_config.php";
    $apiKey = $cfg["api_key"] ?? "";
    $model = $cfg["model_default"] ?? "claude-sonnet-4-20250514";

    if (!$apiKey) {
        return "Donna: erro interno (chave IA não configurada).";
    }

    $from = $context["from"] ?? "desconhecido";
    $fromName = $context["from_name"] ?? "";
    $isAudio = $context["is_audio"] ?? false;

    // Buscar contexto do sistema
    $sysData = _donna_fetch_system_context();

    $systemPrompt = "Você é a **Donna**, assistente operacional inteligente do Grupo Real Infraestrutura.\n"
        . "Você responde via WhatsApp e Telegram, então suas respostas devem ser curtas, diretas e formatadas com *negrito* para destaques.\n\n"
        . "## Sua personalidade\n"
        . "- Profissional, eficiente e objetiva\n"
        . "- Use português brasileiro\n"
        . "- Respostas curtas (máximo 500 caracteres quando possível)\n"
        . "- Use emojis com moderação (1-2 por resposta)\n"
        . "- Não invente dados — se não tiver a informação, diga que não tem no momento\n\n"
        . "## Comandos disponíveis\n"
        . "O usuário pode digitar esses comandos diretamente:\n"
        . "- *frota* — ver toda a frota\n"
        . "- *status <placa>* — status de veículo\n"
        . "- *bloquear <placa>* — bloquear veículo\n"
        . "- *desbloquear <placa>* — desbloquear veículo\n"
        . "- *relatorio ceabs* — relatório de bloqueios\n"
        . "- *ativar/desativar alertas ceabs* — alertas de frota\n"
        . "- *ativar/desativar alertas rh* — alertas de ponto\n\n"
        . "## Dados atuais do sistema\n"
        . $sysData . "\n\n"
        . "## Regras\n"
        . "1. Se o usuário pedir algo que um comando fixo resolve, sugira o comando\n"
        . "2. Se o usuário fizer uma pergunta sobre dados, responda com os dados disponíveis\n"
        . "3. Se o usuário pedir algo que você não consegue fazer, explique e sugira alternativa\n"
        . "4. NUNCA invente números, placas ou nomes de colaboradores\n"
        . "5. Se a mensagem veio de áudio transcrito, seja tolerante com erros de transcrição\n"
        . "6. Não repita o que o usuário disse, vá direto à resposta\n";

    $userMessage = $userText;
    if ($isAudio) {
        $userMessage = "[Mensagem de áudio transcrita]: " . $userText;
    }
    if ($fromName) {
        $userMessage = "[De: " . $fromName . "] " . $userMessage;
    }

    // Chamar Claude API
    $payload = [
        "model" => $model,
        "max_tokens" => 600,
        "system" => $systemPrompt,
        "messages" => [
            ["role" => "user", "content" => $userMessage]
        ]
    ];

    $ch = curl_init("https://api.anthropic.com/v1/messages");
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            "Content-Type: application/json",
            "x-api-key: " . $apiKey,
            "anthropic-version: 2023-06-01",
        ],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_TIMEOUT => 30,
    ]);

    $resp = curl_exec($ch);
    $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    // Log
    $logDir = __DIR__ . "/runtime";
    if (!is_dir($logDir)) @mkdir($logDir, 0755, true);
    @file_put_contents($logDir . "/donna_brain.log",
        "[" . date("Y-m-d H:i:s") . "] from=" . $from . " text=" . substr($userText, 0, 100) . " http=" . $http . " err=" . $err . "\n",
        FILE_APPEND
    );

    if ($err || $http !== 200) {
        @file_put_contents($logDir . "/donna_brain.log",
            "[" . date("Y-m-d H:i:s") . "] ERROR: " . $resp . "\n", FILE_APPEND);
        return "Donna: não consegui processar sua mensagem agora. Tente novamente ou digite *ajuda* para ver os comandos.";
    }

    $json = json_decode($resp, true);
    $reply = "";

    if (isset($json["content"][0]["text"])) {
        $reply = trim($json["content"][0]["text"]);
    }

    if ($reply === "") {
        return "Donna: não entendi. Digite *ajuda* para ver os comandos disponíveis.";
    }

    return $reply;
}

// ======================================================
// BUSCAR DADOS DO SISTEMA PARA CONTEXTO
// ======================================================
function _donna_fetch_system_context(): string {

    global $conn;
    if (!$conn) {
        @require_once __DIR__ . "/../db_conexao.php";
    }
    $db = $conn ?? null;
    if (!$db) return "(sem acesso ao banco)";

    $ctx = "";

    try {
        // Frota resumo
        $r = $db->query("SELECT COUNT(*) as total, SUM(CASE WHEN status_bloqueio != 'LIVRE' AND status_bloqueio IS NOT NULL THEN 1 ELSE 0 END) as bloqueados FROM Tab_frota");
        if ($r) {
            $row = $r->fetch_assoc();
            $ctx .= "Frota: " . $row['total'] . " veículos, " . $row['bloqueados'] . " bloqueados\n";
        }

        // MO últimos dias
        $r2 = $db->query("SELECT data_ref, mo_total, total_colaboradores, total_obras FROM log_motor_mo WHERE status='OK' ORDER BY data_ref DESC LIMIT 5");
        if ($r2 && $r2->num_rows > 0) {
            $ctx .= "MO Diário (últimos dias):\n";
            while ($row2 = $r2->fetch_assoc()) {
                $ctx .= "  " . $row2['data_ref'] . ": R$" . $row2['mo_total'] . " (" . $row2['total_colaboradores'] . " colabs, " . $row2['total_obras'] . " obras)\n";
            }
        }

        // Obras ativas
        $r3 = $db->query("SELECT obra, COUNT(*) as regs, SUM(mo_oficial_rateada) as mo FROM fato_mo_diaria_colab_obra WHERE data_ref >= DATE_SUB(CURDATE(), INTERVAL 7 DAY) GROUP BY obra ORDER BY mo DESC LIMIT 8");
        if ($r3 && $r3->num_rows > 0) {
            $ctx .= "Obras ativas (últimos 7 dias):\n";
            while ($row3 = $r3->fetch_assoc()) {
                $ctx .= "  " . $row3['obra'] . ": R$" . $row3['mo'] . " MO (" . $row3['regs'] . " registros)\n";
            }
        }

        // Despesas resumo
        $r4 = $db->query("SELECT COUNT(*) as total, SUM(CASE WHEN status_aprovacao='PENDENTE' THEN 1 ELSE 0 END) as pendentes, SUM(CASE WHEN status_aprovacao='APROVADA' THEN 1 ELSE 0 END) as aprovadas, SUM(CASE WHEN status_aprovacao='REPROVADA' THEN 1 ELSE 0 END) as reprovadas FROM Tab_despesas");
        if ($r4) {
            $row4 = $r4->fetch_assoc();
            $ctx .= "Despesas: " . $row4['total'] . " total (" . $row4['pendentes'] . " pendentes, " . $row4['aprovadas'] . " aprovadas, " . $row4['reprovadas'] . " reprovadas)\n";
        }

        // Colaboradores
        $r5 = $db->query("SELECT COUNT(*) as total FROM Tab_colaboradores WHERE status='Ativo'");
        if ($r5) {
            $row5 = $r5->fetch_assoc();
            $ctx .= "Colaboradores ativos: " . $row5['total'] . "\n";
        }

        // Cobrança ponto recente
        $r6 = $db->query("SELECT data_ref, rodada, COUNT(*) as envios FROM log_donna_cobranca WHERE modo='real' GROUP BY data_ref, rodada ORDER BY data_ref DESC, rodada DESC LIMIT 4");
        if ($r6 && $r6->num_rows > 0) {
            $ctx .= "Cobranças de ponto recentes:\n";
            while ($row6 = $r6->fetch_assoc()) {
                $ctx .= "  " . $row6['data_ref'] . " " . $row6['rodada'] . ": " . $row6['envios'] . " envios\n";
            }
        }

        // Bloqueios recentes
        $r7 = $db->query("SELECT placa, apelido, acao, status_verificacao, tempo_confirmacao_seg, created_at FROM Tab_ceabs_verificacao_bloqueio ORDER BY id DESC LIMIT 5");
        if ($r7 && $r7->num_rows > 0) {
            $ctx .= "Bloqueios/desbloqueios recentes:\n";
            while ($row7 = $r7->fetch_assoc()) {
                $label = $row7['apelido'] ?: $row7['placa'];
                $ctx .= "  " . $label . ": " . $row7['acao'] . " > " . $row7['status_verificacao'] . " (" . $row7['tempo_confirmacao_seg'] . "s) em " . $row7['created_at'] . "\n";
            }
        }

    } catch (Exception $e) {
        $ctx .= "(erro ao buscar dados: " . $e->getMessage() . ")\n";
    }

    return $ctx;
}

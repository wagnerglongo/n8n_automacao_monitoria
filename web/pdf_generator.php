<?php
declare(strict_types=1);

// Verifica se o autoload existe (evita fatal error com mensagem mais amigável)
$autoload = __DIR__ . '/vendor/autoload.php';
if (!file_exists($autoload)) {
    http_response_code(500);
    echo "Dependências ausentes. Rode: composer require dompdf/dompdf:^2.0 na pasta do projeto.";
    exit;
}
require $autoload;

use Dompdf\Dompdf;
use Dompdf\Options;

// --------- CONFIGURAÇÃO DO BANCO ---------
$DB_DSN  = 'mysql:host=mysql;port=3306;dbname=n8n_evaluations;charset=utf8mb4';
$DB_USER = 'n8nuser';
$DB_PASS = 'n8npassword';

// --------- PARÂMETROS (id OU contract_number OU audio_file_path) ---------
$id               = isset($_GET['id']) ? (int) $_GET['id'] : null;
$contract_number  = isset($_GET['contract_number']) ? trim((string)$_GET['contract_number']) : null;
$audio_file_path  = isset($_GET['audio_file_path']) ? trim((string)$_GET['audio_file_path']) : null;

if (!$id && !$contract_number && !$audio_file_path) {
    http_response_code(400);
    echo "Parâmetros inválidos. Use ?id=... ou ?contract_number=... ou ?audio_file_path=...";
    exit;
}

// --------- CONEXÃO ---------
try {
    $pdo = new PDO($DB_DSN, $DB_USER, $DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo "Erro ao conectar no banco.";
    exit;
}

// --------- CONSULTA ---------
$where = [];
$params = [];

if ($id) {
    $where[] = "id = :id";
    $params[':id'] = $id;
}
if ($contract_number) {
    $where[] = "contract_number = :contract_number";
    $params[':contract_number'] = $contract_number;
}
if ($audio_file_path) {
    $where[] = "audio_file_path = :audio_file_path";
    $params[':audio_file_path'] = $audio_file_path;
}

$sql = "SELECT
    id, audio_file_path, checklist, operator_name, contract_number, phone_number,
    evaluation_type, monitor_name, evaluated_at, monitor_notes,
    transcription, evaluation_score, feedback, created_at, updated_at
FROM evaluations
WHERE " . implode(' OR ', $where) . "
ORDER BY id DESC
LIMIT 1";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$row) {
    http_response_code(404);
    echo "Registro não encontrado.";
    exit;
}

// --------- HTML DO PDF ---------
// Substitua sua função h por esta
function h($v): string {
    if ($v === null) {
        return '';
    }
    // Opcional: formatação amigável para floats (removendo zeros finais)
    if (is_float($v)) {
        // Ajuste a precisão se quiser
        $v = rtrim(rtrim(number_format($v, 4, '.', ''), '0'), '.');
    }
    return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$css = '
<style>
body { font-family: DejaVu Sans, Arial, sans-serif; color: #333; font-size: 12px; }
.header { text-align: center; border-bottom: 2px solid #444; padding-bottom: 8px; margin-bottom: 14px; }
.header h1 { margin: 0; font-size: 18px; }
.meta { font-size: 10px; color: #666; text-align: center; margin-bottom: 10px; }
.table { width: 100%; border-collapse: collapse; margin-bottom: 10px; }
.table th, .table td { border: 1px solid #ccc; padding: 6px; vertical-align: top; }
.table th { width: 28%; background: #f6f6f6; text-align: left; }
.section-title { font-weight: bold; margin: 12px 0 6px; font-size: 13px; }
.small { font-size: 10px; color: #555; }
.footer { margin-top: 16px; font-size: 10px; color: #777; text-align: center; }
.badge { display: inline-block; padding: 2px 6px; border-radius: 3px; background: #efefef; border: 1px solid #ddd; font-size: 10px; }
</style>
';

$evaluatedAt = $row['evaluated_at'] ? date('d/m/Y', strtotime((string)$row['evaluated_at'])) : '';
$createdAt   = $row['created_at'] ? date('d/m/Y H:i', strtotime((string)$row['created_at'])) : '';
$updatedAt   = $row['updated_at'] ? date('d/m/Y H:i', strtotime((string)$row['updated_at'])) : '';

$html = $css . '
<div class="header">
  <h1>Relatório de Avaliação</h1>
</div>
<div class="meta">
  Gerado em ' . date('d/m/Y H:i') . '
</div>

<table class="table">
  <tr><th>ID</th><td>' . h((string)$row['id']) . '</td></tr>
  <tr><th>Checklist</th><td><span class="badge">' . h($row['checklist']) . '</span></td></tr>
  <tr><th>Operador</th><td>' . h($row['operator_name']) . '</td></tr>
  <tr><th>Contrato</th><td>' . h($row['contract_number']) . '</td></tr>
  <tr><th>Telefone</th><td>' . h($row['phone_number']) . '</td></tr>
  <tr><th>Tipo de Avaliação</th><td>' . h($row['evaluation_type']) . '</td></tr>
  <tr><th>Monitor</th><td>' . h($row['monitor_name']) . '</td></tr>
  <tr><th>Data da Avaliação</th><td>' . h($evaluatedAt) . '</td></tr>
  <tr><th>Caminho do Áudio</th><td>' . h($row['audio_file_path']) . '</td></tr>
</table>

<div class="section-title">Considerações do monitor</div>
<div>' . nl2br(h($row['monitor_notes'])) . '</div>

<div class="section-title">Transcrição</div>
<div class="small">' . nl2br(h($row['transcription'])) . '</div>

<table class="table" style="margin-top:10px;">
  <tr><th>Score</th><td>' . h($row['evaluation_score']) . '</td></tr>
  <tr><th>Feedback</th><td>' . nl2br(h($row['feedback'])) . '</td></tr>
  <tr><th>Criado em</th><td>' . h($createdAt) . '</td></tr>
  <tr><th>Atualizado em</th><td>' . h($updatedAt) . '</td></tr>
</table>

<div class="footer">
  Sistema de Avaliação - ' . h($_SERVER['HTTP_HOST'] ?? 'localhost') . '
</div>
';

// --------- RENDER PDF ---------
$options = new Options();
$options->set('isRemoteEnabled', false);
$options->set('isHtml5ParserEnabled', true);

$dompdf = new Dompdf($options);
$dompdf->loadHtml($html, 'UTF-8');
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();

$filename = 'relatorio_avaliacao_' . ($row['id'] ?? 'registro') . '.pdf';

// Download
$dompdf->stream($filename, ['Attachment' => true]);
exit;
<?php
// --------- CONFIGURAÇÃO DO BANCO (AJUSTE AQUI) ---------
$DB_DSN  = 'mysql:host=mysql;port=3306;dbname=n8n_evaluations;charset=utf8mb4';
$DB_USER = 'n8nuser';
$DB_PASS = 'n8npassword';

// Pasta de upload física (no container/site)
$target_dir = __DIR__ . "/uploads/"; // Garante caminho absoluto

// Caminho lógico salvo no banco que o N8N usa para ler (padronize com seu fluxo)
$logical_audio_prefix = "/audios/"; // Exemplo: N8N observa /audios/<arquivo>

// --------- PÁGINA ---------
$message = '';

function normalize_phone($raw) {
    // Mantém somente dígitos
    return preg_replace('/\D+/', '', $raw);
}

function whitelist($value, $allowed) {
    return in_array($value, $allowed, true) ? $value : null;
}

try {
    // Conexão PDO
    $pdo = new PDO($DB_DSN, $DB_USER, $DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);
} catch (Throwable $e) {
    // Em produção, evite exibir erro detalhado
    error_log("Erro de conexão com o banco: " . $e->getMessage());
    die("Erro ao conectar no banco. Verifique os logs para mais detalhes.");
}

$errors = []; // garante que exista mesmo se não houver POST

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $checklist = whitelist($_POST['checklist'] ?? '', ['ativos','crefisa','pagbank']);
    $operator_name   = trim($_POST['operator_name'] ?? '');
    $contract_number = trim($_POST['contract_number'] ?? '');
    $phone_number    = normalize_phone($_POST['phone_number'] ?? '');
    $evaluation_type = whitelist($_POST['evaluation_type'] ?? '', ['acordo','sem_acordo']);
    $monitor_name    = trim($_POST['monitor_name'] ?? '');
    $evaluated_at    = trim($_POST['evaluated_at'] ?? '');
    $monitor_notes   = trim($_POST['monitor_notes'] ?? '');

    // 1) Campos obrigatórios
    if (!$checklist) { $errors[] = "Checklist inválido."; }
    if ($operator_name === '') { $errors[] = "O nome do operador é obrigatório."; }
    if ($contract_number === '') { $errors[] = "O número do contrato é obrigatório."; }
    if ($phone_number === '') { $errors[] = "O número de telefone é obrigatório."; }
    if (!$evaluation_type) { $errors[] = "O tipo de avaliação é inválido."; }

    // 2) Data válida (AAAA-MM-DD)
    if (empty($evaluated_at)) {
        $errors[] = "A data da avaliação é obrigatória (use o formato AAAA-MM-DD).";
    }

    // 3) Validação do Upload de Arquivo
    if (isset($_FILES['audioFile']) && $_FILES['audioFile']['error'] === UPLOAD_ERR_OK) {
        $original_filename = basename($_FILES["audioFile"]["name"]);
        $tmp_path = $_FILES["audioFile"]["tmp_name"];
        $file_size = $_FILES["audioFile"]["size"];
        
        // Valida extensão
        $allowed_ext = ['mp3', 'wav', 'm4a', 'ogg'];
        $ext = strtolower(pathinfo($original_filename, PATHINFO_EXTENSION));
        if (!in_array($ext, $allowed_ext)) {
            $errors[] = "Formato de arquivo inválido. Permitidos: " . implode(', ', $allowed_ext);
        }

        // Valida tipo MIME
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($tmp_path);
        $allowed_mimes = [
            'audio/mpeg', 'audio/wav', 'audio/x-wav', 
            'audio/x-m4a', 'audio/mp4', 'audio/ogg', 'application/ogg'
        ];
        if (!in_array($mime, $allowed_mimes, true)) {
            $errors[] = "Tipo de arquivo (MIME) inválido: " . htmlspecialchars($mime);
        }

        // Valida tamanho
        $maxBytes = 100 * 1024 * 1024; // 100MB
        if ($file_size > $maxBytes) {
            $errors[] = "Arquivo muito grande. O máximo permitido é 100 MB.";
        }
    } else {
        // Trata erros de upload ou ausência de arquivo
        $upload_errors = [
            UPLOAD_ERR_INI_SIZE   => "O arquivo excede o limite definido no servidor.",
            UPLOAD_ERR_FORM_SIZE  => "O arquivo excede o limite definido no formulário.",
            UPLOAD_ERR_PARTIAL    => "O upload do arquivo foi feito parcialmente.",
            UPLOAD_ERR_NO_FILE    => "Nenhum arquivo foi enviado.",
            UPLOAD_ERR_NO_TMP_DIR => "Pasta temporária ausente.",
            UPLOAD_ERR_CANT_WRITE => "Falha ao escrever o arquivo no disco.",
            UPLOAD_ERR_EXTENSION  => "Uma extensão do PHP interrompeu o upload do arquivo.",
        ];
        $error_code = $_FILES['audioFile']['error'] ?? UPLOAD_ERR_NO_FILE;
        $errors[] = $upload_errors[$error_code] ?? "Ocorreu um erro desconhecido no upload.";
    }

    // 4) Se não há erros, salvar arquivo e registrar no banco
    if (empty($errors)) {
        $sanitized = preg_replace("/[^a-zA-Z0-9\._\-]/", "", $original_filename) ?: 'audio.' . $ext;
        try { 
            $randomPart = bin2hex(random_bytes(6)); 
        } catch (Throwable $e) { 
            $randomPart = uniqid(); 
        }
        $new_filename = sprintf('%s_%s_%s', date('Ymd_His'), $randomPart, $sanitized);

        if (!is_dir($target_dir)) { 
            @mkdir($target_dir, 0775, true); 
        }
        $target_file = $target_dir . $new_filename;

        if (move_uploaded_file($_FILES["audioFile"]["tmp_name"], $target_file)) {
            $audio_file_path = $logical_audio_prefix . $new_filename;

            $sql = "
                INSERT INTO evaluations
                  (audio_file_path, checklist, operator_name, contract_number, phone_number, evaluation_type, monitor_name, evaluated_at, monitor_notes, created_at, updated_at)
                VALUES
                  (:audio_file_path, :checklist, :operator_name, :contract_number, :phone_number, :evaluation_type, :monitor_name, :evaluated_at, :monitor_notes, NOW(), NOW())
                ON DUPLICATE KEY UPDATE
                  checklist = VALUES(checklist),
                  operator_name = VALUES(operator_name),
                  phone_number = VALUES(phone_number),
                  evaluation_type = VALUES(evaluation_type),
                  monitor_name = VALUES(monitor_name),
                  evaluated_at = VALUES(evaluated_at),
                  monitor_notes = VALUES(monitor_notes),
                  updated_at = NOW()
            ";

            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                ':audio_file_path' => $audio_file_path,
                ':checklist'       => $checklist,
                ':operator_name'   => $operator_name,
                ':contract_number' => $contract_number,
                ':phone_number'    => $phone_number,
                ':evaluation_type' => $evaluation_type,
                ':monitor_name'    => $monitor_name !== '' ? $monitor_name : null,
                ':evaluated_at'    => $evaluated_at,
                ':monitor_notes'   => $monitor_notes !== '' ? $monitor_notes : null,
            ]);

            $message  = "<p style='color: green;'>Arquivo ". htmlspecialchars($new_filename) ." enviado com sucesso.</p>";
            $message .= "<p>Registro criado/atualizado para processamento.</p>";
        } else {
            $message = "<p style='color: red;'>Ocorreu um erro ao mover o arquivo para o destino final.</p>";
        }
    } else {
        $message = "<div style='color: red;'><strong>Foram encontrados erros:</strong><ul><li>" . implode("</li><li>", array_map('htmlspecialchars', $errors)) . "</li></ul></div>";
    }
}

// --------- CONSULTA: últimas avaliações para o dropdown/tabela ---------
$latestEvals = [];
try {
    $stmt = $pdo->query("
        SELECT id, operator_name, contract_number, evaluated_at
        FROM evaluations
        ORDER BY id DESC
        LIMIT 50
    ");
    $latestEvals = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log("Erro ao listar avaliações: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
  <meta charset="UTF-8">
  <title>Upload de Áudio para Avaliação</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <style>
      body { font-family: sans-serif; max-width: 960px; margin: 40px auto; padding: 20px; border: 1px solid #ccc; border-radius: 8px; background-color: #f9f9f9; }
      h1 { text-align: center; color: #333; }
      form { display: grid; grid-template-columns: 1fr; gap: 16px; }
      label { font-weight: bold; margin-bottom: -8px; }
      input[type="file"], input[type="text"], input[type="date"], select, textarea { width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 4px; box-sizing: border-box; }
      textarea { resize: vertical; }
      input[type="submit"], button { padding: 10px 14px; background-color: #007bff; color: white; border: none; border-radius: 4px; cursor: pointer; font-size: 14px; font-weight: bold; }
      input[type="submit"]:hover, button:hover { background-color: #0056b3; }
      #message-area { margin-top: 20px; padding: 15px; border-radius: 4px; border: 1px solid; }
      #message-area[data-type="success"] { background-color: #e6ffed; border-color: #b7e1cd; }
      #message-area[data-type="error"] { background-color: #ffebe6; border-color: #e1b7b7; }
      @media (min-width: 600px) {
          form { grid-template-columns: 1fr 1fr; gap: 14px 16px; }
          .row-span { grid-column: 1 / 3; }
      }
      /* Seção PDF */
      .pdf-section { margin-top: 32px; padding-top: 16px; border-top: 2px dashed #ddd; }
      .inline { display: flex; gap: 10px; align-items: center; }
      table { width: 100%; border-collapse: collapse; margin-top: 12px; }
      th, td { border: 1px solid #ddd; padding: 8px; font-size: 14px; }
      th { background: #f1f1f1; text-align: left; }
      .right { text-align: right; }
  </style>
</head>
<body>
  <h1>Upload de Áudio para Avaliação</h1>

  <form action="" method="post" enctype="multipart/form-data">
      <label for="audioFile" class="row-span">Selecione o arquivo de áudio (MP3, WAV, M4A, OGG):</label>
      <input class="row-span" type="file" name="audioFile" id="audioFile" accept=".mp3,.wav,.m4a,.ogg" required>

      <div>
          <label for="checklist">Checklist</label>
          <select id="checklist" name="checklist" required>
              <option value="ativos">Ativos</option>
              <option value="crefisa">Crefisa</option>
              <option value="pagbank">PagBank</option>
          </select>
      </div>

      <div>
          <label for="evaluation_type">Tipo de Avaliação</label>
          <select id="evaluation_type" name="evaluation_type" required>
              <option value="sem_acordo">Sem Acordo</option>
              <option value="acordo">Com Acordo</option>
          </select>
      </div>

      <div>
          <label for="operator_name">Operador</label>
          <input id="operator_name" name="operator_name" maxlength="120" required>
      </div>

      <div>
          <label for="contract_number">Contrato</label>
          <input id="contract_number" name="contract_number" maxlength="40" required>
      </div>
      
      <div>
          <label for="phone_number">Telefone</label>
          <input id="phone_number" name="phone_number" maxlength="30" required>
      </div>

      <div>
          <label for="evaluated_at">Data da avaliação</label>
          <input id="evaluated_at" type="date" name="evaluated_at" required>
      </div>

      <div class="row-span">
          <label for="monitor_name">Monitor (opcional)</label>
          <input id="monitor_name" name="monitor_name" maxlength="120">
      </div>

      <div class="row-span">
          <label for="monitor_notes">Considerações do monitor (opcional)</label>
          <textarea id="monitor_notes" name="monitor_notes" rows="4"></textarea>
      </div>

      <input type="submit" value="Enviar Áudio" class="row-span">
  </form>

  <?php if (!empty($message)): ?>
      <div id="message-area" data-type="<?php echo empty($errors) ? 'success' : 'error'; ?>">
          <?php echo $message; ?>
      </div>
  <?php endif; ?>

  <!-- Seção: Gerar PDF -->
  <div class="pdf-section">
    <h2>Gerar PDF de uma Avaliação</h2>

    <!-- Opção 1: Dropdown + botão (abre em nova aba) -->
    <form class="inline" action="pdf_generator.php" method="get" target="_blank">
      <label for="eval_id"><strong>Selecione a avaliação:</strong></label>
      <select id="eval_id" name="id" required>
        <option value="" disabled selected>-- escolha uma avaliação --</option>
        <?php foreach ($latestEvals as $ev):
            $label = sprintf(
              (int)$ev['id'],
              htmlspecialchars((string)$ev['operator_name']),
              htmlspecialchars((string)$ev['contract_number']),
              $ev['evaluated_at'] ? date('d/m/Y', strtotime((string)$ev['evaluated_at'])) : '-'
            );
        ?>
          <option value="<?php echo (int)$ev['id']; ?>">
            <?php echo $label; ?>
          </option>
        <?php endforeach; ?>
      </select>
      <button type="submit">Gerar PDF</button>
    </form>

    <!-- Opção 2: Lista rápida das últimas avaliações com botão por linha -->
    <table>
      <thead>
        <tr>
          <th>ID</th>
          <th>Operador</th>
          <th>Contrato</th>
          <th>Data</th>
          <th class="right">Ações</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($latestEvals)): ?>
          <tr><td colspan="5">Nenhuma avaliação encontrada.</td></tr>
        <?php else: ?>
          <?php foreach ($latestEvals as $ev):
              $evDate = $ev['evaluated_at'] ? date('d/m/Y', strtotime((string)$ev['evaluated_at'])) : '-';
          ?>
            <tr>
              <td><?php echo (int)$ev['id']; ?></td>
              <td><?php echo htmlspecialchars((string)$ev['operator_name']); ?></td>
              <td><?php echo htmlspecialchars((string)$ev['contract_number']); ?></td>
              <td><?php echo $evDate; ?></td>
              <td class="right">
                <a href="pdf_generator.php?id=<?php echo (int)$ev['id']; ?>" target="_blank">
                  <button type="button">Gerar PDF</button>
                </a>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</body>
</html>
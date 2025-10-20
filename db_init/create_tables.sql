-- Garantir o banco correto
USE n8n_evaluations;

-- Tabela de avaliações (exemplo)
CREATE TABLE IF NOT EXISTS evaluations (
  id INT AUTO_INCREMENT PRIMARY KEY,
  audio_file_path VARCHAR(255) NOT NULL,
  transcription TEXT,
  evaluation_score INT,
  feedback TEXT,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  processed_at DATETIME NULL, -- Nova coluna para controle. NULL = não processado.
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;


-- Tabela para armazenar a pontuação por disciplina (sem alterações)
CREATE TABLE IF NOT EXISTS evaluation_disciplines (
  id INT AUTO_INCREMENT PRIMARY KEY,
  evaluation_id INT NOT NULL,
  discipline_name VARCHAR(100) NOT NULL,
  discipline_score DECIMAL(5, 2) NOT NULL,
  FOREIGN KEY (evaluation_id) REFERENCES evaluations(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Tabela para detalhar cada critério avaliado (sem alterações)
CREATE TABLE IF NOT EXISTS evaluation_criteria (
  id INT AUTO_INCREMENT PRIMARY KEY,
  evaluation_id INT NOT NULL,
  discipline_name VARCHAR(100) NOT NULL,
  criterion_name VARCHAR(100) NOT NULL,
  weight DECIMAL(5, 2) NOT NULL,
  grade INT NOT NULL,
  score_contributed DECIMAL(5, 2) NOT NULL,
  complied ENUM('Sim', 'Não', 'N/A') NOT NULL,
  justification TEXT,
  FOREIGN KEY (evaluation_id) REFERENCES evaluations(id) ON DELETE CASCADE
) ENGINE=InnoDB;



-- Inserts iniciais (opcional)
-- INSERT INTO evaluations (audio_file_path, transcription, evaluation_score, feedback)
-- VALUES
-- ('/audios/call_001.mp3', 'Hello, how can I help you?', 8, 'Good initial greeting.'),
-- ('/audios/call_002.mp3', 'Please hold for a moment.', 6, 'Long hold time.');
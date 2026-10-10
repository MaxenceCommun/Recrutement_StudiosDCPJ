<?php
require_once __DIR__.'/config.php';

function db() {
  // On utilise une variable statique pour que la connexion ne soit établie qu'une seule fois.
  static $pdo = null;

  // Si la connexion a déjà été établie, on la retourne directement.
  if ($pdo) {
    return $pdo;
  }

  // --- Configuration de la connexion à votre base de données MySQL OVH ---
  $host = 'sdcpjfdinfos.mysql.db'; // L'adresse du serveur de base de données
  $dbname = 'sdcpjfdinfos';        // Le nom de votre base de données
  $user = 'sdcpjfdinfos';           // Votre nom d'utilisateur
  $password = 'Maxou54300'; // !! REMPLACEZ CECI PAR VOTRE VRAI MOT DE PASSE !!
  $charset = 'utf8mb4';             // Encodage pour une compatibilité optimale

  // DSN (Data Source Name) pour la connexion PDO
  $dsn = "mysql:host=$host;dbname=$dbname;charset=$charset";

  // Options pour la connexion PDO
  $options = [
      PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION, // Lance des exceptions en cas d'erreur
      PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,       // Récupère les résultats en tableau associatif
      PDO::ATTR_EMULATE_PREPARES   => false,                  // Utilise les vraies requêtes préparées
  ];

  try {
    // Tentative de création de l'objet PDO pour se connecter à la base de données
    $pdo = new PDO($dsn, $user, $password, $options);
  } catch (\PDOException $e) {
    // En cas d'échec de la connexion, on affiche une erreur claire et on arrête le script.
    // Dans un environnement de production, vous pourriez vouloir logger cette erreur plutôt que de l'afficher.
    throw new \PDOException($e->getMessage(), (int)$e->getCode());
  }

  return $pdo;
}

/**
 * Récupère les questions du quiz d'intégration depuis la base de données.
 * Fallback sur la variable globale $QUIZ_QUESTIONS définie dans config.php si la table n'existe pas ou est vide.
 */
function get_quiz_questions() {
    $pdo = db();
    try {
        // Tentative de récupération depuis la base de données
        $stmt = $pdo->query("SELECT * FROM integration_questions ORDER BY section ASC, id ASC");
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (empty($results)) {
            global $QUIZ_QUESTIONS;
            return $QUIZ_QUESTIONS;
        }
        
        $questions = [];
        foreach ($results as $row) {
            $questions[$row['section']][$row['id']] = [
                'question' => $row['question'],
                'options' => json_decode($row['options_json'], true),
                'answer' => (int)$row['answer_index']
            ];
        }
        return $questions;
    } catch (Exception $e) {
        // En cas d'erreur (table inexistante par exemple), on utilise les questions par défaut
        global $QUIZ_QUESTIONS;
        return $QUIZ_QUESTIONS;
    }
}



/**
 * Vérifie et applique de manière transparente et non-bloquante les colonnes / tables requises.
 */
function ensure_database_schema($pdo = null) {
    if (!$pdo) $pdo = db();
    static $schema_checked = false;
    if ($schema_checked) return;
    
    try {
        // 1. Table user_notes
        $pdo->exec("CREATE TABLE IF NOT EXISTS user_notes (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            recruiter_id INT NOT NULL,
            note_type VARCHAR(50) DEFAULT 'general',
            note_text TEXT NOT NULL,
            attachments_json TEXT DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_un_user (user_id),
            INDEX idx_un_recruiter (recruiter_id)
        )");

        $note_cols = [
            'note_type' => "VARCHAR(50) DEFAULT 'general'",
            'attachments_json' => "TEXT DEFAULT NULL"
        ];
        foreach ($note_cols as $c => $d) {
            try { $pdo->exec("ALTER TABLE user_notes ADD COLUMN $c $d"); } catch (Exception $e) {}
        }

        // 2. Colonne delay_unit dans rejection_reasons
        try {
            $pdo->exec("ALTER TABLE rejection_reasons ADD COLUMN delay_unit VARCHAR(20) DEFAULT 'months'");
        } catch (Exception $e) {}

        // 3. Table user_logs
        $pdo->exec("CREATE TABLE IF NOT EXISTS user_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            action_type VARCHAR(50) NOT NULL,
            details TEXT,
            recruiter_id INT DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_user_id (user_id)
        )");

        // 4. Colonnes dans users
        $user_cols = [
            'last_connection' => 'DATETIME DEFAULT NULL',
            'last_session_connection' => 'DATETIME DEFAULT NULL',
            'departure_reason' => 'TEXT DEFAULT NULL',
            'departure_date' => 'DATETIME DEFAULT NULL',
            'departure_token' => 'VARCHAR(255) DEFAULT NULL',
            'can_reapply_after' => 'DATETIME DEFAULT NULL',
            'membership_expires_at' => 'DATETIME DEFAULT NULL',
            'last_synced_roles' => 'TEXT DEFAULT NULL',
            'session_presence_count' => 'INT DEFAULT 0',
            'can_manage_access' => 'TINYINT(1) DEFAULT NULL',
            'can_view_fta' => 'TINYINT(1) DEFAULT NULL',
            'can_add_fta' => 'TINYINT(1) DEFAULT NULL',
            'can_edit_fta' => 'TINYINT(1) DEFAULT NULL',
            'can_delete_application' => 'TINYINT(1) DEFAULT NULL',
            'can_override_decision' => 'TINYINT(1) DEFAULT NULL',
            'can_manage_players' => 'TINYINT(1) DEFAULT NULL',
            'can_validate_membership' => 'TINYINT(1) DEFAULT NULL',
            'can_extend_trial' => 'TINYINT(1) DEFAULT NULL',
            'can_change_player_status' => 'TINYINT(1) DEFAULT NULL',
            'can_set_reapply_delay' => 'TINYINT(1) DEFAULT NULL',
            'can_manage_quiz' => 'TINYINT(1) DEFAULT NULL',
            'can_manage_reasons' => 'TINYINT(1) DEFAULT NULL',
            'can_view_stats' => 'TINYINT(1) DEFAULT NULL'
        ];
        foreach ($user_cols as $col => $def) {
            try { $pdo->exec("ALTER TABLE users ADD COLUMN $col $def"); } catch (Exception $e) {}
        }

        // 5. Table role_permissions
        $pdo->exec("CREATE TABLE IF NOT EXISTS role_permissions (
            role_id VARCHAR(50) PRIMARY KEY,
            permissions_json TEXT NOT NULL
        )");

        $schema_checked = true;
    } catch (Exception $e) {
        // Ne bloque jamais l'application en cas d'erreur de droits DDL
    }
}

/**
 * Enregistre une action dans l'historique d'un joueur.
 */
function log_user_action($user_id, $action_type, $details, $recruiter_id = null) {
    $pdo = db();
    try {
        $stmt = $pdo->prepare("INSERT INTO user_logs (user_id, action_type, details, recruiter_id) VALUES (?, ?, ?, ?)");
        $stmt->execute([$user_id, $action_type, $details, $recruiter_id]);
    } catch (Exception $e) {
        // En cas d'absence de la table, tentative de création puis réessai
        try {
            ensure_database_schema($pdo);
            $stmt = $pdo->prepare("INSERT INTO user_logs (user_id, action_type, details, recruiter_id) VALUES (?, ?, ?, ?)");
            $stmt->execute([$user_id, $action_type, $details, $recruiter_id]);
        } catch (Exception $e2) {}
    }
}
?>
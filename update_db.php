<?php
require_once __DIR__.'/config.php';
require_once __DIR__.'/bdd.php';

if (php_sapi_name() !== 'cli' && (empty($_SESSION['user']) || (empty($_SESSION['user']['is_bureau']) && empty($_SESSION['user']['is_secretariat']) && empty($_SESSION['user']['is_recruiter'])))) {
    http_response_code(403);
    die('Accès refusé. Réservé au Bureau, Secrétariat, Recruteurs ou à la CLI.');
}

$pdo = db();

try {
    // 1. Table rejection_reasons
    $pdo->exec("CREATE TABLE IF NOT EXISTS rejection_reasons (
        id INT AUTO_INCREMENT PRIMARY KEY,
        reason_text VARCHAR(255) NOT NULL,
        sanction_type VARCHAR(50) NOT NULL,
        delay_value INT DEFAULT 0,
        delay_unit VARCHAR(20) DEFAULT 'months',
        discord_ban BOOLEAN DEFAULT 0,
        roles_added VARCHAR(255) DEFAULT NULL,
        roles_removed VARCHAR(255) DEFAULT NULL,
        is_active BOOLEAN DEFAULT 1
    )");

    try {
        $pdo->exec("ALTER TABLE rejection_reasons ADD COLUMN delay_unit VARCHAR(20) DEFAULT 'months'");
    } catch (Exception $e) {}

    // 4. Création de la table des votes (Interactions Discord)
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS application_votes (
            id INT AUTO_INCREMENT PRIMARY KEY,
            application_id INT NOT NULL,
            discord_user_id VARCHAR(50) NOT NULL,
            vote_type VARCHAR(20) NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY unique_vote (application_id, discord_user_id),
            FOREIGN KEY (application_id) REFERENCES applications(id) ON DELETE CASCADE
        )");
        echo "Table 'application_votes' créée ou vérifiée avec succès.<br>";
    } catch (PDOException $e) {
        echo "Erreur lors de la création de la table application_votes : " . $e->getMessage() . "<br>";
    }

    // 5. Création de la table des notes personnalisées pour les joueurs
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS user_notes (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            recruiter_id INT NOT NULL,
            note_type VARCHAR(50) DEFAULT 'general',
            note_text TEXT NOT NULL,
            attachments_json TEXT DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (recruiter_id) REFERENCES users(id) ON DELETE CASCADE
        )");

        // Ajout des colonnes si la table existait déjà sans elles
        $note_cols = [
            'note_type' => "VARCHAR(50) DEFAULT 'general'",
            'attachments_json' => "TEXT DEFAULT NULL"
        ];
        foreach ($note_cols as $c => $d) {
            try { $pdo->exec("ALTER TABLE user_notes ADD COLUMN $c $d"); } catch (Exception $e) {}
        }
        echo "Table 'user_notes' créée ou vérifiée avec succès.<br>";
    } catch (PDOException $e) {
        echo "Erreur lors de la création de la table user_notes : " . $e->getMessage() . "<br>";
    }

    // Table user_logs
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS user_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            action_type VARCHAR(50) NOT NULL,
            details TEXT,
            recruiter_id INT DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_user_id (user_id)
        )");
        echo "Table 'user_logs' créée ou vérifiée avec succès.<br>";
    } catch (PDOException $e) {
        echo "Erreur lors de la création de la table user_logs : " . $e->getMessage() . "<br>";
    }

    // 2. Add columns to users if they don't exist
    $columns = [
        'last_connection' => 'DATETIME DEFAULT NULL',
        'last_session_connection' => 'DATETIME DEFAULT NULL',
        'departure_reason' => 'TEXT DEFAULT NULL',
        'departure_date' => 'DATETIME DEFAULT NULL',
        'departure_token' => 'VARCHAR(255) DEFAULT NULL',
        'can_reapply_after' => 'DATETIME DEFAULT NULL',
        'membership_expires_at' => 'DATETIME DEFAULT NULL',
        'last_synced_roles' => 'TEXT DEFAULT NULL',
        'session_presence_count' => 'INT DEFAULT 0'
    ];

    foreach ($columns as $column => $def) {
        try {
            $pdo->exec("ALTER TABLE users ADD COLUMN $column $def");
        } catch (Exception $e) {
            // Column might already exist, ignore
        }
    }

    echo "Toutes les mises à jour ont été appliquées avec succès.<br>";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}

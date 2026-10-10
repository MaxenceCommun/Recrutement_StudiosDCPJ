<?php /* Header commun */ ?>
<header class="hdr">
  <div class="wrap">
    <a class="brand" href="/accueil"><img src="assets/logo.png" class="logo" alt=""> <span>Studios DCPJ</span></a>
    
    <nav class="desktop-nav">
      <?php if (!empty($_SESSION['user'])): 
        // Synchronisation en temps réel du statut de l'utilisateur avec la base de données
        try {
            $pdo_sync = db();
            $stmt_sync = $pdo_sync->prepare("SELECT status FROM users WHERE id = ?");
            $stmt_sync->execute([$_SESSION['user']['id']]);
            $db_status = $stmt_sync->fetchColumn();
            if ($db_status && $db_status !== $_SESSION['user']['status']) {
                $_SESSION['user']['status'] = $db_status;
            }
        } catch (Exception $e) { /* Ignore */ }

        // On définit les statuts considérés comme "en période d'essai" pour l'affichage du lien de suivi
        $trial_statuses = ['essai', 'essai_adhesion', 'essai_termine'];
        $is_in_trial = isset($_SESSION['user']['status']) && in_array($_SESSION['user']['status'], $trial_statuses);
        
        // On définit si l'utilisateur est un membre du staff
        $is_staff = !empty($_SESSION['user']['is_recruiter']) || !empty($_SESSION['user']['is_bureau']) || !empty($_SESSION['user']['is_secretariat']);
      
        // NOUVEAU : On vérifie si l'utilisateur a déjà une candidature pour afficher le lien
        $has_application = false;
        if (!$is_staff) {
            try {
                $pdo_header = db();
                $st_app_header = $pdo_header->prepare("SELECT id FROM applications WHERE user_id=? AND status != 'archive' LIMIT 1");
                $st_app_header->execute([$_SESSION['user']['id']]);
                if ($st_app_header->fetch()) {
                    $has_application = true;
                }
            } catch (Exception $e) {
                // En cas d'erreur de base de données, on ne bloque pas la page
                error_log("Erreur DB dans l'en-tête : " . $e->getMessage());
            }
        }
        $is_member_player = isset($_SESSION['user']['status']) && in_array($_SESSION['user']['status'], ['joueur', 'active']);
      ?>
        <span class="hi">Salut, <?=htmlspecialchars($_SESSION['user']['display_name'] ?? $_SESSION['user']['username'])?></span>
        
        <?php 
        $can_see_access_mgt = !empty($_SESSION['user']['can_manage_access']) || !empty($_SESSION['user']['is_bureau']) || !empty($_SESSION['user']['is_secretariat']);
        $can_see_quiz_mgt = !empty($_SESSION['user']['can_manage_quiz']) || !empty($_SESSION['user']['is_bureau']) || !empty($_SESSION['user']['is_secretariat']);
        $can_see_reasons_mgt = !empty($_SESSION['user']['can_manage_reasons']) || !empty($_SESSION['user']['is_bureau']) || !empty($_SESSION['user']['is_secretariat']);
        $can_see_players_mgt = !empty($_SESSION['user']['can_manage_players']) || !empty($_SESSION['user']['is_bureau']) || !empty($_SESSION['user']['is_secretariat']);
        $can_see_fta_mgt = !empty($_SESSION['user']['can_edit_fta']) || !empty($_SESSION['user']['is_bureau']) || !empty($_SESSION['user']['is_secretariat']);
        
        $can_see_gestion = $can_see_access_mgt || $can_see_quiz_mgt || $can_see_reasons_mgt || $can_see_players_mgt || $can_see_fta_mgt;
        
        if ($can_see_gestion): 
        ?>
            <div class="dropdown">
                <span class="dropdown-toggle">Gestion</span>
                <div class="dropdown-content">
                    <?php if ($can_see_access_mgt): ?>
                        <a href="/gestion_acces">Gestion accès</a>
                    <?php endif; ?>
                    <?php if ($can_see_quiz_mgt): ?>
                        <a href="/gestion_quiz">Gestion Quiz</a>
                    <?php endif; ?>
                    <?php if ($can_see_reasons_mgt): ?>
                        <a href="/gestion_motifs">Gestion Motifs Refus</a>
                    <?php endif; ?>
                    <?php if ($can_see_players_mgt): ?>
                        <a href="/gestion_joueurs">Gestion Joueurs</a>
                    <?php endif; ?>
                    <?php if ($can_see_fta_mgt): ?>
                        <a href="/gestion_infractions">Gestion Infractions</a>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>

        <?php if (!empty($_SESSION['user']['can_view_fta']) || !empty($_SESSION['user']['can_edit_fta'])): ?>
            <a href="/fta">FTA</a>
        <?php endif; ?>

        <?php if ($is_staff): // Si c'est un membre du staff ?>
          <a href="/espace_recruteur">Recrutement</a>
          <?php if (!empty($_SESSION['user']['can_view_stats']) || !empty($_SESSION['user']['is_recruiter']) || !empty($_SESSION['user']['is_bureau'])): ?>
            <a href="/statistiques">Statistiques</a>
          <?php endif; ?>
          <?php if ($is_member_player): ?>
            <a href="/espace_joueur">Espace Joueur</a>
          <?php endif; ?>
        <?php else: // Si c'est un joueur normal ?>
            <?php if ($is_member_player): ?>
                <a href="/espace_joueur">Espace Joueur</a>
            <?php elseif ($is_in_trial): ?>
                <a href="/mon_suivi">Mon Suivi</a>
            <?php elseif ($has_application): ?>
                <a href="/ma_candidature">Ma candidature</a>
            <?php endif; ?>
        <?php endif; ?>
        
        <a class="btn" href="/deconnexion">Se déconnecter</a>
      <?php else: ?>
        <a class="btn" href="/connexion_discord">Connexion Discord</a>
      <?php endif; ?>
    </nav>

    <button id="mobile-menu-toggle" class="mobile-menu-toggle">
        <span class="hamburger-icon"></span>
    </button>
  </div>

  <div id="mobile-menu-overlay" class="mobile-menu-overlay">
      <div id="mobile-menu-panel" class="mobile-menu-panel">
          <div class="mobile-menu-header">
              <span>Menu</span>
              <button id="mobile-menu-close" class="mobile-menu-close">&times;</button>
          </div>
          <div class="mobile-menu-content">
              <?php if (!empty($_SESSION['user'])): ?>
                <?php 
                 // Logique dupliquée pour le menu mobile
                 $is_staff_mobile = !empty($_SESSION['user']['is_recruiter']) || !empty($_SESSION['user']['is_bureau']) || !empty($_SESSION['user']['is_secretariat']);
                 $trial_statuses_mobile = ['essai', 'essai_adhesion', 'essai_termine'];
                 $is_in_trial_mobile = isset($_SESSION['user']['status']) && in_array($_SESSION['user']['status'], $trial_statuses_mobile);
                
                if ($can_see_gestion): 
                ?>
                    <div class="mobile-dropdown">
                        <button class="mobile-dropdown-toggle">Gestion <span class="arrow">&#9656;</span></button>
                        <div class="mobile-dropdown-content">
                            <?php if ($can_see_access_mgt): ?>
                                <a href="/gestion_acces">Gestion accès</a>
                            <?php endif; ?>
                            <?php if ($can_see_quiz_mgt): ?>
                                <a href="/gestion_quiz">Gestion Quiz</a>
                            <?php endif; ?>
                            <?php if ($can_see_reasons_mgt): ?>
                                <a href="/gestion_motifs">Gestion Motifs Refus</a>
                            <?php endif; ?>
                            <?php if ($can_see_players_mgt): ?>
                                <a href="/gestion_joueurs">Gestion Joueurs</a>
                            <?php endif; ?>
                            <?php if ($can_see_fta_mgt): ?>
                                <a href="/gestion_infractions">Gestion Infractions</a>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if (!empty($_SESSION['user']['can_view_fta']) || !empty($_SESSION['user']['can_edit_fta'])): ?>
                    <a href="/fta">FTA</a>
                <?php endif; ?>

                <?php 
                  $is_member_player_mobile = isset($_SESSION['user']['status']) && in_array($_SESSION['user']['status'], ['joueur', 'active']);
                ?>
                <?php if ($is_staff_mobile): ?>
                  <a href="/espace_recruteur">Recrutement</a>
                  <a href="/statistiques">Statistiques</a>
                  <?php if ($is_member_player_mobile): ?>
                    <a href="/espace_joueur">Espace Joueur</a>
                  <?php endif; ?>
                <?php else: ?>
                  <?php if ($is_member_player_mobile): ?>
                    <a href="/espace_joueur">Espace Joueur</a>
                  <?php elseif ($is_in_trial_mobile): ?>
                    <a href="/mon_suivi">Mon Suivi</a>
                  <?php elseif ($has_application): ?>
                    <a href="/ma_candidature">Ma candidature</a>
                  <?php endif; ?>
                <?php endif; ?>
                
                <a class="btn" href="/deconnexion" style="margin-top: 20px;">Se déconnecter</a>
              <?php else: ?>
                <a class="btn" href="/connexion_discord">Connexion Discord</a>
              <?php endif; ?>
          </div>
      </div>
  </div>
</header>
<script>
    document.addEventListener('DOMContentLoaded', function() {
        const menuToggle = document.getElementById('mobile-menu-toggle');
        const menuClose = document.getElementById('mobile-menu-close');
        const menuOverlay = document.getElementById('mobile-menu-overlay');
        const menuPanel = document.getElementById('mobile-menu-panel');
        const body = document.body;

        function openMenu() {
            menuOverlay.classList.add('active');
            body.classList.add('mobile-menu-is-open'); 
        }

        function closeMenu() {
            menuOverlay.classList.remove('active');
            body.classList.remove('mobile-menu-is-open'); 
        }

        menuToggle.addEventListener('click', openMenu);
        menuClose.addEventListener('click', closeMenu);
        
        menuOverlay.addEventListener('click', function(event) {
            if (event.target === menuOverlay) {
                closeMenu();
            }
        });

        document.querySelectorAll('.mobile-dropdown-toggle').forEach(button => {
            button.addEventListener('click', function() {
                const content = this.nextElementSibling;
                const arrow = this.querySelector('.arrow');
                this.parentElement.classList.toggle('open');

                if (content.style.maxHeight) {
                    content.style.maxHeight = null;
                    arrow.style.transform = 'rotate(0deg)';
                } else {
                    content.style.maxHeight = content.scrollHeight + "px";
                    arrow.style.transform = 'rotate(90deg)';
                } 
            });
        });
    });
</script>
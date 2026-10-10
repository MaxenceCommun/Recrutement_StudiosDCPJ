<?php
require_once __DIR__.'/config.php';
require_once __DIR__.'/bdd.php';

// Déterminer la bonne action à proposer sur le bouton principal (section "Saute le pas !")
$primaryHref  = 'connexion_discord';
$primaryLabel = 'Déposer une candidature';

$isRecruiter = !empty($_SESSION['user']['is_recruiter']);
$hasUser     = !empty($_SESSION['user']);

if ($hasUser) {
  $pdo = db();
  if ($isRecruiter) {
    // Recruteur → accès direct à l'espace recruteur
    $primaryHref  = 'espace_recruteur';
    $primaryLabel = 'Espace Recruteur';
  } else {
    $user_status = $_SESSION['user']['status'] ?? '';
    if (in_array($user_status, ['joueur', 'active'])) {
        $primaryHref  = 'espace_joueur';
        $primaryLabel = 'Mon Espace Joueur';
    } elseif (in_array($user_status, ['essai', 'essai_adhesion', 'essai_termine'])) {
        $primaryHref  = 'mon_suivi';
        $primaryLabel = 'Mon Suivi';
    } else {
        // Candidat → regarde s'il a une candidature NON ARCHIVÉE
        $st = $pdo->prepare("SELECT * FROM applications WHERE user_id=? AND status != 'archive' LIMIT 1");
        $st->execute([$_SESSION['user']['id']]);
        $app = $st->fetch(PDO::FETCH_ASSOC);

        if (!$app) {
          // S'il n'a pas de candidature, le bouton l'envoie vers la page de choix
          $primaryHref  = 'choisir_candidature';
          $primaryLabel = 'Déposer une candidature';
        } else {
          // S'il a déjà une candidature, il peut la consulter
          if (in_array($app['status'], ['accepte_integration'], true) && !empty($app['integration_token'])) {
            $primaryHref  = 'integration?token='.$app['integration_token'];
            $primaryLabel = 'Continuer l\'intégration';
          } else {
            $primaryHref  = 'ma_candidature';
            $primaryLabel = 'Voir ma candidature';
          }
        }
    }
  }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Studios DCPJ — Simulation de secours</title>
  <link rel="icon" type="image/png" href="assets/logo.png">
  <link rel="stylesheet" href="assets/styles.css">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;600;800&display=swap" rel="stylesheet">
  <style>
    /* Premium Modern Design Reset & Variables */
    :root {
      --font-main: 'Inter', sans-serif;
      --glass-bg: rgba(255, 255, 255, 0.03);
      --glass-border: rgba(255, 255, 255, 0.08);
      --glass-shadow: 0 8px 32px 0 rgba(0, 0, 0, 0.37);
      --neon-glow: 0 0 20px rgba(231, 76, 60, 0.4);
      --accent-gradient: linear-gradient(135deg, #e74c3c 0%, #ff6b6b 100%);
    }

    body {
      font-family: var(--font-main);
      background-color: #0f1115;
      color: #e2e8f0;
      overflow-x: hidden;
    }

    /* Typography */
    h1, h2, h3, h4 { font-family: var(--font-main); font-weight: 800; letter-spacing: -0.02em; }
    .grad-text {
      background: var(--accent-gradient);
      -webkit-background-clip: text;
      -webkit-text-fill-color: transparent;
      text-shadow: none;
      display: inline-block; /* Helps with clipping issues on some browsers */
    }

    /* Layout & Sections */
    section { padding: 100px 24px; position: relative; }
    .wrap { max-width: 1200px; margin: 0 auto; z-index: 2; position: relative; }
    .section-bg { position: absolute; top: 0; left: 0; width: 100%; height: 100%; object-fit: cover; z-index: 0; opacity: 0.2; filter: blur(4px); }
    .section-overlay { position: absolute; top: 0; left: 0; width: 100%; height: 100%; background: linear-gradient(to bottom, #0f1115 0%, transparent 50%, #0f1115 100%); z-index: 1; }
    
    .section-header { text-align: center; margin-bottom: 60px; }
    .section-title { font-size: clamp(2.5rem, 5vw, 3.5rem); margin-bottom: 20px; text-shadow: 0 2px 10px rgba(0,0,0,0.5); }
    .section-intro { max-width: 800px; margin: 0 auto; font-size: 1.15rem; color: #cbd5e1; line-height: 1.8; font-weight: 300; }

    /* Glassmorphism Cards */
    .glass-card {
      background: var(--glass-bg);
      backdrop-filter: blur(16px);
      -webkit-backdrop-filter: blur(16px);
      border: 1px solid var(--glass-border);
      border-radius: 24px;
      box-shadow: var(--glass-shadow);
      transition: transform 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275), box-shadow 0.4s ease;
      overflow: hidden;
    }
    .glass-card:hover { transform: translateY(-10px); box-shadow: 0 15px 45px rgba(0,0,0,0.5); border-color: rgba(255,255,255,0.15); }

    /* Hero Section */
    .hero { min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 0; background: #000; overflow: hidden; }
    .hero-bg-video { position: absolute; top: 50%; left: 50%; min-width: 100%; min-height: 100%; width: auto; height: auto; transform: translateX(-50%) translateY(-50%); opacity: 0.4; z-index: 0; object-fit: cover; }
    .hero-bg-img { position: absolute; top: 0; left: 0; width: 100%; height: 100%; object-fit: cover; opacity: 0.3; z-index: 0; filter: contrast(1.2) brightness(0.8); }
    .hero-grad { position: absolute; inset: 0; background: radial-gradient(circle at center, transparent 0%, #0f1115 100%); z-index: 1; }
    .hero-content { text-align: center; z-index: 2; display: flex; flex-direction: column; align-items: center; justify-content: center; width: 100%; }
    .hero h1 { font-size: clamp(4rem, 8vw, 6.5rem); line-height: 1.1; margin-bottom: 20px; text-transform: uppercase; text-shadow: 0 10px 30px rgba(0,0,0,0.8); animation: fadeUp 1s ease-out; }
    .hero #typewriter-text { font-size: clamp(1.2rem, 2.5vw, 1.8rem); font-weight: 300; color: #94a3b8; min-height: 3rem; animation: fadeUp 1s ease-out 0.2s backwards; }
    .hero-btn-group { display: flex; gap: 20px; margin-top: 40px; animation: fadeUp 1s ease-out 0.4s backwards; flex-wrap: wrap; justify-content: center; }
    
    .btn-premium { background: var(--accent-gradient); color: white; border: none; padding: 16px 36px; border-radius: 50px; font-weight: 600; font-size: 1.1rem; cursor: pointer; transition: all 0.3s ease; box-shadow: var(--neon-glow); text-transform: uppercase; letter-spacing: 1px; display: inline-flex; align-items: center; justify-content: center; }
    .btn-premium:hover { transform: scale(1.05); box-shadow: 0 0 30px rgba(231, 76, 60, 0.6); color: white; }
    .btn-outline { background: transparent; color: white; border: 2px solid rgba(255,255,255,0.2); padding: 16px 36px; border-radius: 50px; font-weight: 600; font-size: 1.1rem; cursor: pointer; transition: all 0.3s ease; backdrop-filter: blur(5px); text-transform: uppercase; letter-spacing: 1px; }
    .btn-outline:hover { border-color: white; background: rgba(255,255,255,0.1); color: white; }

    /* Pôles Grid */
    .poles-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 30px; margin-top: 50px; }
    .pole-card { padding: 30px; display: flex; flex-direction: column; align-items: center; text-align: center; z-index: 2; position: relative; }
    .pole-card img { width: 100%; height: 200px; object-fit: contain; margin-bottom: 24px; filter: drop-shadow(0 10px 15px rgba(0,0,0,0.5)); transition: transform 0.5s ease; }
    .pole-card:hover img { transform: scale(1.1) rotate(2deg); }
    .pole-card h3 { font-size: 1.6rem; color: white; margin-bottom: 16px; position: relative; display: inline-block; }
    .pole-card h3::after { content: ''; position: absolute; bottom: -8px; left: 50%; transform: translateX(-50%); width: 40px; height: 3px; background: var(--accent-gradient); border-radius: 2px; transition: width 0.3s ease; }
    .pole-card:hover h3::after { width: 80px; }
    .pole-card p { color: #94a3b8; line-height: 1.6; font-size: 1rem; }

    /* Audience Section */
    .audience-section { position: relative; padding: 120px 24px; background: #0b0d10; }
    .social-links { display: flex; flex-wrap: wrap; justify-content: center; gap: 30px; margin-top: 50px; }
    .social-card { width: 80px; height: 80px; border-radius: 20px; background: var(--glass-bg); backdrop-filter: blur(10px); border: 1px solid var(--glass-border); display: flex; align-items: center; justify-content: center; transition: all 0.3s ease; }
    .social-card img { width: 40px; height: 40px; transition: all 0.3s ease; }
    .social-card:hover { transform: translateY(-10px) scale(1.1); background: rgba(255,255,255,0.1); border-color: rgba(255,255,255,0.3); }
    .social-card:hover img { transform: scale(1.1); }

    /* Strategy Section */
    .strategy-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 30px; margin-top: 60px; }
    .strategy-card { display: flex; flex-direction: column; }
    .strategy-img-wrapper { height: 260px; overflow: hidden; position: relative; border-radius: 24px 24px 0 0; }
    .strategy-img-wrapper img { width: 100%; height: 100%; object-fit: cover; transition: transform 0.7s ease; }
    .strategy-card:hover .strategy-img-wrapper img { transform: scale(1.1); }
    .strategy-content { padding: 30px; flex-grow: 1; display: flex; flex-direction: column; justify-content: center; background: linear-gradient(to top, rgba(0,0,0,0.8), transparent); position: absolute; bottom: 0; width: 100%; }
    .strategy-content h3 { font-size: 1.8rem; color: white; margin-bottom: 10px; text-shadow: 0 2px 4px rgba(0,0,0,0.8); }
    .strategy-content p { color: #e2e8f0; font-size: 1rem; text-shadow: 0 1px 3px rgba(0,0,0,0.8); }

    /* President Word */
    .president-section { background: url('assets/backgrounds/audience.jpg') center/cover fixed; position: relative; }
    .president-overlay { position: absolute; inset: 0; background: rgba(15, 17, 21, 0.85); backdrop-filter: blur(5px); }
    .president-card { display: flex; flex-wrap: wrap; align-items: center; gap: 50px; padding: 50px; max-width: 1000px; margin: 0 auto; position: relative; z-index: 2; }
    .president-photo { flex: 0 0 200px; text-align: center; position: relative; }
    .president-photo::before { content: ''; position: absolute; inset: -10px; background: var(--accent-gradient); border-radius: 50%; z-index: 0; opacity: 0.5; filter: blur(15px); }
    .president-photo img { width: 200px; height: 200px; border-radius: 50%; object-fit: cover; border: 4px solid var(--accent-primary); position: relative; z-index: 1; }
    .president-text { flex: 1; min-width: 300px; position: relative; }
    .president-text::before { content: '"'; position: absolute; top: -60px; left: -30px; font-size: 8rem; color: rgba(255,255,255,0.05); font-family: serif; font-weight: bold; line-height: 1; }
    .president-text p { color: #cbd5e1; line-height: 1.9; font-size: 1.1rem; margin-bottom: 20px; font-style: italic; }
    .president-signature { text-align: right; color: white; font-weight: 700; font-size: 1.2rem; margin-top: 30px; background: var(--accent-gradient); -webkit-background-clip: text; -webkit-text-fill-color: transparent; }

    /* CTA Section */
    .cta-section { 
        padding: 150px 24px; 
        text-align: center; 
        position: relative; 
        overflow: hidden; 
        background: url('assets/backgrounds/cta.png') center/cover fixed;
    }
    .cta-overlay {
        position: absolute;
        inset: 0;
        background: linear-gradient(to right, rgba(15, 17, 21, 0.9), rgba(15, 17, 21, 0.6), rgba(15, 17, 21, 0.9));
        z-index: 1;
    }
    .cta-content { position: relative; z-index: 2; max-width: 800px; margin: 0 auto; }
    .cta-content h2 { font-size: clamp(3rem, 6vw, 4.5rem); margin-bottom: 24px; line-height: 1.1; text-shadow: 0 4px 20px rgba(0,0,0,0.5); }
    .cta-content p { font-size: 1.3rem; color: #e2e8f0; margin-bottom: 40px; text-shadow: 0 2px 10px rgba(0,0,0,0.5); }

    /* Presse & Partenaires */
    .press-grid { 
        display: grid; 
        grid-template-columns: repeat(3, 1fr); 
        gap: 30px; 
        margin-top: 50px; 
    }
    .press-item {
        display: block;
        width: 100%;
        max-width: 400px;
        margin: 0 auto;
        transition: all 0.4s ease;
        transform: scale(0.95);
        position: relative;
        z-index: 5;
    }
    .press-item:hover {
        transform: translateY(-5px) scale(1.05);
        z-index: 10;
    }
    .press-item img { width: 100%; height: auto; border-radius: 12px; }

    .partner-section { background: linear-gradient(to bottom, #0b0d10, #0f1115); text-align: center; }
    .partner-logo { max-width: 300px; margin: 40px auto; display: block; filter: drop-shadow(0 0 20px rgba(255,255,255,0.1)); }

    /* Contact Footer */
    .footer-contact { padding: 80px 24px 40px; background: #050608; text-align: center; border-top: 1px solid rgba(255,255,255,0.05); }
    .footer-links { margin-top: 60px; padding-top: 30px; border-top: 1px solid rgba(255,255,255,0.05); color: #64748b; display: flex; justify-content: space-between; align-items: center; max-width: 1200px; margin-left: auto; margin-right: auto; flex-wrap: wrap; gap: 20px; }
    
    @keyframes fadeUp {
      from { opacity: 0; transform: translateY(30px); }
      to { opacity: 1; transform: translateY(0); }
    }

    .partner-logo-img {
      max-height: 110px;
      max-width: 100%;
      width: auto;
      height: auto;
      object-fit: contain;
      filter: drop-shadow(0 0 15px rgba(255,255,255,0.1));
    }

    @media (max-width: 768px) {
      .president-card { flex-direction: column; text-align: center; padding: 30px; }
      .president-text::before { display: none; }
      .president-signature { text-align: center; }
      .hero-btn-group { flex-direction: column; width: 100%; max-width: 300px; }
      .btn-premium, .btn-outline { width: 100%; }
      .press-grid { grid-template-columns: 1fr; }
      .poles-grid, .strategy-grid { grid-template-columns: 1fr !important; }
      .partner-card { padding: 30px 20px !important; }
      .partner-logo-img { max-height: 70px; max-width: min(100%, 280px); }
    }

    @media (max-width: 480px) {
      .partner-card { padding: 24px 15px !important; }
      .partner-logo-img { max-height: 55px; max-width: min(100%, 230px); }
      .btn-premium { width: 100%; padding: 14px 20px !important; }
    }

    /* Before/After Slider */
    .ba-slider-container {
      max-width: 900px;
      margin: 50px auto;
      aspect-ratio: 16/9;
      position: relative;
      user-select: none;
      border-radius: 24px;
      overflow: hidden;
      box-shadow: 0 10px 30px rgba(0,0,0,0.5);
    }
    .ba-slider {
      position: relative;
      width: 100%;
      height: 100%;
      overflow: hidden;
    }
    .ba-slider img {
      position: absolute;
      top: 0;
      left: 0;
      width: 100%;
      height: 100%;
      object-fit: cover;
      display: block;
      pointer-events: none;
    }
    .ba-img-before {
      z-index: 2;
      clip-path: inset(0 50% 0 0); /* Milieu par défaut */
    }
    .ba-img-after {
      z-index: 1;
    }
    .ba-slider .handle {
      position: absolute;
      left: 50%;
      top: 0;
      bottom: 0;
      width: 4px;
      background: #fff;
      z-index: 10;
      display: flex;
      align-items: center;
      justify-content: center;
      pointer-events: none;
      box-shadow: 0 0 15px rgba(0,0,0,0.5);
      transform: translateX(-50%);
    }
    .ba-slider .handle-btn {
      width: 54px;
      height: 54px;
      background: #fff;
      border-radius: 50%;
      display: flex;
      align-items: center;
      justify-content: center;
      box-shadow: 0 0 20px rgba(0,0,0,0.4);
      position: relative;
      pointer-events: auto;
      border: 4px solid #fff;
    }
    .ba-slider .handle-btn::before,
    .ba-slider .handle-btn::after {
      content: '';
      width: 0;
      height: 0;
      border-top: 6px solid transparent;
      border-bottom: 6px solid transparent;
    }
    .ba-slider .handle-btn::before {
      border-right: 8px solid #333;
      margin-right: 4px;
    }
    .ba-slider .handle-btn::after {
      border-left: 8px solid #333;
      margin-left: 4px;
    }
    .ba-slider-input {
      position: absolute;
      top: 0;
      left: 0;
      width: 100%;
      height: 100%;
      opacity: 0;
      cursor: ew-resize;
      z-index: 20;
      margin: 0;
    }
  </style>
</head>
<body>
<?php include __DIR__.'/partiels_entete.php'; ?>

<!-- Hero Section -->
<section class="hero">
  <img class="hero-bg-img" src="assets/accueil_sdcpj.png" alt="Véhicules de secours">
  <div class="hero-grad"></div>
  
  <div class="wrap hero-content">
    <h1>Studios <span class="grad-text">DCPJ</span></h1>
    <p id="typewriter-text"></p>
    
    <div class="hero-btn-group">
      <?php if ($isRecruiter): ?>
        <a class="btn-premium" href="<?= htmlspecialchars($primaryHref) ?>" style="background: linear-gradient(135deg, #1abc9c 0%, #2ecc71 100%); box-shadow: 0 0 20px rgba(46, 204, 113, 0.4);">
          <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="margin-right: 10px;"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect><line x1="9" y1="3" x2="9" y2="21"></line><path d="M14 8h.01"></path><path d="M14 12h.01"></path><path d="M14 16h.01"></path></svg>
          Espace Recruteur
        </a>
      <?php else: ?>
        <a class="btn-premium" href="<?= htmlspecialchars($primaryHref) ?>">
          <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="margin-right: 10px;"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><line x1="19" y1="8" x2="19" y2="14"></line><line x1="22" y1="11" x2="16" y2="11"></line></svg>
          <?= htmlspecialchars($primaryLabel) ?>
        </a>
      <?php endif; ?>
      
      <a class="btn-outline" href="candidater_operateur">
        <svg xmlns="http://www.w3.org/.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="margin-right: 10px;"><rect x="2" y="2" width="20" height="20" rx="2" ry="2"></rect><line x1="2" y1="12" x2="22" y2="12"></line><line x1="12" y1="2" x2="12" y2="22"></line></svg>
        Devenir Opérateur
      </a>
    </div>
  </div>
</section>

<!-- Pôles Section -->
<section id="poles">
  <div class="wrap">
    <div class="section-header">
      <h2 class="section-title">Les pôles de <span class="grad-text">simulation</span></h2>
      <p class="section-intro">Quand le jeu est source d'apprentissage. Le jeu se transforme réellement en simulateur lorsque de nombreux moyens et services sont engagés. Derrière chaque ordinateur, un humain bien réel qu’il faut manager ; derrière chaque intervention, une stratégie à concevoir, une crise à gérer.</p>
    </div>
    
    <div class="poles-grid">
      <div class="glass-card pole-card">
        <img src="assets/poles/forces-ordre.png" alt="Forces de l'ordre">
        <h3>Forces de l'ordre</h3>
        <p>La simulation la plus aboutie en termes d'interventions, d'utilisation de l'équipement, et de gestion de scène sur le terrain de jeu.</p>
      </div>
      <div class="glass-card pole-card">
        <img src="assets/poles/sapeurs-pompiers.png" alt="Sapeurs-Pompiers">
        <h3>Sapeurs-Pompiers</h3>
        <p>Réalisez des gestes de secours vitaux, luttez contre des incendies structurels et urbains, coordonnez des opérations de sauvetage.</p>
      </div>
      <div class="glass-card pole-card">
        <img src="assets/poles/vie-civile.png" alt="Vie civile">
        <h3>Vie civile</h3>
        <p>L'essence même de notre univers. Devenez le maillon déclencheur des interventions et créez des scénarios complexes pour les secours.</p>
      </div>
    </div>
  </div>
</section>

<!-- Audience Section -->
<section id="audience" class="audience-section">
  <div class="wrap">
    <div class="section-header">
      <h2 class="section-title">Notre <span class="grad-text">audience</span></h2>
      <p class="section-intro">À travers nos réseaux sociaux, nous touchons un public large passionné par les services de secours. Nous répondons aux questions des jeunes, transmettons notre passion et suscitons des vocations professionnelles (pompiers, gendarmes, policiers, militaires).</p>
    </div>

    <!-- Before/After Slider -->
    <div class="ba-slider-container glass-card">
      <div class="ba-slider">
        <img src="assets/police.png" alt="Police" class="ba-img-after" id="ba-img-after">
        <img src="assets/pompier.png" alt="Pompier" class="ba-img-before" id="ba-img-before">
        <div class="handle" id="ba-handle">
          <div class="handle-btn"></div>
        </div>
        <input type="range" min="0" max="100" value="50" class="ba-slider-input" id="ba-range">
      </div>
    </div>
    
    <div class="social-links">
      <a href="VOTRE_LIEN_YOUTUBE" target="_blank" rel="noopener noreferrer" class="social-card">
        <img src="assets/logos/youtube.png" alt="YouTube">
      </a>
      <a href="VOTRE_LIEN_DISCORD" target="_blank" rel="noopener noreferrer" class="social-card">
        <img src="assets/logos/discord.png" alt="Discord">
      </a>
      <a href="VOTRE_LIEN_TIKTOK" target="_blank" rel="noopener noreferrer" class="social-card">
        <img src="assets/logos/tiktok.png" alt="TikTok">
      </a>
      <a href="VOTRE_LIEN_INSTAGRAM" target="_blank" rel="noopener noreferrer" class="social-card">
        <img src="assets/logos/instagram.png" alt="Instagram">
      </a>
      <a href="VOTRE_LIEN_FACEBOOK" target="_blank" rel="noopener noreferrer" class="social-card">
        <img src="assets/logos/facebook.png" alt="Facebook">
      </a>
    </div>
  </div>
</section>

<!-- Stratégie Section -->
<section id="strategie">
  <div class="wrap">
    <div class="section-header">
      <h2 class="section-title">Notre <span class="grad-text">stratégie</span></h2>
      <p class="section-intro">Issus du milieu du jeu vidéo, de la communication et des métiers du secours, nous allions une communication virtuelle pour les jeunes connectés avec des enjeux d'engagement réels.</p>
    </div>
    
    <div class="strategy-grid">
      <div class="glass-card strategy-card">
        <div class="strategy-img-wrapper">
          <img src="assets/backgrounds/bmpm.png" alt="Simulation virtuelle">
          <div class="strategy-content">
            <h3>Le Virtuel</h3>
            <p>Nous créons des scénarios immersifs et réalistes dans un environnement maitrisé.</p>
          </div>
        </div>
      </div>
      
      <div class="glass-card strategy-card">
        <div class="strategy-img-wrapper">
          <img src="assets/live.jpg" alt="Communication">
          <div class="strategy-content">
            <h3>Le Lien</h3>
            <p>Nous utilisons nos plateformes pour éduquer, informer et créer du dialogue.</p>
          </div>
        </div>
      </div>

      <div class="glass-card strategy-card">
        <div class="strategy-img-wrapper">
          <img src="assets/pdg.png" alt="Réel">
          <div class="strategy-content">
            <h3>Le Réel</h3>
            <p>Valorisation des vrais métiers pour susciter des vocations concrètes.</p>
          </div>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- Mot du Président -->
<section id="mot-president" class="president-section">
  <div class="president-overlay"></div>
  <div class="wrap">
    <div class="section-header">
      <h2 class="section-title">Le mot du <span class="grad-text">président</span></h2>
    </div>
    <div class="glass-card president-card">
      <div class="president-photo">
        <img src="assets/president.png" alt="Maxime RIO">
      </div>
      <div class="president-text">
        <p>Nous développons depuis quelques années une plateforme dérivée du jeux GTA 5 où l’utilisateur peut se plonger dans une simulation “Forces de l’ordre”, “Sapeurs-Pompiers” ou “Civile”. L’objectif : Transmettre les valeurs des métiers de secours et de la République.</p>
        <p>Pour faire le lien entre le virtuel et les métiers du service public, nous proposons quotidiennement du contenu immersif et qualitatif. Un contenu qui dans son ensemble met en valeur ces professions essentielles.</p>
        <div class="president-signature">Maxime RIO<br><span style="font-size: 0.9rem; font-weight: 400; color: #94a3b8;">Président de l'association</span></div>
      </div>
    </div>
  </div>
</section>

<!-- Presse -->
<section id="presse">
  <div class="wrap">
    <div class="section-header">
      <h2 class="section-title">Ils parlent <span class="grad-text">de nous</span></h2>
    </div>
    <div class="press-grid">
      <a href="https://www.bfmtv.com/tech/gaming/comme-dans-la-vraie-vie-on-a-patrouille-avec-la-police-nationale-de-marseille-sur-gta-5_AN-202303040021.html" target="_blank" class="press-item glass-card" style="padding: 15px;">
        <img src="assets/presse/article1.png" alt="BFMTV">
        <p style="margin-top: 12px; font-size: 0.85rem; line-height: 1.4; color: #cbd5e1; font-weight: 500;">"Comme dans la vraie vie": on a patrouillé avec la police... sur GTA 5</p>
      </a>
      <a href="https://www.leparisien.fr/video/video-arrete-toi-ou-on-va-te-taser-ils-sentrainent-a-devenir-policier-sur-le-gta-francais-29-12-2022-GJRCZHU5LRANDERRHTMRNYSZC4.php" target="_blank" class="press-item glass-card" style="padding: 15px;">
        <img src="assets/presse/article2.png" alt="Le Parisien">
        <p style="margin-top: 12px; font-size: 0.85rem; line-height: 1.4; color: #cbd5e1; font-weight: 500;">«Arrête-toi ou on va te taser !» : ils s’entraînent à devenir policiers sur le «GTA» français</p>
      </a>
      <a href="https://www.lexpress.fr/societe/larmee-et-la-gendarmerie-semparent-du-jeu-video-pour-recruter-S5O655K6FREOTHC55IM47XJJLU/?cmp_redirect=true" target="_blank" class="press-item glass-card" style="padding: 15px;">
        <img src="assets/presse/article3.png" alt="L'Express">
        <p style="margin-top: 12px; font-size: 0.85rem; line-height: 1.4; color: #cbd5e1; font-weight: 500;">L'armée et la gendarmerie s'emparent du jeu vidéo pour recruter</p>
      </a>
      <a href="https://www.lemonde.fr/pixels/article/2024/10/25/a-la-paris-games-week-l-industrie-du-jeu-video-n-est-pas-la-seule-a-convoiter-l-attention-de-la-jeunesse_6359812_4408996.html" target="_blank" class="press-item glass-card" style="padding: 15px;">
        <img src="assets/presse/article4.png" alt="Le Monde">
        <p style="margin-top: 12px; font-size: 0.85rem; line-height: 1.4; color: #cbd5e1; font-weight: 500;">A la PGW, l’industrie du jeu vidéo n’est pas la seule à convoiter l’attention de la jeunesse</p>
      </a>
      <a href="https://www.lejdd.fr/societe/paris-games-week-la-police-nationale-veut-recruter-et-renouer-des-liens-grace-aux-jeux-video-150992" target="_blank" class="press-item glass-card" style="padding: 15px;">
        <img src="assets/presse/article5.png" alt="Le JDD">
        <p style="margin-top: 12px; font-size: 0.85rem; line-height: 1.4; color: #cbd5e1; font-weight: 500;">Paris Games Week : la Police veut recruter et renouer des liens</p>
      </a>
      <a href="https://www.tf1info.fr/high-tech/videos/video-les-forces-de-l-ordre-recrutent-au-salon-du-jeu-video-6909-2330759.html" target="_blank" class="press-item glass-card" style="padding: 15px;">
        <img src="assets/presse/article6.png" alt="TF1 Info">
        <p style="margin-top: 12px; font-size: 0.85rem; line-height: 1.4; color: #cbd5e1; font-weight: 500;">Les forces de l'ordre recrutent au salon du jeu vidéo</p>
      </a>
    </div>
  </div>
</section>

<!-- CTA Dynamique -->
<section id="cta" class="cta-section">
  <div class="cta-overlay"></div>
  <div class="wrap cta-content">
    <h2>Saute le pas !</h2>
    <p>Rejoins la communauté et découvre la simulation sous un nouvel angle.</p>
    <a class="btn-premium" href="<?= htmlspecialchars($primaryHref) ?>" style="padding: 20px 50px; font-size: 1.3rem;">
       <?= htmlspecialchars($primaryLabel) ?>
    </a>
  </div>
</section>

<!-- Partenaires -->
<section id="partenaires" class="partner-section">
  <div class="wrap">
    <div class="section-header">
      <h2 class="section-title">Notre <span class="grad-text">partenaire</span></h2>
      <p class="section-intro">Allianz Défense et Sécurité</p>
    </div>
    
    <div class="glass-card partner-card" style="padding: 50px; text-align: center; max-width: 900px; margin: 0 auto;">
      <div style="display: flex; justify-content: center; align-items: center; margin-bottom: 30px;">
        <img src="assets/partenaires/logo-allianz.png" alt="Allianz" class="partner-logo-img">
      </div>
      
      <div style="color: #cbd5e1; line-height: 1.8; text-align: justify; font-size: 1.05rem;">
        <p style="margin-bottom: 20px;">Allianz Défense et Sécurité (ADS) est un expert historique de l’assurance reconnu pour la protection des militaires, policiers, gendarmes, pompiers et réservistes.</p>
        <p style="margin-bottom: 20px;">Partenaire des forces de défense depuis plus de 70 ans, Allianz met son expertise au profit des personnels engagés à travers ses 120 conseillers terrain, tous issus de la communauté. Présents au quotidien dans les unités, casernes, écoles, brigades et commissariats, ces conseillers s’assurent que chacun bénéficie d’une protection optimale, tant dans l’exercice de leurs missions que face aux imprévus.</p>
        <p style="margin-bottom: 30px;">Allianz Défense et Sécurité propose des solutions d’assurance simples, complètes et abordables, spécialement conçues pour les professionnels de la défense et de la sécurité, qu’ils soient d’active ou de réserve, ainsi que pour leurs familles.</p>
      </div>
      
      <a href="https://maa-ads-assurance.fr/?mtm_campaign=SDCPJ%20V2" target="_blank" class="btn-premium" style="padding: 18px 40px;">
        Découvrez leurs offres de protection
      </a>
    </div>
  </div>
</section>

<!-- Footer -->
<footer class="footer-contact">
  <div class="wrap">
    <h2 class="section-title" style="font-size: 2.5rem; margin-bottom: 20px;">Nous contacter</h2>
    <p class="section-intro" style="margin-bottom: 40px;">Renseignements, partenariats ou questions diverses.</p>
    <a href="#" class="btn-premium">Formulaire de contact</a>
    
    <div class="footer-links">
      <div>© 2026 Studios DCPJ. Tous droits réservés.</div>
      <div style="display: flex; gap: 20px;">
        <a href="#" style="color: inherit; text-decoration: none;">Mentions Légales</a>
        <a href="#" style="color: inherit; text-decoration: none;">Politique de confidentialité</a>
      </div>
    </div>
  </div>
</footer>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const typingText = "Notre vocation, susciter la vôtre.";
    const typingElement = document.getElementById('typewriter-text');
    if (!typingElement) return;
    
    typingElement.innerHTML = '<span class="text"></span><span class="cursor" style="display: inline-block; background: #fff; width: 3px; height: 1.2em; vertical-align: middle; margin-left: 5px; animation: blink 1s infinite;"></span>';
    const textSpan = typingElement.querySelector('.text');
    
    let i = 0;
    function typeWriter() {
        if (i < typingText.length) {
            textSpan.textContent += typingText.charAt(i);
            i++;
            setTimeout(typeWriter, 80);
        }
    }
    
    // Ajout des styles dynamiques d'animation clignotante
    const style = document.createElement('style');
    style.innerHTML = `@keyframes blink { 0%, 100% { opacity: 1; } 50% { opacity: 0; } }`;
    document.head.appendChild(style);
    
    setTimeout(typeWriter, 1000);

    // Initialisation du Slider Before/After
    const baRange = document.getElementById('ba-range');
    if (baRange) {
        const imgBefore = document.getElementById('ba-img-before');
        const handleBox = document.getElementById('ba-handle');
        
        baRange.addEventListener('input', (e) => {
            const val = e.target.value;
            imgBefore.style.clipPath = `inset(0 ${100 - val}% 0 0)`;
            handleBox.style.left = `${val}%`;
        });
    }
});
</script>

</body>
</html>

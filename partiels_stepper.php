<?php
// partiels_stepper.php
// Nécessite la variable $current_step_status avant inclusion.

$step_index = 0;
if (in_array($current_step_status, ['attente', 'refuse'])) {
    $step_index = 1;
} elseif ($current_step_status === 'accepte_integration') {
    $step_index = 2;
} elseif (in_array($current_step_status, ['essai', 'essai_adhesion', 'essai_termine'])) {
    $step_index = 3;
} elseif (in_array($current_step_status, ['joueur', 'active'])) {
    $step_index = 4;
}

$is_refused = ($current_step_status === 'refuse');
?>
<style>
.stepper-container {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 40px;
    position: relative;
    padding: 0 20px;
}
.stepper-container::before {
    content: '';
    position: absolute;
    top: 50%;
    left: 40px;
    right: 40px;
    height: 4px;
    background: rgba(255, 255, 255, 0.1);
    z-index: 0;
    transform: translateY(-50%);
    border-radius: 2px;
}
.stepper-step {
    position: relative;
    z-index: 1;
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 10px;
    flex: 1;
}
.stepper-circle {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    background: var(--bg-secondary);
    border: 3px solid var(--border-color);
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: bold;
    color: var(--text-muted);
    transition: all 0.3s ease;
}
.stepper-label {
    font-size: 0.85rem;
    color: var(--text-muted);
    text-transform: uppercase;
    font-weight: bold;
    text-align: center;
    transition: all 0.3s ease;
}

/* Status styles */
.stepper-step.completed .stepper-circle {
    background: var(--accent-success);
    border-color: var(--accent-success);
    color: white;
}
.stepper-step.completed .stepper-label {
    color: var(--text-primary);
}

.stepper-step.active .stepper-circle {
    background: var(--accent-primary); /* Primary blue */
    border-color: var(--accent-primary);
    color: white;
    box-shadow: 0 0 15px var(--accent-primary);
    transform: scale(1.1);
}
.stepper-step.active .stepper-label {
    color: var(--accent-primary);
}

.stepper-step.refused .stepper-circle {
    background: var(--accent-red);
    border-color: var(--accent-red);
    color: white;
    box-shadow: 0 0 15px var(--accent-red);
}
.stepper-step.refused .stepper-label {
    color: var(--accent-red);
}

/* Progress bar fill */
.stepper-progress {
    position: absolute;
    top: 50%;
    left: 40px;
    height: 4px;
    background: var(--accent-success);
    z-index: 0;
    transform: translateY(-50%);
    transition: width 0.5s ease;
    border-radius: 2px;
}

@media (max-width: 600px) {
    .stepper-container {
        padding: 0 10px;
        margin-bottom: 25px;
    }
    .stepper-container::before, .stepper-progress {
        left: 20px;
        right: 20px;
    }
    .stepper-circle {
        width: 32px;
        height: 32px;
        font-size: 0.85rem;
        border-width: 2px;
    }
    .stepper-label {
        font-size: 0.65rem;
        line-height: 1.2;
    }
}
</style>

<?php
$progress_widths = [1 => 0, 2 => 33, 3 => 66, 4 => 100];
$current_width = $progress_widths[$step_index] ?? 0;
?>

<div class="stepper-container" style="background: transparent; border: none; box-shadow: none; max-width: 800px; margin-left: auto; margin-right: auto; margin-top: 20px;">
    <div class="stepper-progress" style="width: calc(<?= $current_width ?>% - 80px);"></div>
    
    <div class="stepper-step <?= $step_index > 1 ? 'completed' : ($step_index == 1 ? ($is_refused ? 'refused' : 'active') : '') ?>">
        <div class="stepper-circle"><?= $step_index > 1 ? '✓' : ($is_refused ? '✗' : '1') ?></div>
        <div class="stepper-label">Candidature</div>
    </div>
    
    <div class="stepper-step <?= $step_index > 2 ? 'completed' : ($step_index == 2 ? 'active' : '') ?>">
        <div class="stepper-circle"><?= $step_index > 2 ? '✓' : '2' ?></div>
        <div class="stepper-label">Intégration</div>
    </div>
    
    <div class="stepper-step <?= $step_index > 3 ? 'completed' : ($step_index == 3 ? 'active' : '') ?>">
        <div class="stepper-circle"><?= $step_index > 3 ? '✓' : '3' ?></div>
        <div class="stepper-label">Période d'Essai</div>
    </div>
    
    <div class="stepper-step <?= $step_index == 4 ? 'completed' : '' ?>">
        <div class="stepper-circle"><?= $step_index == 4 ? '✓' : '4' ?></div>
        <div class="stepper-label">Joueur</div>
    </div>
</div>

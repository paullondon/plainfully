<?php declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$errors = $errors ?? [];
$old    = $old ?? [];

$csrfToken = plainfully_csrf_token();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Start a clarification | Plainfully</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="/assets/css/app.css">
</head>
<body class="pf-page-body">
<main class="pf-page-main">

    <section class="pf-card pf-card--narrow">

        <h1 class="pf-heading">Start a new clarification</h1>

        <?php if (!empty($errors)): ?>
            <div class="pf-alert pf-alert--error">
                <ul>
                    <?php foreach ($errors as $err): ?>
                        <li><?= htmlspecialchars($err, ENT_QUOTES, 'UTF-8') ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <form method="POST" action="/clarifications/new">
            <input type="hidden" name="_token"
                   value="<?= htmlspecialchars($csrfToken, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">

            <div class="pf-field">
                <label class="pf-label">What do you want Plainfully to clarify?</label>
                <textarea
                    name="text"
                    class="pf-input pf-input--textarea"
                    rows="10"
                    maxlength="12000"
                ><?= htmlspecialchars($old['text'] ?? '', ENT_QUOTES, 'UTF-8') ?></textarea>

                <p class="pf-help">Max 12,000 characters.</p>
            </div>

            <div class="pf-field">
                <label class="pf-label">Tone</label>
                <select name="tone" class="pf-input pf-input--select">
                    <?php
                    $tones = ['calm' => 'Calm', 'firm' => 'Firm', 'professional' => 'Professional'];
                    foreach ($tones as $k => $label):
                        $selected = (($old['tone'] ?? '') === $k) ? 'selected' : '';
                        ?>
                        <option value="<?= $k ?>" <?= $selected ?>><?= $label ?></option>
                    <?php endforeach; ?>
                </select>
                <p class="pf-help">This is the style Plainfully will use.</p>
            </div>

            <button type="submit"
                    class="pf-button pf-button--primary pf-button--full">
                Generate clarification
            </button>
        </form>

        <div class="pf-actions pf-actions--center">
            <a href="/dashboard" class="pf-button pf-button--ghost">Back to dashboard</a>
        </div>

    </section>

</main>
</body>
</html>

<?php
/** @var array $errors */
/** @var string $oldText */
/** @var string $oldTone */
?>
<h1 class="pf-page-title">New clarification</h1>
<p class="pf-page-subtitle">
    Paste the message you’d like Plainfully to clarify or check for scams.
</p>

<?php if (!empty($errors)): ?>
    <div class="pf-message-error">
        <ul>
            <?php foreach ($errors as $e): ?>
                <li><?= htmlspecialchars($e, ENT_QUOTES, 'UTF-8') ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<form method="post" action="/clarifications/new" class="pf-form">
    <?= pf_csrf_field(); ?>

    <div class="pf-form-group">
        <label for="tone">Tone</label>
        <select id="tone" name="tone" class="pf-input">
            <option value="calm" <?= $oldTone === 'calm' ? 'selected' : '' ?>>Calm</option>
            <option value="firm" <?= $oldTone === 'firm' ? 'selected' : '' ?>>Firm</option>
            <option value="professional" <?= $oldTone === 'professional' ? 'selected' : '' ?>>Professional</option>
        </select>
    </div>

    <div class="pf-form-group">
        <label for="text">What do you want Plainfully to check?</label>
        <textarea id="text" name="text" rows="10" class="pf-input"
                  placeholder="Paste the email, text message or wording here..."><?= htmlspecialchars($oldText, ENT_QUOTES, 'UTF-8') ?></textarea>
    </div>

    <button type="submit" class="pf-button-primary">
        Analyse this for me
    </button>
</form>

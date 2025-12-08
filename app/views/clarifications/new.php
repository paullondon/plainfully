<?php
/** @var string|null $error */
?>
<h1 class="pf-page-title">New clarification</h1>

<p class="pf-page-subtitle">
    Paste the message you want Plainfully to check. We’ll analyse it for scam risk and clarity.
</p>

<?php if (!empty($error)): ?>
    <p class="pf-message-error">
        <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
    </p>
<?php endif; ?>

<form method="post" class="pf-form">
    <div class="pf-field">
        <label for="content">Message to check</label>
        <textarea id="content"
                  name="content"
                  rows="8"
                  required
                  placeholder="Paste suspicious email, text, or message here…"></textarea>
    </div>

    <button type="submit" class="pf-button pf-button-primary">
        Run clarification
    </button>
</form>

<p style="margin-top:1.5rem;">
    <a href="/clarifications">Back to your clarifications</a>
</p>

<?php
/** @var string|null $flashError */
?>
<h1 class="pf-page-title">New clarification</h1>

<p class="pf-page-subtitle">
    Paste the text you’d like Plainfully to simplify and explain.
</p>

<?php if (!empty($flashError)): ?>
    <p class="pf-message-error">
        <?= htmlspecialchars($flashError, ENT_QUOTES, 'UTF-8') ?>
    </p>
<?php endif; ?>

<form action="/clarifications" method="POST" class="pf-form">
    <?php pf_csrf_field(); ?>

    <div class="pf-field">
        <label for="title" class="pf-label">Title (optional)</label>
        <input
            id="title"
            name="title"
            type="text"
            class="pf-input"
            placeholder="e.g. Contract clause 4.2, HMRC letter, etc.">
    </div>

    <div class="pf-field">
        <label for="original_text" class="pf-label">Text to clarify</label>
        <textarea
            id="original_text"
            name="original_text"
            class="pf-textarea"
            rows="10"
            required
            placeholder="Paste the confusing bit here..."></textarea>
    </div>

    <button type="submit" class="pf-button">
        Save request
    </button>

    <a href="/clarifications" class="pf-link-inline">
        Cancel
    </a>
</form>

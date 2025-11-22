<?php
// View: Home page (logged-in user)
?>

<h1 class="pf-auth-title">Plainfully</h1>
<p class="pf-auth-subtitle">
    You’re signed in. (User ID: <?= (int)$userId ?>)
</p>

<form method="post" action="/logout">
    <button type="submit" class="pf-button">Sign out</button>
</form>

<?php declare(strict_types=1);

/**
 * ============================================================
 * Plainfully — Coming Soon View
 * ============================================================
 * Purpose:
 *  - Public landing page
 *  - Deliberately vague, calm, and intriguing
 *  - No business logic, no routing, no side effects
 */

?>

<div class="pf-hero">
  <div class="pf-glow" aria-hidden="true"></div>

  <div class="pf-shell">
    <div class="pf-badge">PLAINFULLY</div>

    <h1 class="pf-title">
      Something calm is coming.
    </h1>

    <p class="pf-sub">
      A quiet edge in a noisy world. <span class="pf-dim">No details yet.</span>
    </p>

    <div class="pf-divider"></div>

    <div class="pf-row">
      <div class="pf-kpi">
        <div class="pf-kpi-top">Private by default</div>
        <div class="pf-kpi-bottom">Ephemeral files. Short retention.</div>
      </div>
      <div class="pf-kpi">
        <div class="pf-kpi-top">Designed for clarity</div>
        <div class="pf-kpi-bottom">Simple on the surface.</div>
      </div>
      <div class="pf-kpi">
        <div class="pf-kpi-top">Built to endure</div>
        <div class="pf-kpi-bottom">Boring, solid engineering.</div>
      </div>
    </div>

    <div class="pf-actions">
      <a class="pf-cta" href="/health">System status</a>
      <span class="pf-micro">Invite only</span>
    </div>

    <p class="pf-foot">
      <span class="pf-dot"></span> Guidance through contained confusion.
    </p>
  </div>
</div>

<style>
/* (styles unchanged — exactly as before) */
/* keep them here for now; later we can extract to CSS if wanted */

.pf-hero{
  min-height: calc(100vh - 40px);
  display:flex;
  align-items:center;
  justify-content:center;
  padding: 28px 16px;
  position:relative;
  overflow:hidden;
}

/* rest of CSS stays exactly the same */
</style>

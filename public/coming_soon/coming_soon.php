<?php declare(strict_types=1);

/**
 * ============================================================
 * Plainfully — Coming Soon View
 * ============================================================
 * Purpose:
 *  - Public landing page
 *  - Deliberately vague, calm, and intriguing
 *  - No business logic, no routing, no side effects
 *
 * Notes:
 *  - Logo expected at: /assets/img/plainfully-logo-light.svg
 */

?>

<div class="pf-hero" role="region" aria-label="Plainfully coming soon">
  <div class="pf-backdrop" aria-hidden="true"></div>
  <div class="pf-grain" aria-hidden="true"></div>

  <div class="pf-shell">
    <header class="pf-head">
      <div class="pf-brand" aria-label="Plainfully">
        <img
          class="pf-logo"
          src="/assets/img/plainfully-logo-light.svg"
          alt="Plainfully"
          width="148"
          height="32"
          loading="eager"
          decoding="async"
        />
      </div>

      <div class="pf-badge" aria-label="Status">Invite only</div>
    </header>

    <h1 class="pf-title">
      Something calm is coming.
    </h1>

    <p class="pf-sub">
      A quiet edge in a noisy world. <span class="pf-dim">No details yet.</span>
    </p>

    <div class="pf-divider" aria-hidden="true"></div>

    <div class="pf-row" aria-label="Principles">
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
      <span class="pf-micro" aria-label="Availability">Watch this space</span>
    </div>

    <p class="pf-foot">
      <span class="pf-dot" aria-hidden="true"></span>
      Guidance through contained confusion.
    </p>
  </div>
</div>

<style>
/* ============================================================
   Plainfully — Epic Minimal “Coming Soon” (inline for now)
   ============================================================ */

:root{
  --pf-bg: #0b0f14;
  --pf-surface: rgba(16,19,26,0.78);
  --pf-border: rgba(255,255,255,0.08);
  --pf-border-strong: rgba(255,255,255,0.12);

  --pf-text: #eef0f3;
  --pf-muted: rgba(238,240,243,0.70);
  --pf-dim: rgba(238,240,243,0.55);

  /* restrained “mystery” accent */
  --pf-accent: rgba(90,191,168,0.95);
  --pf-accent-dim: rgba(90,191,168,0.26);

  --pf-radius: 18px;
  --pf-shadow: 0 28px 90px rgba(0,0,0,0.55);
  --pf-shadow-soft: 0 14px 44px rgba(0,0,0,0.35);
}

.pf-hero{
  min-height: calc(100vh - 40px);
  display:flex;
  align-items:center;
  justify-content:center;
  padding: 32px 16px;
  position:relative;
  overflow:hidden;
  background: var(--pf-bg);
  color: var(--pf-text);
}

/* soft, cinematic light, not “glow” */
.pf-backdrop{
  position:absolute;
  inset:-30%;
  background:
    radial-gradient(900px 520px at 22% 18%, rgba(255,255,255,0.06), transparent 55%),
    radial-gradient(780px 560px at 78% 22%, rgba(90,191,168,0.10), transparent 60%),
    radial-gradient(900px 680px at 55% 88%, rgba(255,255,255,0.05), transparent 62%),
    linear-gradient(180deg, rgba(255,255,255,0.02), rgba(0,0,0,0.00));
  filter: blur(10px);
  pointer-events:none;
}

.pf-grain{
  position:absolute;
  inset:0;
  pointer-events:none;
  opacity: 0.06;
  background-image:
    url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='220' height='220'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='.9' numOctaves='3' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='220' height='220' filter='url(%23n)' opacity='.65'/%3E%3C/svg%3E");
  mix-blend-mode: overlay;
}

.pf-shell{
  width: min(980px, 100%);
  padding: clamp(22px, 4vw, 44px);
  border-radius: var(--pf-radius);
  border: 1px solid var(--pf-border);
  background: linear-gradient(180deg, rgba(255,255,255,0.06), rgba(255,255,255,0.02));
  box-shadow: var(--pf-shadow);
  position: relative;
  overflow: hidden;
}

.pf-shell::before{
  content:"";
  position:absolute;
  inset:0;
  border-radius: var(--pf-radius);
  pointer-events:none;
  background:
    linear-gradient(90deg, rgba(90,191,168,0.12), transparent 35%, transparent 65%, rgba(90,191,168,0.10));
  opacity: 0.9;
}

.pf-head{
  display:flex;
  align-items:center;
  justify-content:space-between;
  gap: 14px;
  flex-wrap: wrap;
  position: relative;
}

.pf-brand{
  display:flex;
  align-items:center;
  gap: 12px;
}

.pf-logo{
  height: 32px;
  width: auto;
  display:block;
  opacity: 0.98;
  filter: drop-shadow(0 10px 28px rgba(0,0,0,0.35));
}

.pf-badge{
  display:inline-flex;
  align-items:center;
  gap: 10px;
  font-size: 12px;
  letter-spacing: 0.16em;
  text-transform: uppercase;
  color: var(--pf-muted);
  padding: 10px 12px;
  border-radius: 999px;
  border: 1px solid var(--pf-border);
  background: rgba(0,0,0,0.14);
  box-shadow: var(--pf-shadow-soft);
}

.pf-title{
  margin: 26px 0 10px 0;
  font-size: clamp(34px, 4.8vw, 64px);
  line-height: 1.05;
  letter-spacing: -0.6px;
  font-weight: 650;
  position: relative;
}

.pf-title::after{
  content:"";
  display:block;
  width: 92px;
  height: 2px;
  margin-top: 14px;
  background: linear-gradient(90deg, var(--pf-accent), transparent);
  opacity: 0.9;
}

.pf-sub{
  margin: 0;
  max-width: 62ch;
  font-size: 16px;
  line-height: 1.65;
  color: var(--pf-muted);
}

.pf-dim{
  color: var(--pf-dim);
}

.pf-divider{
  height: 1px;
  background: rgba(255,255,255,0.08);
  margin: 26px 0 18px 0;
}

.pf-row{
  display:grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 12px;
}

@media (max-width: 860px){
  .pf-row{ grid-template-columns: 1fr; }
}

.pf-kpi{
  border: 1px solid var(--pf-border);
  background: rgba(0,0,0,0.12);
  border-radius: 14px;
  padding: 14px 14px;
  position: relative;
  overflow:hidden;
}

.pf-kpi::before{
  content:"";
  position:absolute;
  inset:-1px;
  background:
    radial-gradient(280px 120px at 20% 0%, rgba(90,191,168,0.10), transparent 55%);
  pointer-events:none;
}

.pf-kpi-top{
  font-size: 13px;
  letter-spacing: 0.10em;
  text-transform: uppercase;
  color: rgba(238,240,243,0.86);
  margin-bottom: 6px;
}

.pf-kpi-bottom{
  font-size: 14px;
  color: var(--pf-muted);
  line-height: 1.5;
}

.pf-actions{
  display:flex;
  align-items:center;
  gap: 14px;
  margin-top: 20px;
  flex-wrap: wrap;
}

.pf-cta{
  display:inline-flex;
  align-items:center;
  justify-content:center;
  min-height: 44px;
  padding: 12px 16px;
  border-radius: 12px;
  border: 1px solid var(--pf-border-strong);
  background: rgba(255,255,255,0.06);
  color: var(--pf-text);
  text-decoration:none;
  font-weight: 600;
  letter-spacing: 0.02em;
  box-shadow: var(--pf-shadow-soft);
  transition: transform 120ms ease, background 120ms ease, border-color 120ms ease;
}

.pf-cta:hover{
  transform: translateY(-1px);
  background: rgba(255,255,255,0.08);
  border-color: rgba(90,191,168,0.28);
}

.pf-cta:focus{
  outline: 2px solid rgba(90,191,168,0.45);
  outline-offset: 3px;
}

.pf-micro{
  font-size: 13px;
  color: var(--pf-dim);
  border-left: 1px solid rgba(255,255,255,0.08);
  padding-left: 14px;
}

.pf-foot{
  margin: 22px 0 0 0;
  display:flex;
  align-items:center;
  gap: 10px;
  color: var(--pf-dim);
  font-size: 13px;
}

.pf-dot{
  width: 8px;
  height: 8px;
  border-radius: 999px;
  background: var(--pf-accent);
  box-shadow: 0 0 0 6px var(--pf-accent-dim);
}
</style>
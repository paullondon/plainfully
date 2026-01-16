<?php declare(strict_types=1);

/**
 * ============================================================
 * Plainfully — Minimal Coming Soon Front Page
 * ============================================================
 * Philosophy:
 *   - Minimal
 *   - Quietly confident
 *   - Nothing given away
 *   - Feels deliberate, not placeholder
 */

http_response_code(200);
header('Content-Type: text/html; charset=utf-8');
header('X-Robots-Tag: noindex, nofollow');

$year = (int)date('Y');
$logoUrl = '/assets/img/logo-icon.svg';
?>
<!doctype html>
<html lang="en-GB">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">

  <title>Plainfully</title>
  <meta name="description" content="Something is coming." />
  <meta name="robots" content="noindex, nofollow">

  <style>
    /* ============================================================
       Minimal / Mysterious Theme
       ============================================================ */

    :root {
      --bg: #0d0f12;          /* near-black, not pure */
      --surface: #12151a;     /* subtle separation */
      --text: #e6e7ea;        /* soft white */
      --muted: #9ca3af;       /* restraint */
      --accent: #6ee7b7;      /* quiet confidence */
      --border: rgba(255,255,255,0.06);
    }

    * { box-sizing: border-box; }

    html, body {
      height: 100%;
      margin: 0;
    }

    body {
      background: var(--bg);
      color: var(--text);
      font-family: system-ui, -apple-system, Segoe UI, Roboto, Helvetica, Arial;
      display: grid;
      place-items: center;
      padding: 32px;
    }

    .frame {
      width: 100%;
      max-width: 860px;
      border: 1px solid var(--border);
      background: var(--surface);
      padding: clamp(32px, 6vw, 72px);
    }

    header {
      display: flex;
      align-items: center;
      gap: 14px;
      margin-bottom: 56px;
    }

    .logo {
      width: 36px;
      height: 36px;
    }

    .logo img {
      width: 100%;
      height: 100%;
      display: block;
    }

    .brand {
      font-weight: 600;
      letter-spacing: 0.3px;
      font-size: 15px;
    }

    main {
      max-width: 520px;
    }

    h1 {
      font-size: clamp(32px, 4vw, 56px);
      line-height: 1.05;
      font-weight: 600;
      margin: 0 0 18px 0;
    }

    p {
      margin: 0;
      color: var(--muted);
      font-size: 16px;
      line-height: 1.6;
    }

    .hint {
      margin-top: 32px;
      font-size: 13px;
      letter-spacing: 0.2px;
      color: var(--muted);
    }

    .hint span {
      color: var(--accent);
    }

    footer {
      margin-top: 72px;
      display: flex;
      justify-content: space-between;
      font-size: 12px;
      color: var(--muted);
    }

    @media (max-width: 640px) {
      footer {
        flex-direction: column;
        gap: 12px;
      }
    }
  </style>
</head>

<body>
  <section class="frame" role="region" aria-label="Plainfully coming soon">
    <header>
      <span class="logo" aria-hidden="true">
        <img src="<?= htmlspecialchars($logoUrl, ENT_QUOTES, 'UTF-8') ?>" alt="">
      </span>
      <span class="brand">Plainfully</span>
    </header>

    <main>
      <h1>Something is coming.</h1>
      <p>
        Not loud.  
        Not rushed.  
        Built properly.
      </p>

      <div class="hint">
        <span>Watch this space.</span>
      </div>
    </main>

    <footer>
      <span>© <?= $year ?> Plainfully</span>
      <span>Quietly in progress</span>
    </footer>
  </section>
</body>
</html>

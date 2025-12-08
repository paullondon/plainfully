<?php
declare(strict_types=1);

use App\Features\Checks\CheckInput;
use App\Features\Checks\CheckEngine;
use App\Features\Checks\DummyAiClient;

// Tell bootstrap not to load routes/web.php
define('PLAINFULLY_SKIP_ROUTER', true);

// 1) Load your app bootstrap (env, config, helpers, db, etc.)
require __DIR__ . '/../bootstrap/app.php';

// 2) Load the CheckEngine feature classes (no composer autoload yet)
require __DIR__ . '/../app/features/checks/check_input.php';
require __DIR__ . '/../app/features/checks/check_result.php';
require __DIR__ . '/../app/features/checks/ai_client.php';
require __DIR__ . '/../app/features/checks/check_engine.php';

// 3) Get PDO via your existing DB helper (from app/support/db.php)
$pdo = pf_db(); // this should already exist in your project

$aiClient    = new DummyAiClient();
$checkEngine = new CheckEngine($pdo, $aiClient);

$result = null;
$error  = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $channel          = $_POST['channel'] ?? 'web';
        $sourceIdentifier = $_POST['source_identifier'] ?? 'test@example.com';
        $contentType      = 'text/plain';
        $rawContent       = $_POST['content'] ?? '';
        $email            = $_POST['email'] ?? null;
        $phone            = $_POST['phone'] ?? null;
        $isPaid           = isset($_POST['is_paid']) && $_POST['is_paid'] === '1';

        $input = new CheckInput(
            $channel,
            $sourceIdentifier,
            $contentType,
            $rawContent,
            $email ?: null,
            $phone ?: null,
            null // provider_user_id
        );

        $result = $checkEngine->run($input, $isPaid);
    } catch (Throwable $t) {
        $error = $t->getMessage();
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Plainfully – CheckEngine Dev Test</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        body {
            background:#111;
            color:#eee;
            font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            padding:20px;
        }
        .pf-form {
            max-width: 800px;
            margin: 0 auto;
            background:#1c1c1c;
            padding:20px;
            border-radius:12px;
        }
        label {
            display:block;
            margin-top:10px;
            font-size:0.9rem;
            color:#ccc;
        }
        input[type="text"], textarea, select {
            width:100%;
            padding:8px;
            margin-top:4px;
            border-radius:6px;
            border:1px solid #333;
            background:#111;
            color:#eee;
            font-size:0.9rem;
        }
        textarea {
            min-height:150px;
            resize:vertical;
        }
        button {
            margin-top:15px;
            padding:10px 16px;
            border:0;
            border-radius:999px;
            background:#1AB385;
            color:#000;
            font-weight:600;
            cursor:pointer;
        }
        button:hover {
            filter:brightness(1.1);
        }
        .pf-result, .pf-error {
            max-width:800px;
            margin:20px auto;
            padding:16px;
            border-radius:12px;
        }
        .pf-result {
            background:#16251d;
            border:1px solid #1AB385;
        }
        .pf-error {
            background:#2b1111;
            border:1px solid #ff4d4f;
        }
        pre {
            white-space:pre-wrap;
            word-wrap:break-word;
            font-size:0.8rem;
        }
        .pf-meta {
            font-size:0.85rem;
            color:#aaa;
            margin-bottom:8px;
        }
    </style>
</head>
<body>
    <div class="pf-form">
        <h1>CheckEngine – Dev Test</h1>
        <p>Use this form to send content into the core CheckEngine and see the result.</p>

        <form method="post">
            <label>
                Channel
                <select name="channel">
                    <option value="web">web</option>
                    <option value="email">email</option>
                    <option value="sms">sms</option>
                    <option value="whatsapp">whatsapp</option>
                    <option value="api">api</option>
                </select>
            </label>

            <label>
                Source identifier (email, phone, or provider ID)
                <input type="text" name="source_identifier" value="test@example.com">
            </label>

            <label>
                Email (optional)
                <input type="text" name="email" value="">
            </label>

            <label>
                Phone (optional)
                <input type="text" name="phone" value="">
            </label>

            <label>
                Content to check
                <textarea name="content" placeholder="Paste the suspicious message here..."></textarea>
            </label>

            <label>
                Is paid?
                <select name="is_paid">
                    <option value="0">No (free tier)</option>
                    <option value="1">Yes (paid)</option>
                </select>
            </label>

            <button type="submit">Run Check</button>
        </form>
    </div>

    <?php if ($error !== null): ?>
        <div class="pf-error">
            <strong>Error:</strong>
            <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
        </div>
    <?php endif; ?>

    <?php if ($result !== null && $error === null): ?>
        <div class="pf-result">
            <div class="pf-meta">
                Check ID: <?= (int)$result->checkId ?> |
                Scam: <?= $result->isScam ? 'YES' : 'NO' ?> |
                Paid: <?= $result->isPaid ? 'YES' : 'NO' ?>
            </div>

            <h2>Short verdict</h2>
            <p><?= htmlspecialchars($result->shortVerdict, ENT_QUOTES, 'UTF-8') ?></p>

            <h2>Long report</h2>
            <p><?= nl2br(htmlspecialchars($result->longReport, ENT_QUOTES, 'UTF-8')) ?></p>

            <h2>Input capsule</h2>
            <p><?= nl2br(htmlspecialchars($result->inputCapsule, ENT_QUOTES, 'UTF-8')) ?></p>

            <h2>Upsell flags</h2>
            <pre><?= htmlspecialchars(print_r($result->upsellFlags, true), ENT_QUOTES, 'UTF-8') ?></pre>
        </div>
    <?php endif; ?>
</body>
</html>

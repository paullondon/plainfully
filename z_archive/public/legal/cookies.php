<?php
/*
 * ---------------------------------------------------------------------------
 * Copyright (c) 2025 Paul London. All rights reserved.
 * This file is proprietary code owned exclusively by Paul London.
 * Unauthorized copying, distribution, modification, or use of this file,
 * in whole or in part, is strictly prohibited.
 * ---------------------------------------------------------------------------
 */
declare(strict_types=1);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Cookie Policy | EventForm</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <link rel="stylesheet" href="/assets/css/app.css">
    <link rel="stylesheet"
          href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">

    <style>
        .ef-legal-page {
            min-height: 100vh;
            margin: 0;
            background: #050608;
            color: #F7F8FA;
            font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            display: flex;
            align-items: flex-start;
            justify-content: center;
            padding: 32px 16px;
        }

        .ef-legal-shell {
            width: 100%;
            max-width: 920px;
        }

        .ef-legal-brand {
            text-align: left;
            margin-bottom: 20px;
        }

        .ef-legal-logo {
            max-width: 220px;
            height: auto;
            display: block;
            margin-bottom: 6px;
        }

        .ef-legal-tagline {
            font-size: 0.9rem;
            color: #9DA3AF;
        }

        .ef-legal-card {
            background: #10131A;
            border-radius: 16px;
            border: 1px solid #272B33;
            box-shadow: 0 18px 45px rgba(0, 0, 0, 0.65);
            padding: 24px 22px 26px 22px;
        }

        .ef-legal-card h1 {
            margin-top: 0;
            margin-bottom: 4px;
            font-size: 1.6rem;
        }

        .ef-legal-subtitle {
            margin: 0 0 16px 0;
            font-size: 0.95rem;
            color: #9DA3AF;
        }

        .ef-legal-card h2 {
            margin-top: 20px;
            margin-bottom: 6px;
            font-size: 1.15rem;
        }

        .ef-legal-card h3 {
            margin-top: 16px;
            margin-bottom: 4px;
            font-size: 1.02rem;
        }

        .ef-legal-card p {
            margin-top: 0;
            margin-bottom: 10px;
            font-size: 0.94rem;
            line-height: 1.6;
        }

        .ef-legal-card ul {
            margin: 0 0 10px 1.1rem;
            padding-left: 0;
            font-size: 0.94rem;
            line-height: 1.6;
        }

        .ef-legal-card li {
            margin-bottom: 4px;
        }

        .ef-legal-card a {
            color: #FA781F;
            text-decoration: underline;
        }

        .ef-legal-card a:hover {
            text-decoration: none;
        }

        .ef-legal-footer {
            margin-top: 14px;
            font-size: 0.9rem;
            text-align: right;
        }

        .ef-legal-footer a {
            color: #FA781F;
            text-decoration: none;
        }

        .ef-legal-footer a:hover {
            text-decoration: underline;
        }

        @media (max-width: 640px) {
            .ef-legal-card {
                padding: 18px 16px 20px 16px;
            }
        }
    </style>
</head>
<body class="ef-legal-page">
<main class="ef-legal-shell" aria-labelledby="ef-cookie-title">
    <header class="ef-legal-brand">
        <img src="/assets/img/logo/horizontal-dark.svg"
             alt="EventForm"
             class="ef-legal-logo">
        <p class="ef-legal-tagline">Where events take shape.</p>
    </header>

    <article class="ef-legal-card">
        <h1 id="ef-cookie-title">Cookie Policy</h1>
        <p class="ef-legal-subtitle">
            A quick overview of how EventForm uses cookies to keep things running smoothly.
        </p>

        <h2>1. How we use cookies</h2>
        <p>
            EventForm uses cookies and similar technologies to provide a secure, reliable experience.
            Cookies are small text files stored on your device by your browser.
        </p>

        <h3>1.1 Essential cookies (required)</h3>
        <p>These are necessary for EventForm to work, for example:</p>
        <ul>
            <li>Keeping you logged in between page loads</li>
            <li>Protecting your account and preventing fraud</li>
            <li>Supporting payment flows and role switching</li>
        </ul>
        <p>
            You cannot disable these cookies without breaking core functionality of the site.
        </p>

        <h3>1.2 Functional cookies</h3>
        <p>These help improve your experience, such as:</p>
        <ul>
            <li>Remembering view or filter preferences</li>
            <li>Storing UI choices to make navigation faster</li>
        </ul>

        <h3>1.3 Analytics cookies (optional / future)</h3>
        <p>
            Analytics cookies help us understand how EventForm is used so we can improve stability
            and performance. Where these are used, they will:
        </p>
        <ul>
            <li>Not be used for advertising or third-party marketing</li>
            <li>Be used in aggregate form to understand general usage patterns</li>
        </ul>
        <p>
            Where required by law, analytics cookies will only be activated if you choose to opt in.
        </p>

        <h2>2. Managing cookies</h2>
        <p>
            Most browsers allow you to clear or block cookies in their settings. If you block
            essential cookies, EventForm may not function correctly, and you may not be able to log
            in or use the service.
        </p>

        <h2>3. Updates</h2>
        <p>
            We may update this Cookie Policy from time to time. The latest version will always be
            available on this page.
        </p>

        <footer class="ef-legal-footer">
            <a href="/login">Back to login</a>
        </footer>
    </article>
</main>

<div id="ef-cookie-banner" class="ef-cookie-banner" style="display:none;">
    <div class="ef-cookie-banner-inner">
        <p class="ef-cookie-banner-text">
            EventForm uses cookies to keep you logged in, secure your account, and remember your preferences.
            For details, see our
            <a href="/legal/cookies.php" target="_blank" rel="noopener noreferrer">Cookie Policy</a>.
        </p>
        <div class="ef-cookie-banner-actions">
            <button id="ef-cookie-accept" class="ef-btn ef-btn-primary">
                Accept
            </button>
        </div>
    </div>
</div>

<script src="/assets/js/cookies.js" defer></script>
</body>
</html>

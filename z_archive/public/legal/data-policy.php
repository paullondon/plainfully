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
    <title>Data Usage &amp; Retention Policy | EventForm</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <!-- Global stylesheet + icons -->
    <link rel="stylesheet" href="/assets/css/app.css">
    <link rel="stylesheet"
          href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">

    <style>
        /* Legal page wrapper */
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
            margin: 0 0 14px 0;
            font-size: 0.95rem;
            color: #9DA3AF;
        }

        .ef-legal-meta {
            font-size: 0.85rem;
            color: #9DA3AF;
            margin-bottom: 18px;
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
<main class="ef-legal-shell" aria-labelledby="ef-legal-title">
    <header class="ef-legal-brand">
        <img src="/assets/img/logo/horizontal-dark.svg"
             alt="EventForm"
             class="ef-legal-logo">
        <p class="ef-legal-tagline">Where events take shape.</p>
    </header>

    <article class="ef-legal-card">
        <h1 id="ef-legal-title">Data Usage &amp; Retention Policy</h1>
        <p class="ef-legal-subtitle">
            EventForm keeps organisers, venues and vendors connected — and your data protected.
        </p>
        <p class="ef-legal-meta">Last updated: 2025</p>

        <p>
            EventForm (“we”, “us”) collects and processes personal data so that the platform can
            function safely and effectively. By using EventForm, you agree to the data handling
            practices described in this policy.
        </p>

        <h2>1. Data we collect</h2>
        <ul>
            <li>Account details (such as name, email, contact details you provide)</li>
            <li>Vendor, organiser, venue and event information that you submit</li>
            <li>Uploaded documents, images, compliance files and other media</li>
            <li>Billing and payment details (card data handled by Stripe, not stored by us)</li>
            <li>System logs, IP addresses, device information and security metadata</li>
            <li>Technical and usage analytics to help improve performance and reliability</li>
        </ul>

        <h2>2. How we use your data</h2>
        <p>We process data in order to:</p>
        <ul>
            <li>Operate your EventForm account and roles (User, Vendor, Organiser, Venue Manager)</li>
            <li>Run events, bookings, invitations, document workflows and compliance checks</li>
            <li>Process payments, deposits, payouts and refunds through our payment providers</li>
            <li>Provide platform security, fraud prevention and operational monitoring</li>
            <li>Improve the stability, usability and performance of EventForm</li>
            <li>Meet legal, financial, tax and audit obligations</li>
        </ul>

        <h2>3. Data sharing</h2>
        <p>We only share data where necessary for platform operation or legal reasons, for example:</p>
        <ul>
            <li>Payment processors (such as Stripe) to handle payments and payouts</li>
            <li>Email / messaging providers to send notifications you request or require</li>
            <li>Regulators, law enforcement or legal advisers where required by law</li>
        </ul>
        <p>We do not sell personal data to third parties.</p>

        <h2>4. Data retention</h2>
        <ul>
            <li>Account data is kept while your account is active.</li>
            <li>
                Event, vendor, organiser and venue records may be retained to support ongoing
                operations, audit trails and financial records.
            </li>
            <li>System and security logs are typically kept for up to 24 months.</li>
            <li>
                Some records must be retained for longer where required by law (for example,
                accounting and tax records).
            </li>
        </ul>

        <h2>5. Your rights</h2>
        <p>
            Depending on your location, you may have rights under data protection laws
            (for example, GDPR in the UK/EU), including the right to:
        </p>
        <ul>
            <li>Access the personal data we hold about you</li>
            <li>Request correction of inaccurate data</li>
            <li>Request deletion of your data where legally possible</li>
            <li>Request a copy of the data you have provided in a portable format</li>
            <li>Object to or restrict certain types of processing where applicable</li>
        </ul>
        <p>
            We may need to keep some data even after a deletion request where we are legally
            required to do so (for example, financial records).
        </p>

        <h2>6. Cookies &amp; tracking</h2>
        <p>
            EventForm uses cookies and similar technologies for login sessions, security,
            preference storage and basic analytics. For full details, see our
            <a href="/legal/cookies.php">Cookie Policy</a>.
        </p>

        <h2>7. Contact</h2>
        <p>
            If you have questions about this policy or wish to exercise your data rights, please
            contact the EventForm support team using the details provided within the platform.
        </p>

        <p>
            By using EventForm, you confirm that you have read and understood this
            Data Usage &amp; Retention Policy.
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

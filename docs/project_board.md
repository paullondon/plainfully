# PLAINFULLY — MULTI-CHANNEL MVP EXTENSION  
This document adds the required FEATURES, RELEASES, and implementation order for:

1. Core Check Engine (unifies ALL ingestion types)  
2. Email Ingestion + Email Reply System  
3. SMS Ingestion (TextCheck)  
4. Universal Ingestion ("Put anything into the site")  

This section becomes part of the **master roadmap** and follows the same format:  
Commit naming → `F{feature}-R{release}-{build number}`  
This **IS the prompt** for all future ChatGPT development sessions.

---

# DEVELOPMENT ORDER (NEW)

Plainfully will now ship in this sequence:

1️⃣ **FEATURE 6 — CheckEngine (Core System)**  
2️⃣ **FEATURE 7 — Email Ingestion & Email Reply System**  
3️⃣ **FEATURE 10 — SMS Ingestion (“TextCheck by Plainfully”)**  
4️⃣ **FEATURE 14 — Universal Ingestion (Any Source → Site)** *(New)*  

This ordering ensures:

- Core logic exists once (not duplicated)  
- Email route becomes the cheapest/fastest test bed  
- SMS TextCheck can instantly monetise  
- Universal ingestion becomes one final wrapper around CheckEngine  

---

# FEATURE 6 — Multi-Channel Check Engine (CORE BRAIN)

⚠️ **This is now the FIRST deliverable to build, before Email & SMS.**

## R1 — Core CheckEngine Service (PRIORITY)
- [x] Create `checks` table:
  - `user_id`, `channel`, `source_identifier`
  - `raw_content`
  - `content_type`
  - `ai_result_json`
  - `short_summary`
  - `is_scam`
  - `is_paid`
  - timestamps

- [x] Build `CheckEngine` class:
  - Normalised input object
  - Create/find user by email / phone / provider ID
  - AI classification
  - AI clarity output
  - Upsell flags
  - Store results in `checks`
  - Return:
    - short verdict
    - long-form report
    - Input Summary Capsule

- [x] Input safety layer:
  - Size caps
  - Spam detection
  - URL extraction + stripping
  - Offensive-content prefilter
  - Graceful fallback if AI fails

- [x] ZERO RAW STORAGE:
  - Raw content processed in memory
  - Never stored in database
  - Only capsule & summaries persist

---

## R2 — Input Summary Capsule
Used everywhere instead of storing the raw user input.

- [x] Capsule fields:
  - Input type (email, sms, upload, etc.)
  - Character count
  - Keyword risk count
  - Clarity/quality score
  - Processing time
  - “Raw content securely discarded”

- [x] Capsule must appear:
  - Web result pages
  - Email full-report (subscriber only)
  - SMS/WhatsApp/Messenger expansions (future)

---

## R3 — Web Ingestion Integration
- [ ] Route existing consultation inputs → CheckEngine
- [ ] Replace old consultation summaries with capsule
- [ ] Apply plan quota logic
- [ ] Upsell constraints
- [ ] Use AI clarity engine for final output

---

# FEATURE 7 — Email Ingestion (NEXT AFTER CORE ENGINE)

Plainfully will first support **scamcheck@plainfully.com** & **clarify@plainfully.com** as free ingestion channels for testing.

## R1 — Email Routing (via inbound provider)
- [ ] Configure inbound email → webhook:
  - `/ingest/email/scamcheck`
  - `/ingest/email/clarify`
- [ ] Parse:
  - from_email  
  - subject  
  - body  
  - attachments (optional OCR later)
- [ ] Normalise → CheckEngine InputObject

## R2 — Email Reply Logic
### Non-Subscribers
- [ ] Send **short summary** to the sender
- [ ] Include magic-link login to full dashboard result
- [ ] Drive upsell notice for more checks / more details

### Subscribers
- [ ] Full report emailed back
- [ ] Include Input Summary Capsule
- [ ] Include link to dashboard page

---

# FEATURE 10 — SMS Ingestion (“Plainfully TextCheck”)

This becomes our **first monetising ingestion channel**.

## R1 — Integration via The SMS Works (Long Code)
- [ ] `/ingest/sms/inbound` endpoint
- [ ] Extract:
  - `from` (phone)
  - `message`
- [ ] Normalise → CheckEngine input
- [ ] Store result
- [ ] Generate short-summary SMS reply
- [ ] Include magic-link for full report
- [ ] Respect quotas unless paid

## R2 — TextCheck Monetisation Layer
- [ ] £1 per check premium path (future)
- [ ] Track paid checks via `is_paid=true`
- [ ] Bypass monthly limits for paid checks
- [ ] Later: short code support

---

# FEATURE 14 — Universal Ingestion (“Put Anything Into The Site”)  
*(NEW FEATURE — final phase)*

This feature wraps ALL ingestion logic into a single entry point so users can:

- Paste text  
- Upload screenshots  
- Share links  
- Forward emails  
- Forward SMS  
- Drop voice notes (future)  

Everything funnels into CheckEngine with **no special cases**.

## R1 — Unified Endpoint
- [ ] `/ingest/universal`
- [ ] Detect content-type
  - Text
  - OCR image
  - Email-parsed
  - Phone message
  - URL extraction
- [ ] Normalise to InputObject → CheckEngine

## R2 — Universal Dashboard View
- [ ] List of all checks by channel
- [ ] Channel filters
- [ ] Status: scam / safe / unclear
- [ ] Capsule preview
- [ ] Link to full report

---

# WHY THIS ORDER?

| Stage | Reason | Value |
|-------|--------|--------|
| **1. CheckEngine First** | This eliminates duplicated logic across SMS, Email, Web, WhatsApp | Reliability + reduced cost |
| **2. Email Ingestion** | FREE to test, instant throughput, rapid development | Lets you verify the full engine |
| **3. SMS TextCheck** | Real revenue stream → funds further dev | Public launch entry point |
| **4. Universal Ingestion** | Final user convenience layer | One simple “give us anything” tool |

---

# APPENDIX — Required ENV Vars (Aggregated)
- `CHECKENGINE_API_KEY`  
- `CHECKENGINE_LOG_LEVEL`  
- `EMAIL_INGEST_SECRET`  
- `SMSWORKS_KEY`  
- `SMSWORKS_SECRET`  
- `MAGIC_TOKEN_SECRET`  
- `STRIPE_SECRET` (Feature 4)  
- `LOG_WEBHOOK_ERRORS=true`  

---
# APPENDIX — FULL FEATURE VISIBILITY INDEX

This appendix lists ALL current Plainfully FEATURES for oversight.  
Detailed tasks remain in the main roadmap sections — this is a **reference-only index**.

---

## FEATURE 1 — Core Consultation System (MVP)
Foundational consultation creation, storage, processing, and rendering.

## FEATURE 2 — OCR & Multi-Image Pipeline
Handles multi-image uploads, quality checks, OCR extraction, and text merging.

## FEATURE 3 — Diagnostic Failure Pipeline
Captures failed OCR/processing attempts and provides admin tools for review.

## FEATURE 4 — Billing & Plans (Stripe)
Plan definitions, user quotas, subscriptions, Stripe Checkout, and webhooks.

## FEATURE 5 — Account & Session Layer
Magic-link authentication, session hardening, profile view, and rate-limits.

## FEATURE 6 — Multi-Channel Check Engine (CORE BRAIN)
Normalised ingestion engine powering EmailCheck, TextCheck, WebCheck, etc.

## FEATURE 7 — Email Ingestion
Inbound email routing, normalisation, short-summary replies, and full-report emails.

## FEATURE 8 — Messenger Ingestion
Meta Messenger webhook ingestion, short verdict responses, and deep-linking.

## FEATURE 9 — WhatsApp Ingestion
WhatsApp Cloud API ingestion pipeline, verdict replies, and upsell links.

## FEATURE 10 — SMS (“TextCheck by Plainfully”)
Long-code inbound, paid premium routes, shortcodes (future), and SMS summary replies.

## FEATURE 11 — Weekly Scam Report Engine
Aggregates trends + scam types, generates AI weekly reports, and optional FB auto-posting.

## FEATURE 12 — Security, Retention & Monitoring
Data retention rules, encrypted storage, observability metrics, and alerting.

## FEATURE 13 — Final Launch Preparation
Plesk setup, Cloudflare rules, Stripe live launch, tester batch, and pre-launch QA.

## FEATURE 14 — Universal Ingestion (“Put Anything Into The Site”)
One endpoint for all ingestion types (text, email, SMS, images, URLs, etc.)  
All inputs → CheckEngine → unified dashboard.

---

# END OF APPENDIX

# DEVELOPMENT SEQUENCE — PLAINFULLY MVP

                      ┌────────────────────────┐
                      │  FEATURE 6             │
                      │  CORE CHECK ENGINE     │
                      └───────────┬────────────┘
                                  │
                     (All ingestion types depend on this)
                                  │
                                  ▼
         ┌──────────────────────────────────────────────┐
         │  FEATURE 7 – EMAIL INGESTION                 │
         │  Cheapest to test / validates core engine     │
         └───────────────────────┬───────────────────────┘
                                 │
                                 ▼
         ┌──────────────────────────────────────────────┐
         │  FEATURE 10 – SMS INGESTION (“TEXTCHECK”)    │
         │  First monetised feature → funds expansion    │
         └───────────────────────┬───────────────────────┘
                                 │
                                 ▼
         ┌──────────────────────────────────────────────┐
         │  FEATURE 14 – UNIVERSAL INGESTION            │
         │  (“Put anything into the site”)               │
         │  Final wrapper around CheckEngine             │
         └───────────────────────┬───────────────────────┘
                                 │
                                 ▼
         ┌──────────────────────────────────────────────┐
         │       FINAL LAUNCH PIPELINE (F13)             │
         └──────────────────────────────────────────────┘

# SUPPORTING SYSTEMS BUILT ALONGSIDE:
- F1: Consultation Core
- F2: OCR Pipeline
- F3: Diagnostic System
- F4: Billing & Plans
- F5: Accounts & Sessions
- F8: Messenger
- F9: WhatsApp
- F11: Weekly Report Engine
- F12: Security & Monitoring

(Core engine unlocks them all.)

# FEATURE MAP — PLAINFULLY SYSTEM

PLAINFULLY
├── FEATURE 1 — Consultation System
│   ├── Data Layer
│   ├── Input Processing
│   ├── Result Page
│   └── Dashboard UX
│
├── FEATURE 2 — OCR / Multi-Image Pipeline
│   ├── Upload Pipeline
│   ├── Image Quality Gate
│   └── OCR Processing
│
├── FEATURE 3 — Diagnostic Failure Pipeline
│   ├── Failure Capture
│   └── Admin Tools
│
├── FEATURE 4 — Billing & Plans (Stripe)
│   ├── Plan Definitions
│   ├── Checkout + Webhooks
│   └── Billing Portal
│
├── FEATURE 5 — Accounts & Sessions
│   ├── Magic Link Auth
│   └── Profile Page
│
├── FEATURE 6 — CORE CHECK ENGINE  ← **Primary System**
│   ├── CheckEngine Service
│   ├── Input Summary Capsule
│   └── Web Ingestion Integration
│
├── FEATURE 7 — Email Ingestion
│   ├── Email Routing
│   └── Email Reply Logic
│
├── FEATURE 8 — Messenger Ingestion
│   ├── Webhook Setup
│   └── Output Messages
│
├── FEATURE 9 — WhatsApp Ingestion
│   ├── Cloud API Inbound
│   └── Output Summary
│
├── FEATURE 10 — SMS (“TextCheck”)
│   ├── Long-Code Ingestion
│   ├── Monetisation Logic
│   └── Future Shortcode Support
│
├── FEATURE 11 — Weekly Scam Report Engine
│   ├── Aggregator
│   └── Optional FB Auto-Post
│
├── FEATURE 12 — Security, Retention & Monitoring
│   ├── Retention Rules
│   ├── Storage Hardening
│   └── Observability
│
├── FEATURE 13 — Final Launch Prep
│   ├── Deployment
│   ├── Stripe Live
│   └── Internal Tester Batch
│
└── FEATURE 14 — UNIVERSAL INGESTION
    ├── Unified Endpoint
    └── Universal Dashboard View


# END OF DOCUMENT  
This file should be placed under:  
`/docs/PLAINFULLY_TEXTCHECK_AND_MULTICHANNEL.md`  
and referenced from the main README roadmap.

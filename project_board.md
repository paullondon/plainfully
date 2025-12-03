# 📦 Plainfully — Unified TODO Roadmap  
A structured build plan combining the Consultation System, Check Engine, Ingestion Channels, SMS TextCheck, OCR pipeline, Billing, and Launch preparation.

Everything below is organised into **Release Packages**, so the platform progresses in stable chunks.

---

# 🚀 R1 — Core Platform Backbone (Consultations, Database, Retention)

### ✔ Consultation Data Layer (Foundations)
- [ ] Create `consultations` table
- [ ] Create `consultation_details` table (encrypted fields)
- [ ] Create `consultation_uploads` table (OCR text + metadata)
- [ ] Add `expires_at` to all non-financial tables
- [ ] Implement daily cleanup job for 28-day rolling deletion
- [ ] Document retention policy (DB-only, no financial deletion)
- [ ] Add aggressive input cleaning & screening pipeline:
  - [ ] Input normalisation
  - [ ] Pre-clean text before sending to AI
  - [ ] Evidence logging that inputs are wiped post-processing

---

# 🚀 R2 — Consultation Flow (Web Clarifications MVP)

### Consultation Creation
- [ ] Build `GET /clarifications/new` form (clean + simple)
- [ ] Validation rules (length, profanity filters)
- [ ] Save consultation + details
- [ ] (Temp) “stub AI reply” for early testing
- [ ] Redirect to final result page

### Consultation Result Page
- [ ] Final Plainfully layout + WCAG compliance
- [ ] NEVER show original user text
- [ ] Show processed clarity in full
- [ ] Add plan-based upsell banner subtly
- [ ] Return to dashboard easily
- [ ] Abandoned consultations:
  - [ ] Track started-but-not-finished  
  - [ ] Add “Cancel” option (instant delete + invisible to user)

### Dashboard Completion
- [ ] Add Plainfully logo in header
- [ ] Bronze / Silver / Gold badges (Basic / Pro / Unlimited)
- [ ] Show recent clarifications (paginated table up to 4 rows)
- [ ] Maintain static page + inner-table pagination
- [ ] Responsive final layout

---

# 🚀 R3 — The Plainfully Check Engine (Multi-Channel Core)

### Core Data Model
- [ ] Extend `users`:
  - [ ] `phone_number`
  - [ ] `email`
  - [ ] `subscription_tier`
  - [ ] `monthly_check_quota`
  - [ ] `monthly_check_used`

- [ ] Create `checks` table (shared for SMS, Email, Web, Messenger, WhatsApp):
  - [ ] `id`
  - [ ] `user_id`
  - [ ] `channel`
  - [ ] `source_identifier`
  - [ ] `raw_content`
  - [ ] `content_type`
  - [ ] `ai_result_json`
  - [ ] `short_summary`
  - [ ] `is_scam`
  - [ ] `is_paid`
  - [ ] `created_at`, `updated_at`
  - [ ] Indexes: `(user_id, created_at)` and `(channel, created_at)`

### CheckEngine Service
- [ ] Unified service handling **all ingestion sources**
- [ ] Standardised input object:
  - `channel`
  - `source_identifier`
  - `raw_content`
  - metadata
- [ ] Process steps:
  - [ ] Identify or create user
  - [ ] Apply quota logic (except paid SMS)
  - [ ] Build prompt + call AI
  - [ ] Parse structured response (risk, reasons, suggested action, clarity)
  - [ ] Save to DB (`checks`)
  - [ ] Return:
    - [ ] `short_summary`
    - [ ] full structured output
- [ ] Apply safety limits (max size, error fallback, spam protection)

### OpenAI Integration
- [ ] Create effective prompt (scam + clarity modes)
- [ ] Create test + live API environments (Sandbox style)
- [ ] Character-count safety cap (to control cost)
- [ ] “Was this helpful?” → self-improving prompt tuning (optional)

---

# 🚀 R4 — Ingestion Channels (Email, Messenger, WhatsApp, Web OCR)

## Email Ingestion (scamcheck@ / clarify@)
- [ ] Configure inbound email provider:
  - Mailgun / Postmark / SendGrid / IMAP poller
- [ ] Routes:
  - [ ] `scamcheck@plainfully.com` → `/ingest/email/scam`
  - [ ] `clarify@plainfully.com` → `/ingest/email/clarify`
- [ ] Parse sender, subject, body, URLs
- [ ] Normalise → CheckEngine input
- [ ] Email replies:

**Non-subscribers:**
  - [ ] Send short summary  
  - [ ] Include magic login link for full detail  
  - [ ] Enforce quotas on web dashboard

**Subscribers (Unlimited £4.99):**
  - [ ] Send full report in email  
  - [ ] Include dashboard link

---

## Messenger Ingestion
- [ ] Create Meta App + connect to Page
- [ ] Webhook: `/ingest/messenger`
- [ ] Verify token + extract sender + message
- [ ] Normalise → CheckEngine
- [ ] Respond:
  - [ ] Short verdict + reason
  - [ ] Link to full report (magic login)

---

## WhatsApp Ingestion
- [ ] Register WhatsApp Business Cloud API
- [ ] Webhook: `/ingest/whatsapp`
- [ ] Extract sender + message
- [ ] Normalise → CheckEngine
- [ ] Reply with summary + upsell link  
- [ ] Dashboard enforces limit → upgrade screen

---

## Web OCR + Multi-Image Upload
- [ ] Support 1–20 images
- [ ] Virus scanning? (Review Cloudflare capabilities)
- [ ] Temporary storage (auto-expire 28 days)
- [ ] OCR pipeline:
  - [ ] Send to OCR (Google Vision)
  - [ ] Merge text with page markers
  - [ ] Feed into CheckEngine
- [ ] Quality scoring:
  - [ ] If poor → “Image hard to read” prompt  
  - [ ] Add guidance/retake page
- [ ] Abandoned uploads → delete immediately

---

# 🚀 R5 — SMS Feature Pack (TextCheck by Plainfully)

## SMS Trial (Long-Code Dev Line)
- [ ] Integrate Twilio/Vonage/etc.
- [ ] Route `/webhooks/sms/test`
- [ ] Extract sender + body
- [ ] Normalise → CheckEngine
- [ ] Send SMS reply:
  - Short verdict  
  - Dashboard link

## Premium SMS (Paid)
- [ ] Integrate UK premium aggregator (Fonix / txtNation)
- [ ] Rent dedicated shortcode
- [ ] `/webhooks/sms/premium`:
  - [ ] Extract charged_amount  
  - [ ] Set `is_paid = true` if >= £1.00  
- [ ] Run through CheckEngine
- [ ] Short SMS summary reply
- [ ] Paid SMS bypasses normal plan limits

---

# 🚀 R6 — Weekly Reports, Billing, Account Layer, Launch

## Weekly Scam Report Engine (Optional but High Value)
- [ ] Create `weekly_reports` table  
- [ ] Cron job:
  - [ ] Aggregate weekly scam stats  
  - [ ] AI-generate weekly summary  
  - [ ] Save result
- [ ] (Optional) Auto-post to FB Page using Graph API

---

## Billing (Stripe)
- [ ] Define Basic, Pro, Unlimited (£4.99) plans
- [ ] Create Stripe prices + IDs
- [ ] Stripe Checkout for upgrades
- [ ] Webhooks:
  - `checkout.session.completed`
  - `customer.subscription.updated`
  - `customer.subscription.deleted`
- [ ] Sync user plan automatically
- [ ] Add Billing Portal link (manage card/cancel)

---

## Account & Session Layer
- [ ] Magic link auth (rate limit + expiry)
- [ ] Hardened session cookies + fingerprinting
- [ ] User profile:
  - [ ] Show plan  
  - [ ] Show billing portal link  
  - [ ] Simple profile fields  

---

## Retention & Security
- [ ] 28-day deletion engine (CRON)
- [ ] Encrypt `consultation_details`
- [ ] Avoid storing excess PII
- [ ] Salt/hide internal relationships
- [ ] Delete scratch/temp content automatically

---

## UX & Support Polish
- [ ] Dashboard polish (WCAG, mobile)
- [ ] “How to take a good photo” help page
- [ ] FAQ + About Plainfully
- [ ] Stylish 404 / 500 pages
- [ ] Upload failure flow (diagnostic ticket path)

---

## Launch Prep
- [ ] Plesk production setup
- [ ] Cloudflare rules (cache, security)
- [ ] Email provider integration (magic link + admin alerts)
- [ ] Swap test → live Stripe keys
- [ ] Create Tester Onboarding Page
- [ ] Add anonymous feedback form
- [ ] Monitor logs for first 72 hrs

---

# ✔ This is the unified, stable-release roadmap for Plainfully.
It compresses **everything we’ve designed** into manageable release packs while keeping full feature clarity.
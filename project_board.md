# PLAINFULLY – MASTER MVP TODO ROADMAP  
(Feature → Release Package structure)  
This file maps the MVP into controllable build chunks.  
Use commit format: F{feature}-R{release}-{build number} e.g. F1-R2-003

---

# ============================================
# FEATURE 1 — Core Consultation System (MVP)
# ============================================

## R1 — Consultation Data Layer (Complete)
- [x] Create `consultations` table  
- [x] Create `consultation_details` (encrypted)  
- [x] Create `consultation_uploads` (OCR text + metadata)  
- [x] Add `expires_at` to non-financial tables  
- [x] Daily cleanup job (28-day rolling deletion)  
- [x] Isolate financial tables (no deletion)  
- [x] Document retention policy  

---

## R2 — Consultation Input & Processing
- [x] `/clarifications/new` form  
- [x] Validate fields  
- [x] Save consultation + details  
- [x] Temporary / stub AI output generator  
- [x] Store result  
- [x] Redirect to `/clarifications/view`  

- [ ] A4 Input cleansing:
  - [ ] Screen & sanitise text before AI  
  - [ ] Fake push/get AI debug mode  
  - [ ] Ensure fake reply is stored to correct consultation  
  - [ ] Evidence of deletion of all raw text  

---

## R3 — Consultation Result Page
- [ ] Render final AI result using full Plainfully styling  
- [ ] Add subtle plan-based upsell banner  
- [ ] NEVER show user’s original input  
- [ ] Abandoned consultations → instant delete & invisible  
- [ ] Ensure user can return to Dashboard cleanly  

---

## R4 — Dashboard & UX Completion
- [ ] Add Plainfully logo next to welcome  
- [ ] Bronze / Silver / Gold badges  
- [ ] Display badges in plan box  
- [ ] Recent clarifications table (paginate inside box only)  
- [ ] Final responsive layout pass  

---

# ============================================
# FEATURE 2 — OCR & Multi-Image Pipeline
# ============================================

## R1 — Upload Pipeline
- [ ] 1–20 image upload  
- [ ] Temp storage (auto-delete 28 days)  
- [ ] Decide on virus scanning (Cloudflare or other?)  
- [ ] Decide: store images on Cloudflare first → batch → OCR?  

## R2 — Quality Check
- [ ] Use AI Vision for clarity scoring  
- [ ] “Too blurry? Retake?”  
- [ ] Build retake guidance page  

## R3 — OCR Processing
- [ ] OCR pages sequentially  
- [ ] Merge text + page markers  
- [ ] Feed into consultation creation flow  
- [ ] Abandoned upload → delete immediately  
- [ ] Failed → send to diagnostic ticket pipeline  

---

# ============================================
# FEATURE 3 — Failure Pipeline (Diagnostics)
# ============================================

## R1 — Ticket Creation
- [ ] On OCR/processing failure → “Send to support?”  
- [ ] Create row in `diagnostic_reports`  
- [ ] Store OCR text + uploads (28-day expiry)  
- [ ] Email internal alert  

## R2 — Admin Tools
- [ ] Magic-link admin login  
- [ ] 28-day “Diagnostic Tickets” view  
- [ ] Protected diagnostic detail page  

---

# ============================================
# FEATURE 4 — Billing & Plans (Stripe)
# ============================================

## R1 — Plan Model
- [ ] Basic / Pro / Unlimited definitions  
- [ ] Monthly quota logic  
- [ ] Store Stripe price IDs  

## R2 — Checkout & Webhooks
- [ ] Connect user ↔ Stripe customer  
- [ ] Upgrade via Stripe Checkout  
- [ ] Handle:
  - [ ] `checkout.session.completed`  
  - [ ] `customer.subscription.updated`  
  - [ ] `customer.subscription.deleted`  
- [ ] Auto-update user plan  

## R3 — Billing Portal
- [ ] Update card details / cancel  
- [ ] Protect route behind magic link login  

---

# ============================================
# FEATURE 5 — Account & Session Layer
# ============================================

## R1 — Magic Link Auth
- [ ] Rate limit per email/IP  
- [ ] Expiring tokens  
- [ ] Hardened cookies  
- [ ] Session fingerprinting  

## R2 — Profile Page
- [ ] Show plan  
- [ ] Link to Stripe portal  
- [ ] Optional: name edit  

---

# ============================================
# FEATURE 6 — Multi-Channel Check Engine (Ingestion)
# ============================================
*A new core system powering SMS, Email, Web, Messenger, WhatsApp.*

## R1 — Core Check Engine (the brain)
- [ ] Create `checks` table  
  - [ ] `user_id`, `channel`, `source_identifier`, `raw_content`  
  - [ ] `content_type` (text/email/ocr/etc.)  
  - [ ] `ai_result_json`  
  - [ ] `short_summary`  
  - [ ] `is_scam`  
  - [ ] `is_paid`  
  - [ ] timestamps + indexes  

- [ ] Build `CheckEngine` service:  
  - [ ] Normalised input  
  - [ ] Create/find user  
  - [ ] Enforce quotas  
  - [ ] Call AI  
  - [ ] Parse structured result  
  - [ ] Save DB entry  
  - [ ] Return short + long output  

- [ ] Safety checks:  
  - [ ] Input size limit  
  - [ ] Abuse detection  
  - [ ] Graceful fallback replies  

---

## R2 — Web Ingestion (already partially exists)
- [ ] Refactor current “clarification” text to use CheckEngine  
- [ ] Connect quotas to subscription  
- [ ] Show full result in dashboard  
- [ ] Apply upsell wall when quota reached  

---

## R3 — Email Ingestion
- [ ] Route:
  - [ ] `scamcheck@plainfully.com` → `/ingest/email/scamcheck`  
  - [ ] `clarify@plainfully.com` → `/ingest/email/clarify`  
- [ ] Build handlers:
  - [ ] Validate inbound email  
  - [ ] Extract From / Subject / Body / URLs  
  - [ ] Send to CheckEngine  

- [ ] Non-subscribers:
  - [ ] Email short answer  
  - [ ] Magic link to full response  

- [ ] Subscribers:
  - [ ] Full report returned in email  

---

## R4 — Messenger Ingestion
- [ ] Meta App + Page integration  
- [ ] `/ingest/messenger` route  
- [ ] Extract sender + text  
- [ ] Send to CheckEngine  
- [ ] Reply with summary + dashboard link  

---

## R5 — WhatsApp Ingestion
- [ ] WhatsApp Cloud API setup  
- [ ] `/ingest/whatsapp`  
- [ ] Same normalisation → CheckEngine  
- [ ] Reply with summary + link  

---

# ============================================
# FEATURE 7 — SMS Product (TextCheck by Plainfully)
# ============================================

## R1 — Test SMS (Cheap Long-Code Trial)
- [ ] Twilio/Vonage inbound webhook `/webhooks/sms/test`  
- [ ] Normalise to CheckEngine  
- [ ] SMS short summary reply + dashboard link  

## R2 — Premium SMS (Paid £1 per check)
- [ ] Integrate UK premium SMS aggregator  
- [ ] Dedicated shortcode rental  
- [ ] `/webhooks/sms/premium` parsing  
- [ ] Extract `charged_amount`  
- [ ] If `>= £1` → mark as `is_paid`  
- [ ] short SMS reply (no quotas apply)  

---

# ============================================
# FEATURE 8 — Weekly Scam Report Engine
# ============================================

## R1 — Weekly Data Aggregator
- [ ] Create `weekly_reports` table  
- [ ] Cron job summarising:
  - [ ] top scam types  
  - [ ] impersonated brands  
  - [ ] keywords  
  - [ ] volumes  
- [ ] AI generate weekly summary  
- [ ] Store FB post text + blog HTML  

## R2 — Facebook Auto-Posting (Optional)
- [ ] Meta App  
- [ ] Page access token  
- [ ] Cron: auto-post weekly report  
- [ ] Log post id  

---

# ============================================
# FEATURE 9 — Security, Retention & Monitoring
# ============================================

## R1 — Retention & Deletion
- [ ] CRON deletion engine (28 days)  
- [ ] Delete:
  - consultations  
  - OCR uploads  
  - diag tickets  
  - temp tables  
- [ ] Don’t delete Stripe/financial records  
- [ ] Log deletions  

## R2 — Secure Storage
- [ ] Encrypt consultation_details  
- [ ] Reduce stored PII  
- [ ] Hash sensitive fields  
- [ ] Ensure table names don’t leak meaning  

## R3 — Observability
- [ ] Admin dashboard:  
  - [ ] daily checks by channel  
  - [ ] scam/safe ratios  
  - [ ] impersonated brand stats  
- [ ] Error monitoring for:
  - [ ] AI errors  
  - [ ] Ingestion failures  
  - [ ] Provider webhook issues  

---

# ============================================
# FEATURE 10 — Final Launch Prep
# ============================================

## R1 — Deployment
- [ ] Plesk final config  
- [ ] Cloudflare cache rules  
- [ ] Cloudflare image resizing (if used)  
- [ ] Email provider for magic links & alerts  

## R2 — Stripe Live Transition
- [ ] Switch to live keys  
- [ ] Live price IDs  
- [ ] Full end-to-end test  

## R3 — Internal Tester Batch
- [ ] Tester onboarding page  
- [ ] Anonymous feedback form  
- [ ] Monitor logs for 72h  

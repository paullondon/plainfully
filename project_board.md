# PLAINFULLY — MASTER MVP TODO ROADMAP  
This roadmap defines all features, releases, and tasks required for Plainfully MVP and multi-channel ingestion.  
This document **IS the prompt** for all future ChatGPT development sessions.

Use commit naming: F{feature}-R{release}-{build number}

---

===
FEATURE 1 — Core Consultation System (MVP)
===

R1 — Consultation Data Layer (✓ Completed)
- [x] consultations table  
- [x] consultation_details (encrypted)  
- [x] consultation_uploads (OCR metadata)  
- [x] expires_at column  
- [x] 28-day deletion cron  
- [x] isolate financial tables  
- [x] retention documentation  

---

R2 — Consultation Input & Processing
- [x] /clarifications/new form  
- [x] Validation rules  
- [x] Persists consultation + details  
- [x] Temporary AI stub  
- [x] View redirect  

Additional items:
- [ ] Input sanitisation pipeline  
- [ ] Text-screening before AI  
- [ ] Fake push/get AI (debug toggle)  
- [ ] Wipe all original raw text after use  
- [ ] Prove deletion in logs  

---

R3 — Consultation Result Page
- [ ] Final result rendering (Plainfully styling)  
- [ ] Upsell banner  
- [ ] Do NOT show original user input  
- [ ] Handle abandoned consultations (instant delete)  
- [ ] Ensure return-to-dashboard path is clear  

---

R4 — Dashboard & UX Completion
- [ ] Logo in welcome header  
- [ ] Bronze/Silver/Gold plan badges  
- [ ] Badge display near plan box  
- [ ] Recent clarifications table w/ internal pagination  
- [ ] Responsive layout pass  

---

===
FEATURE 2 — OCR & Multi-Image Pipeline
===

R1 — Upload Pipeline
- [ ] Upload 1–20 images  
- [ ] Temp storage with 28-day cleanup  
- [ ] Decide on virus-scanning approach  
- [ ] Decide on Cloudflare image staging  

R2 — Image Quality Gate
- [ ] Vision clarity scoring  
- [ ] “Blurry? Retake?” flow  
- [ ] Build retake-guidance page  

R3 — OCR Processing
- [ ] Sequential OCR  
- [ ] Merge text with page markers  
- [ ] Feed into consultation creation  
- [ ] Abandoned uploads → purge immediately  
- [ ] Failed OCR → diagnostic ticket flow  

---

===
FEATURE 3 — Diagnostic Failure Pipeline
===

R1 — Failure Capture
- [ ] “Send to support?” prompt  
- [ ] diagnostic_reports table  
- [ ] Store OCR text + uploads (28-day expiry)  
- [ ] Admin email alert  

R2 — Admin Tools
- [ ] Admin magic-link login  
- [ ] 28-day diagnostic ticket list  
- [ ] Secure diagnostic view page  

---

===
FEATURE 4 — Billing & Plans (Stripe)
===

R1 — Plan Definitions
- [ ] Define Basic / Pro / Unlimited  
- [ ] Quota logic  
- [ ] Stripe price IDs  

R2 — Checkout + Webhooks
- [ ] User↔Stripe customer link  
- [ ] Stripe Checkout for upgrades  
- [ ] Webhooks:
  - [ ] checkout.session.completed  
  - [ ] customer.subscription.updated  
  - [ ] customer.subscription.deleted  
- [ ] Automatic plan transitions  

R3 — Billing Portal
- [ ] Manage billing link  
- [ ] Protect behind magic link  

---

===
FEATURE 5 — Account & Session Layer
===

R1 — Magic Link Auth
- [ ] Rate-limit per email/IP  
- [ ] Expiring login tokens  
- [ ] Hardened session cookies  
- [ ] Session fingerprinting  

R2 — Profile Page
- [ ] Show user’s plan & limits  
- [ ] Link to billing portal  
- [ ] Minimal editable fields  

---

===
FEATURE 6 — Multi-Channel Check Engine (CORE BRAIN)
===
All Plainfully ingestion routes go through this engine.

R1 — Core CheckEngine Service
- [ ] Create `checks` table:
  - `user_id`, `channel`, `source_identifier`,  
  - `raw_content`, `content_type`,  
  - `ai_result_json`, `short_summary`,  
  - `is_scam`, `is_paid`, timestamps  

- [ ] Build `CheckEngine`:
  - Normalised input object  
  - Create/find user via email/phone/platform ID  
  - Enforce plan limits  
  - AI classification  
  - Structured output parsing  
  - Store result  
  - Return short + long formats  

- [ ] Input-safety:
  - Size caps  
  - Abuse detection  
  - Graceful fallback  

---

R2 — Input Summary Capsule (NEW)
Displayed in reports instead of original user input.

- [ ] Create “Input Summary Capsule” block:
  - Upload type  
  - Character count  
  - Keyword risk count  
  - Clarity score (if applicable)  
  - Words kept after reduction  
  - Processing time  
  - “Raw content has now been securely deleted.”  

- [ ] Capsule must appear on:
  - Web result page  
  - Email full-report for subscribers  
  - (Optional) Messenger/WhatsApp expanded views  

- [ ] DO NOT store raw input.  
- [ ] Capsule data must be generated WITHOUT keeping content.  

---

R3 — Web Ingestion Integration
- [ ] Route existing consultation text via CheckEngine  
- [ ] Enforce quotas  
- [ ] Use Input Summary Capsule  
- [ ] Show long-form AI clarity  
- [ ] Apply upsell wall  

---

===
FEATURE 7 — Email Ingestion
===

R1 — Email Routing
- [ ] Configure inbound:
  - scamcheck@plainfully.com  
  - clarify@plainfully.com  
- [ ] Build `/ingest/email/*` handlers  
- [ ] Normalise (from_email, subject, body, urls) → CheckEngine  

R2 — Email Reply Logic
- Non-subscribers:
  - [ ] Short summary reply  
  - [ ] Magic link to dashboard  

- Subscribers:
  - [ ] Full report inside email  
  - [ ] Include Input Summary Capsule  

---

===
FEATURE 8 — Messenger Ingestion
===

R1 — Messenger Setup
- [ ] Meta App + Page  
- [ ] `/ingest/messenger` webhook  
- [ ] Extract sender + message  
- [ ] Normalise → CheckEngine  

R2 — Messenger Output
- [ ] Short verdict  
- [ ] 1–2 reasons  
- [ ] Link to full report  

---

===
FEATURE 9 — WhatsApp Ingestion
===

R1 — WhatsApp Cloud API
- [ ] Register  
- [ ] Build `/ingest/whatsapp`  
- [ ] Normalise message → CheckEngine  
- [ ] Reply with summary + link  

---

===
FEATURE 10 — SMS Product (“TextCheck by Plainfully”)
===

R1 — Cheap Long-Code Trial
- [ ] Twilio/Vonage inbound `/webhooks/sms/test`  
- [ ] Normalise → CheckEngine  
- [ ] SMS reply with short summary + dashboard link  

R2 — Premium SMS (Paid £1/check)
- [ ] Integrate premium-rate SMS aggregator  
- [ ] Dedicated shortcode  
- [ ] `/webhooks/sms/premium`  
- [ ] Extract `charged_amount`  
- [ ] If >= £1 → is_paid=true  
- [ ] Short SMS reply  
- [ ] Bypass monthly limits  

---

===
FEATURE 11 — Weekly Scam Report Engine
===

R1 — Weekly Aggregator
- [ ] weekly_reports table  
- [ ] Cron summary:
  - top scam types  
  - impersonation trends  
  - keywords  
  - counts  
- [ ] AI-generated weekly report  
- [ ] Store fb_post_text + blog_html  

R2 — Facebook Auto-Posting (Optional)
- [ ] Meta App  
- [ ] Page token  
- [ ] Cron auto-post to FB Page  
- [ ] Log post ID  

---

===
FEATURE 12 — Security, Retention & Monitoring
===

R1 — Retention & Cleanup
- [ ] 28-day deletion cron  
- [ ] Delete:
  - consultations  
  - OCR uploads  
  - check raw_content (never stored)  
  - diagnostic tickets  
  - temp tables  
- [ ] Keep Stripe/financial records only  

R2 — Storage Hardening
- [ ] Encrypt consultation_details  
- [ ] Minimise PII footprints  
- [ ] Hash sensitive fields  
- [ ] Avoid leaked meaning in table/column names  

R3 — Observability
- [ ] Internal dashboard:
  - checks per channel  
  - scam vs safe ratio  
  - brand impersonation stats  
- [ ] AI failure logs  
- [ ] Ingestion failure alerts  
- [ ] Provider webhook error pager  

---

===
FEATURE 13 — Final Launch Preparation
===

R1 — Deployment
- [ ] Plesk config  
- [ ] Cloudflare rules  
- [ ] Email provider finalised  

R2 — Stripe Live
- [ ] Live keys  
- [ ] Live price IDs  
- [ ] Full test cycle  

R3 — Internal Tester Batch
- [ ] Tester onboarding page  
- [ ] Anonymous feedback  
- [ ] Monitor logs for 72h  

# Plainfully — MVP TODO Checklist

A structured roadmap of everything required before opening the platform to your first tester batch.

---

## A. Core Product Flow (Essential for MVP)

### A1 — Consultation Data Layer (with 28-day retention)
- [/] Create `consultations` table
- [/] Create `consultation_details` table (encrypted fields)
- [/] Create `consultation_uploads` table (OCR text + metadata)
- [/] Add `expires_at` column to all non-financial tables
- [/] Implement daily cleanup job to delete expired rows
- [/] Isolate “financial data tables” that are exempt from 28-day deletion
- [/] Document data-retention policy for compliance

---

### A2 — Start Consultation (Text-only flow)
- [/] Build `GET /clarifications/new` form
- [/] Add tone selector (Calm / Firm / Professional)
- [/] Validation rules for text inputs
- [/] Save consultation + details
- [/] Create “stub AI output” generator (temporary)
- [/] Store completed result text
- [/] Redirect to `/clarifications/view?id=...`

---

### A3 — Consultation Result Page
- [ ] Render final result with full Plainfully styling
- [ ] Add subtle plan-based upsell banner
- [ ] Add “Start another consultation” CTA
- [ ] NEVER show user’s original input text
- [ ] Prevent deletion of real consultations (except 28-day auto-cleanup)
- [ ] Abandoned consultations: destroy instantly + invisible to user

---

### A4 — Dashboard Completion
- [ ] Add Plainfully logo next to welcome message
- [ ] Create Bronze / Silver / Gold badges (Basic / Pro / Unlimited)
- [ ] Display badges near plan box
- [ ] Integrate recent consultations from DB
- [ ] Finalise responsive layout + spacing fixes

---

## B. OCR + Multi-Image Upload

### B1 — Multi-image Upload Page
- [ ] Support uploading 1–10 images
- [ ] Temporary storage for unvalidated uploads
- [ ] Auto-delete after 28 days

### B2 — Quality Check
- [ ] Use OpenAI Vision for clarity scoring
- [ ] If poor quality → show: “Your photos are difficult to read. Continue?”
  - [ ] Retake → open guidance page
  - [ ] Continue → user accepts risk

### B3 — OCR Pipeline
- [ ] OCR each page in order
- [ ] Merge text + inject page markers
- [ ] Feed text directly into consultation creation pipeline
- [ ] Abandoned uploads → delete all data immediately
- [ ] Failed attempts → redirect to diagnostic ticket flow

---

## C. Failure Pipeline + Diagnostic Tickets

### C1 — Failure Capture
- [ ] On processing failure ask: “Send this to Plainfully Support?”
- [ ] If yes:
  - [ ] Create ticket in `diagnostic_reports`
  - [ ] Store original OCR text + uploads only for ticket analysis
  - [ ] Auto-delete after 28 days
  - [ ] Email admin alert

### C2 — Admin Tools
- [ ] Admin magic-link login
- [ ] “Diagnostic Tickets (max 28 days)” view
- [ ] Protected view showing diagnostics + metadata

---

## D. Billing System (Stripe)

### D1 — Account Plans
- [ ] Basic / Pro / Unlimited plan definitions
- [ ] Monthly quota logic (if any)
- [ ] Stripe price IDs stored in config

### D2 — Subscribe / Upgrade / Downgrade
- [ ] Connect user ↔ Stripe Customer
- [ ] Stripe Checkout for upgrades
- [ ] Webhooks:
  - [ ] `checkout.session.completed`
  - [ ] `customer.subscription.updated`
  - [ ] `customer.subscription.deleted`
- [ ] Update user plan automatically

### D3 — Billing Portal
- [ ] Add link for updating payment details / cancelling
- [ ] Protect endpoint with login

---

## E. Account & Session Layer

### E1 — Magic Link Auth
- [ ] Rate limiting per email + IP
- [ ] Expiring tokens
- [ ] Hardened session cookies
- [ ] Session fingerprinting (your anti-hijack logic)

### E2 — Profile Page
- [ ] Show plan level
- [ ] Show Stripe “Manage Billing”
- [ ] Basic profile edits (maybe name only)

---

## F. Data Retention Enforcement

### F1 — 28-Day Deletion Engine
- [ ] CRON-safe script
- [ ] Deletes:
  - consultations (all non-financial)
  - OCR uploads
  - diagnostic tickets
  - any scratch/temporary tables
- [ ] Logging of deletions (internal only)
- [ ] Skip users + Stripe financial metadata

### F2 — Obfuscation & Secure Storage
- [ ] Encrypt consultation_details
- [ ] Avoid storing PII where not required
- [ ] Salted hashing for any sensitive fields that aren’t encrypted
- [ ] Ensure table relationships don’t expose meaning via names

---

## G. UX & Polish

### G1 — Logo & Branding
- [ ] Inline logo on dashboard
- [ ] Plan-level badge colours
- [ ] Branding rules in README

### G2 — Help & Guidance
- [ ] “How to take a good photo” help page
- [ ] FAQ / About Plainfully
- [ ] Contact support disclaimer

### G3 — Error Pages
- [ ] Nice 404 page
- [ ] Nice 500 page
- [ ] “Something went wrong with your upload” flow

---

## H. Launch Prep

### H1 — Deployment Finalisation
- [ ] Plesk production configuration
- [ ] Cloudflare cache rules
- [ ] Cloudflare image resizing (optional)
- [ ] Email provider (magic link + admin alerts)

### H2 — Sandbox → Live Stripe Transition
- [ ] Store live keys in Plesk vault
- [ ] Update plan IDs
- [ ] Final test cycle

### H3 — “Internal Tester Launch”
- [ ] Create “Tester onboarding” page
- [ ] Add anonymous feedback form
- [ ] Monitor logs for first 72h

---


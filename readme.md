# Plainfully

**Plainfully** is a calm, reliable clarification service designed to reduce confusion and anxiety by providing clear explanations of messages, letters, and emails.

This document reflects the **current locked MVP state**. Anything not listed as supported is intentionally out of scope for now.

---

## MVP STATUS (LOCKED)

**Internal version:** `text-core-v1`  
**Stage:** End-to-end functional (email → queue → AI → email + web result)

This MVP focuses on *clarity-first text analysis* with strong safety, privacy, and predictable behaviour.

---

## What Works (End-to-End)

### Input Channels
- ✅ Email ingestion (e.g. `hello@plainfully.com`)
- ✅ Website text submission

### Processing
- ✅ Text normalization and safety checks
- ✅ Single queue, single worker model
- ✅ AI analysis via `CheckEngine`
- ✅ Automatic user creation by email (free plan)

### Output
- ✅ Immediate acknowledgement email (receipt confirmation)
- ✅ Result email with secure “View full details” link
- ✅ Web-based result page (requires token + email verification)
- ✅ Dashboard view for logged-in users

### Security & Access
- ✅ Result-scoped tokens (HMAC hashed, no plaintext)
- ✅ Token rules:
  - 24 hours to validate (email confirmation)
  - Once validated, valid for 30 minutes
- ✅ Adaptive error handling using a single error view
- ✅ Works whether user is logged in or not

### UX & Accessibility
- ✅ Calm, neutral language
- ✅ WCAG-aware colour system
- ✅ Scales correctly up to 400% zoom
- ✅ Dark / light mode via CSS tokens
- ✅ Reduced-motion respected

---

## Explicitly NOT Supported (By Design)

These are intentionally excluded from MVP:

- ❌ File attachments (PDF, images, HEIC, DOCX, etc)
- ❌ OCR (image or scanned documents)
- ❌ ScamCheck as a separate mode
- ❌ Multiple input items per request
- ❌ Real-time queue positions or ETAs
- ❌ File storage or long-term raw input retention

If an unsupported feature is detected:
- The request **fails fast**
- The user is **not charged**
- A clear, calm explanation is sent

---

## Email Attachments (Current Behaviour)

- Any inbound email **with attachments** is rejected
- The email is acknowledged with:
  - Explanation that attachments are not yet supported
  - Assurance nothing was processed
- The message is **not queued**
- No analysis or billing occurs

This prevents:
- Malware risk
- Unexpected OCR costs
- Ambiguous parsing
- Privacy leakage

---

## Architecture (MVP)

### High-level flow

```
Input (Email / Web)
        ↓
   Inbound Queue
        ↓
   Single Worker
        ↓
  CheckEngine
        ↓
 Email Result + Web View
```

### Design Principles
- Many inputs → one queue → one engine → many outputs
- Fail-closed on security
- Fail-open on AI (graceful degradation)
- Deterministic behaviour over cleverness

---

## Privacy & Data Handling

- No plaintext email addresses stored in tokens
- Tokens and emails are hashed (HMAC + pepper)
- Raw input is minimized
- Attachments are not stored
- Results are scoped per user
- No third-party sharing

---

## Known Limitations

- Text-only analysis
- English-first
- No OCR
- No attachment handling
- No prioritization or batching
- Basic subscription logic only

These are **acceptable limitations** for MVP.

---

## Next Milestones

### N3 — Attachment Ingestion (Planned)
- PDF text extraction
- Image OCR (cost-aware)
- Size limits (e.g. 10MB)
- Malware scanning
- Clear failure reasons

### N4 — Confidence & Source Signals
- Confidence indicators
- Clearer “what this means / what to do next”
- Improved explanations

### N5 — Performance & Scaling
- Multiple workers
- Back-pressure handling
- Cost controls

---

## Development Notes

- PHP 8.x
- No framework dependency
- Token-based auth flows
- CSS token system:
  - `base.css` (accessibility + scaling)
  - `theme.css` (single source of truth)
  - `components/*.css`

---

## Guiding Principle

> **Calm. Clear. Contained.**  
> Confusion stays inside the boundary.  
> Clarity comes out.

---

© Plainfully / Hissing Goat Studios
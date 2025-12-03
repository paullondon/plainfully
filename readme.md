# 🐐 Plainfully  
### Clear answers, anywhere — delivered safely, simply, and fast.

Plainfully is a clarity platform built to help people understand scam messages, confusing emails, suspicious texts, and difficult documents using a clean, safe, and privacy-first AI engine.  
The system unifies **multiple input channels** (Web, Email, SMS, Messenger, WhatsApp) into **one structured Check Engine** that returns clear, plain-English guidance.

This README describes the purpose, architecture, data flow, and key features of the Plainfully system.

---

# 🌟 What Plainfully Does

Plainfully allows users to send in:
- Scam text messages  
- Suspicious emails  
- Letters or documents (via image upload + OCR)  
- Screenshots  
- General clarification queries  
- Messenger / WhatsApp forwarded messages  
- Premium SMS scam-check requests

Every input type is **normalised**, fed into the **Check Engine**, processed through the Plainfully AI prompt chain, then returned to the user with a clear, trustworthy explanation.

The user can access full results through their dashboard and upgrade to unlimited usage for £4.99/month.

---

# 🎯 Core Principles

### ✔ One engine, many entry points  
No matter where the user sends content from, everything is processed by the same Check Engine.

### ✔ Safety and privacy first  
- No sensitive data stored longer than 28 days  
- Minimal PII stored  
- AI receives only cleaned, screened content  
- All temporary data auto-purges

### ✔ Simplicity for users  
- No app to install  
- Magic-link login  
- SMS service requires **no login**  
- Email forwarding works instantly  
- Messenger/WhatsApp replies are frictionless

### ✔ Controlled, stable releases  
Plainfully is built in structured “Release Packages” to maintain stability and predictable delivery.

---

# 🔧 Architecture Overview

## 1. Ingestion Channels (Front Door)
Plainfully accepts content through:

### **Web**
OCR uploads, text submissions, screenshots  

### **Email**
- `scamcheck@plainfully.com` → scam detection  
- `clarify@plainfully.com` → general clarifications  

### **Messenger**
Forwarded scam messages and suspicious DMs  

### **WhatsApp**
Direct forwarding of scam content  

### **SMS (TextCheck)**
- Trial long-code (cheap) for development  
- Premium rate shortcode (£1 per check) for production  
  - No login required  
  - Instant reply  
  - Bypasses quotas

All channels convert inbound messages into a shared internal format:

```php
{
  channel: "email" | "web" | "messenger" | "whatsapp" | "sms_premium" | ...,
  source_identifier: email/phone/platform ID,
  raw_content: "text to analyse",
  metadata: {...}
}

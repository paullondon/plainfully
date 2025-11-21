# Plainfully

Plainfully is an AI-powered clarification platform built for users who need simple, accurate help in plain English.  
Requests are processed through a smart queue system that protects operating costs while ensuring paid users always receive real-time results.

## Key Features
- **Clarification Engine** — transforms unclear questions into structured, easy-to-understand explanations.
- **Queue Protection Layer**  
  - Free requests queue when demand is high  
  - Paid requests are processed instantly  
  - Automatic caps prevent API overspend  
- **Cost-Safe Architecture** — designed to avoid runaway API usage.
- **Zero-Friction UX** — simple, mobile-first interface inspired by Apple Store clarity.
- **Login Optional** — anonymous users can submit instantly; accounts enable history + preferences.

## Tech Stack
- PHP backend (modular `/app` architecture)
- MariaDB database
- Cloudflare (R2 + Networking)
- OpenAI API (primary model)
- Stripe (if paid tiers added later)
- GitHub-driven CI/CD

## Project Goals
- Deliver a clean, intuitive user experience  
- Keep latency low and platform costs predictable  
- Provide a scalable foundation for future expansions

## Local Development
```bash
composer install
cp .env.example .env
php -S localhost:8000 -t public

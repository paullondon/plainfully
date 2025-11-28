# Plainfully – Consultation Data Retention

## Scope

This policy covers **non-financial** operational data, including:

- `consultations`
- `consultation_details`
- `consultation_uploads`
- `magic_login_tokens`
- `auth_events`
- Other short-lived operational tables with an `expires_at` column.

Financial tables such as `payments`, `payment_intents`, `invoices`,
`subscriptions`, and `payouts` are explicitly **excluded** and are retained
according to legal and accounting requirements.

## Retention

- Consultation-related data uses a standard window of **28 days** from creation
  (stored explicitly as `expires_at` on each row).
- Auth logs (`auth_events`) and login tokens (`magic_login_tokens`) use shorter
  or longer retention based on security and operational requirements, also
  expressed via `expires_at`.

## Deletion

- A backend cleanup job runs daily and deletes rows where `expires_at <= NOW()`
  in configured non-financial tables.
- This process is irreversible; after deletion, consultation content cannot be
  restored.

# Plainfully Security Model

## Core Rules

- All user input is untrusted
- No executable content is ever evaluated
- Attachments are processed offline
- Tokens are scoped, expiring, and single-purpose

## Result Access

- Result links are scoped to a single clarification
- Email confirmation is required
- Tokens expire automatically
- Logged-in state does not bypass validation

## Data Handling

- No plaintext sensitive data stored unnecessarily
- Attachments are deleted after processing
- Logs never contain raw user content

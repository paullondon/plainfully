# Plainfully — Magic Link Auth Skeleton

Quick start:

1. Create a new MariaDB / MySQL database.
2. Update `config/app.php`:
   - `base_url`
   - DB `dsn`, `user`, `pass`
   - `mail.from_email`, `mail.from_name`
3. Run the SQL in `sql/0000_auth_magic_link.sql` against your database.
4. Point your web server docroot to the `public/` folder.
5. Open `/login.php` in a browser and request a magic link.

This skeleton uses:
- Single shared CSS: `public/assets/css/app.css`
- Magic-link login via email (phone field is stored but unused for login for now).

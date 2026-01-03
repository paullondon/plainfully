ALTER TABLE users
  ADD COLUMN is_admin TINYINT(1) NOT NULL DEFAULT 0;

CREATE INDEX idx_users_is_admin ON users (is_admin);

UPDATE users
SET is_admin = 1
WHERE email = 'Paul.london@me.com'
LIMIT 1;

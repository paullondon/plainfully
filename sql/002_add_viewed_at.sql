ALTER TABLE pf_outbound_queue
  ADD COLUMN viewed_at DATETIME NULL DEFAULT NULL AFTER status;

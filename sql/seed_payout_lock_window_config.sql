INSERT INTO atem_config (setting_key, setting_value) VALUES
    ('payout_lock_window_days_q1', '10'),
    ('payout_lock_window_days_q2', '10'),
    ('payout_lock_window_days_q3', '10'),
    ('payout_lock_window_days_q4', '10')
ON DUPLICATE KEY UPDATE setting_value = setting_value;

-- Leftover from before the window was split per-quarter - no longer read by
-- any code (superseded by the _q1.._q4 keys above). Safe to remove.
DELETE FROM atem_config WHERE setting_key = 'payout_lock_window_days';

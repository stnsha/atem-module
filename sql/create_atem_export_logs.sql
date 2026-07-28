CREATE TABLE IF NOT EXISTS atem_export_logs (
  id INT NOT NULL AUTO_INCREMENT,
  target_staff_id INT NOT NULL,
  actor_staff_id INT NOT NULL,
  export_type VARCHAR(32) NOT NULL,
  exported_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_target_staff (target_staff_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

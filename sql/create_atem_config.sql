CREATE TABLE IF NOT EXISTS atem_config (
    setting_key   VARCHAR(64)  NOT NULL,
    setting_value VARCHAR(255) NOT NULL DEFAULT '',
    PRIMARY KEY (setting_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO atem_config (setting_key, setting_value)
VALUES ('struct_window_override', '0');

INSERT IGNORE INTO atem_config (setting_key, setting_value)
VALUES ('backdate_enabled', '0');
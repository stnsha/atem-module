ALTER TABLE staff
    ADD COLUMN atem TINYINT(1) NOT NULL DEFAULT 0 COMMENT '0 = normal user, 1 = superadmin' AFTER coin_hub;

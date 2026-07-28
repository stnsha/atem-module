ALTER TABLE staff_grade
    ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1 COMMENT '0 = inactive, 1 = active' AFTER grade_name;

ALTER TABLE staff_struct
    ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1 COMMENT '0 = inactive, 1 = active' AFTER description;

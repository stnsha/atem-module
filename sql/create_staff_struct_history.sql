CREATE TABLE IF NOT EXISTS staff_struct_history (
    id          INT(11)      NOT NULL AUTO_INCREMENT,
    staff_id    INT(11)      NOT NULL,
    struct      INT(11)      NOT NULL DEFAULT 0,
    year        SMALLINT(4)  NOT NULL,
    quarter     TINYINT(1)   NOT NULL COMMENT '1 = Q1, 2 = Q2, 3 = Q3, 4 = Q4',
    PRIMARY KEY (id),
    KEY idx_staff_year_quarter (staff_id, year, quarter)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- atem_access_control.sql
-- Adds staff grade and performance structure columns to the staff table.
-- Creates lookup tables for grade and struct values.
-- Run once against the odb database.

-- Staff grade level
ALTER TABLE `staff`
  ADD COLUMN `grade` tinyint(1) NOT NULL DEFAULT '0'
  AFTER `department`;

-- Staff performance structure
ALTER TABLE `staff`
  ADD COLUMN `struct` tinyint(1) NOT NULL DEFAULT '0'
  AFTER `grade`;

-- Grade lookup table
CREATE TABLE `staff_grade_library` (
  `id`    tinyint(1)   NOT NULL,
  `label` varchar(100) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=MyISAM DEFAULT CHARSET=latin1;

INSERT INTO `staff_grade_library` (`id`, `label`) VALUES
(0, 'Non-Graded'),
(1, 'Frontline / Operational Staff'),
(2, 'Middle management'),
(3, 'Senior management'),
(4, 'C suite executive'),
(5, 'CEO/board'),
(6, 'SuperAdmin');

-- Performance structure lookup table
CREATE TABLE `staff_struct_library` (
  `id`    tinyint(1)   NOT NULL,
  `label` varchar(100) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=MyISAM DEFAULT CHARSET=latin1;

INSERT INTO `staff_struct_library` (`id`, `label`) VALUES
(0, 'No structure'),
(1, 'Flexi KPI'),
(2, 'KPI Only'),
(3, 'Baodi KPI + 2 ATEM'),
(4, 'ATEM Only');
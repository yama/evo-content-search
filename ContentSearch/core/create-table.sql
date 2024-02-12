CREATE TABLE `[+prefix+]search_content` (
  `id` int(10) NOT NULL AUTO_INCREMENT,
  `doc_id` int(10) NOT NULL,
  `pagetitle` varchar(512) NOT NULL DEFAULT '',
  `plain_text` mediumtext,
  `tokens` mediumtext,
  `publishedon` int(20) NOT NULL DEFAULT '0',
  `editedon` int(20) NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `publishedon` (`publishedon`),
  FULLTEXT KEY `tokens` (`tokens`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Contains the search content.';

<?php

declare(strict_types=1);

namespace Neos\Flow\Persistence\Doctrine\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20210607114613 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Initial migration of the wwwision/node-event-log package';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf($this->connection->getDatabasePlatform()->getName() !== 'mysql', 'Migration can only be executed safely on \'mysql\'.');

        $this->addSql('CREATE TABLE `wwwision_nodeeventlog_projection_event` (
          `id` int(11) NOT NULL AUTO_INCREMENT,
          `nodeaggregateidentifier` varchar(255) NOT NULL DEFAULT "",
          `contentstreamidentifier` varchar(255) NOT NULL DEFAULT "",
          `dimensionspacepointhash` varchar(255) NOT NULL DEFAULT "",
          `eventid` varchar(255) CHARACTER SET utf8 COLLATE utf8_unicode_ci NOT NULL DEFAULT "",
          `eventtype` varchar(255) NOT NULL DEFAULT "",
          `payload` text NOT NULL,
          `initiatinguserid` varchar(255) NOT NULL DEFAULT "",
          `recordedat` datetime NOT NULL COMMENT "(DC2Type:datetime_immutable)",
          `inherited` tinyint(1) unsigned NOT NULL DEFAULT 0,
          PRIMARY KEY (`id`),
          KEY `variant` (`nodeaggregateidentifier`,`dimensionspacepointhash`),
          KEY `nodeaggregateidentifier` (`nodeaggregateidentifier`),
          KEY `initiatinguserid` (`initiatinguserid`),
          KEY `contentstreamidentifier` (`contentstreamidentifier`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

        $this->addSql('CREATE TABLE `wwwision_nodeeventlog_projection_hierarchy` (
          `nodeaggregateidentifier` varchar(255) NOT NULL DEFAULT "",
          `contentstreamidentifier` varchar(255) NOT NULL DEFAULT "",
          `dimensionspacepointhash` varchar(255) NOT NULL DEFAULT "",
          `nodeaggregateidentifierpath` varchar(4000) NOT NULL DEFAULT "",
          `origindimensionspacepointhash` varchar(255) NOT NULL DEFAULT "",
          `parentnodeaggregateidentifier` varchar(255) DEFAULT "",
          `disabled` tinyint(4) unsigned NOT NULL DEFAULT 0,
          UNIQUE KEY `variant` (`nodeaggregateidentifier`,`dimensionspacepointhash`),
          KEY `parentnodeaggregateidentifier` (`parentnodeaggregateidentifier`),
          KEY `nodeaggregateidentifierpath` (`nodeaggregateidentifierpath`(768)) KEY_BLOCK_SIZE=100
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf($this->connection->getDatabasePlatform()->getName() !== 'mysql', 'Migration can only be executed safely on \'mysql\'.');

        $this->addSql('DROP TABLE `wwwision_nodeeventlog_projection_event`');
        $this->addSql('DROP TABLE `wwwision_nodeeventlog_projection_hierarchy`');
    }
}

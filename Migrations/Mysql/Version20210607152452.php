<?php
declare(strict_types=1);

namespace Neos\Flow\Persistence\Doctrine\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20210607152452 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add table wwwision_nodeeventlog_projection_workspace';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf($this->connection->getDatabasePlatform()->getName() !== 'mysql', 'Migration can only be executed safely on \'mysql\'.');

        $this->addSql('CREATE TABLE `wwwision_nodeeventlog_projection_workspace` (
          `workspacename` varchar(255) NOT NULL DEFAULT "",
          `contentstreamidentifier` varchar(255) NOT NULL DEFAULT "",
          UNIQUE KEY `workspacename` (`workspacename`),
          UNIQUE KEY `contentstreamidentifier` (`contentstreamidentifier`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf($this->connection->getDatabasePlatform()->getName() !== 'mysql', 'Migration can only be executed safely on \'mysql\'.');

        $this->addSql('DROP TABLE `wwwision_nodeeventlog_projection_workspace`');
    }
}

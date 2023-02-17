<?php
declare(strict_types=1);

namespace Neos\Flow\Persistence\Doctrine\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20230214181928 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add column baseworkspacename to table wwwision_nodeeventlog_projection_workspace';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf($this->connection->getDatabasePlatform()->getName() !== 'mysql', 'Migration can only be executed safely on \'mysql\'.');

        $this->addSql('ALTER TABLE `wwwision_nodeeventlog_projection_workspace` ADD baseworkspacename VARCHAR(255) DEFAULT NULL;');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf($this->connection->getDatabasePlatform()->getName() !== 'mysql', 'Migration can only be executed safely on \'mysql\'.');

        $this->addSql('ALTER TABLE `wwwision_nodeeventlog_projection_workspace` DROP baseworkspacename');
    }
}

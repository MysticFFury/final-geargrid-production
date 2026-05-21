<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Allow user deletion: set nullable user FKs to NULL instead of blocking deletes.
 */
final class Version20260522120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Set ON DELETE SET NULL for user foreign keys (orders, products, categories, stock movements)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE `order` DROP FOREIGN KEY FK_F529939828753A59');
        $this->addSql('ALTER TABLE `order` ADD CONSTRAINT FK_F529939828753A59 FOREIGN KEY (placed_by_id) REFERENCES user (id) ON DELETE SET NULL');

        $this->addSql('ALTER TABLE `order` DROP FOREIGN KEY FK_F5299398B03A8386');
        $this->addSql('ALTER TABLE `order` ADD CONSTRAINT FK_F5299398B03A8386 FOREIGN KEY (created_by_id) REFERENCES user (id) ON DELETE SET NULL');

        $this->addSql('ALTER TABLE product DROP FOREIGN KEY FK_D34A04ADB03A8386');
        $this->addSql('ALTER TABLE product ADD CONSTRAINT FK_D34A04ADB03A8386 FOREIGN KEY (created_by_id) REFERENCES user (id) ON DELETE SET NULL');

        $this->addSql('ALTER TABLE category DROP FOREIGN KEY FK_64C19C1B03A8386');
        $this->addSql('ALTER TABLE category ADD CONSTRAINT FK_64C19C1B03A8386 FOREIGN KEY (created_by_id) REFERENCES user (id) ON DELETE SET NULL');

        $this->addSql('ALTER TABLE stock_movement DROP FOREIGN KEY FK_BB1BC1B5B03A8386');
        $this->addSql('ALTER TABLE stock_movement CHANGE created_by_id created_by_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE stock_movement ADD CONSTRAINT FK_BB1BC1B5B03A8386 FOREIGN KEY (created_by_id) REFERENCES user (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE `order` DROP FOREIGN KEY FK_F529939828753A59');
        $this->addSql('ALTER TABLE `order` ADD CONSTRAINT FK_F529939828753A59 FOREIGN KEY (placed_by_id) REFERENCES user (id)');

        $this->addSql('ALTER TABLE `order` DROP FOREIGN KEY FK_F5299398B03A8386');
        $this->addSql('ALTER TABLE `order` ADD CONSTRAINT FK_F5299398B03A8386 FOREIGN KEY (created_by_id) REFERENCES user (id)');

        $this->addSql('ALTER TABLE product DROP FOREIGN KEY FK_D34A04ADB03A8386');
        $this->addSql('ALTER TABLE product ADD CONSTRAINT FK_D34A04ADB03A8386 FOREIGN KEY (created_by_id) REFERENCES user (id)');

        $this->addSql('ALTER TABLE category DROP FOREIGN KEY FK_64C19C1B03A8386');
        $this->addSql('ALTER TABLE category ADD CONSTRAINT FK_64C19C1B03A8386 FOREIGN KEY (created_by_id) REFERENCES user (id)');

        $this->addSql('ALTER TABLE stock_movement DROP FOREIGN KEY FK_BB1BC1B5B03A8386');
        $this->addSql('ALTER TABLE stock_movement CHANGE created_by_id created_by_id INT NOT NULL');
        $this->addSql('ALTER TABLE stock_movement ADD CONSTRAINT FK_BB1BC1B5B03A8386 FOREIGN KEY (created_by_id) REFERENCES user (id)');
    }
}

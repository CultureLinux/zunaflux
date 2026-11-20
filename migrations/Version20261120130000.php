<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261120130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adds per-feed collection-order index and covering favorite/saved indexes.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE article ADD INDEX idx_article_feed_collected (feed_id, collected_at, id), ALGORITHM=INPLACE, LOCK=NONE');
        $this->addSql('ALTER TABLE article_state ADD INDEX idx_article_state_user_favorite_article (user_id, is_favorite, article_id), ADD INDEX idx_article_state_user_saved_article (user_id, is_saved, article_id), DROP INDEX idx_article_state_user_favorite, DROP INDEX idx_article_state_user_saved, ALGORITHM=INPLACE, LOCK=NONE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE article_state ADD INDEX idx_article_state_user_favorite (user_id, is_favorite), ADD INDEX idx_article_state_user_saved (user_id, is_saved), DROP INDEX idx_article_state_user_favorite_article, DROP INDEX idx_article_state_user_saved_article, ALGORITHM=INPLACE, LOCK=NONE');
        $this->addSql('ALTER TABLE article DROP INDEX idx_article_feed_collected');
    }
}

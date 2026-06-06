<?php
namespace arjanbrinkman\craftappauthenticator\migrations;

use craft\db\Migration;

/**
 * Install migration for the AppAuth plugin.
 */
class Install extends Migration
{
	public function safeUp(): bool
	{
		// Only create table if it doesn't already exist
		if (!$this->db->tableExists('{{%appauthenticator_tokens}}')) {
			$this->createTable('{{%appauthenticator_tokens}}', [
				'id'        => $this->primaryKey(),
				'userId'    => $this->integer()->notNull(),
				'token'     => $this->string(255)->notNull()->unique(),
				'expiresAt' => $this->dateTime()->notNull(),
				'createdAt' => $this->dateTime()->notNull(),
			]);

			$this->addForeignKey(
				null,
				'{{%appauthenticator_tokens}}',
				'userId',
				'{{%users}}',
				'id',
				'CASCADE',
				'CASCADE'
			);
		}

		return true;
	}

	public function safeDown(): bool
	{
		// Drop table on uninstall
		$this->dropTableIfExists('{{%appauthenticator_tokens}}');
		return true;
	}
}

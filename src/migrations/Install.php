<?php

namespace simplygoodwork\craftdolly\migrations;

use craft\db\Migration;
use craft\db\Table;
use simplygoodwork\craftdolly\models\ImportLogEntry;
use simplygoodwork\craftdolly\records\ImportLogRecord;

/**
 * Creates the Import log table.
 */
class Install extends Migration
{
    public function safeUp(): bool
    {
        $this->createTable(ImportLogRecord::tableName(), [
            'id' => $this->primaryKey(),
            'userId' => $this->integer(),
            'payloadIdentity' => $this->char(64)->notNull(),
            'source' => $this->json(),
            'results' => $this->json(),
            'choices' => $this->json(),
            'status' => $this->string(20)->notNull()->defaultValue(ImportLogEntry::STATUS_COMPLETED),
            'error' => $this->text(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createIndex(null, ImportLogRecord::tableName(), ['payloadIdentity']);
        $this->addForeignKey(null, ImportLogRecord::tableName(), ['userId'], Table::USERS, ['id'], 'SET NULL');

        return true;
    }

    public function safeDown(): bool
    {
        $this->dropTableIfExists(ImportLogRecord::tableName());

        return true;
    }
}

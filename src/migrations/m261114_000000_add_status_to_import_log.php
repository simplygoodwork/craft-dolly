<?php

namespace simplygoodwork\craftportage\migrations;

use craft\db\Migration;
use simplygoodwork\craftportage\models\ImportLogEntry;
use simplygoodwork\craftportage\records\ImportLogRecord;

/**
 * Records the status of each Import in the Import log, for Imports that run in the queue. Imports already in the log
 * are completed.
 */
class m261114_000000_add_status_to_import_log extends Migration
{
    public function safeUp(): bool
    {
        $table = ImportLogRecord::tableName();
        if (!$this->db->columnExists($table, 'status')) {
            $this->addColumn($table, 'status', $this->string(20)->notNull()->defaultValue(ImportLogEntry::STATUS_COMPLETED)->after('choices'));
        }
        if (!$this->db->columnExists($table, 'error')) {
            $this->addColumn($table, 'error', $this->text()->after('status'));
        }

        return true;
    }

    public function safeDown(): bool
    {
        $this->dropColumn(ImportLogRecord::tableName(), 'error');
        $this->dropColumn(ImportLogRecord::tableName(), 'status');

        return true;
    }
}

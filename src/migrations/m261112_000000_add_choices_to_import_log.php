<?php

namespace simplygoodwork\craftdolly\migrations;

use craft\db\Migration;
use simplygoodwork\craftdolly\records\ImportLogRecord;

/**
 * Records the choice made for each Unresolved reference in the Import log.
 */
class m261112_000000_add_choices_to_import_log extends Migration
{
    public function safeUp(): bool
    {
        if (!$this->db->columnExists(ImportLogRecord::tableName(), 'choices')) {
            $this->addColumn(ImportLogRecord::tableName(), 'choices', $this->json()->after('results'));
        }

        return true;
    }

    public function safeDown(): bool
    {
        $this->dropColumn(ImportLogRecord::tableName(), 'choices');

        return true;
    }
}

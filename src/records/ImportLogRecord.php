<?php

namespace simplygoodwork\craftdolly\records;

use craft\db\ActiveRecord;

/**
 * One Import in the Import log.
 *
 * @property int $id
 * @property int|null $userId
 * @property string $payloadIdentity
 * @property array|string|null $source
 * @property array|string|null $results
 * @property array|string|null $choices
 * @property string $status
 * @property string|null $error
 * @property string $dateCreated
 */
class ImportLogRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return '{{%dolly_importlog}}';
    }
}

<?php

namespace simplygoodwork\craftportage\tests\support;

use craft\test\CraftConnector as BaseCraftConnector;
use yii\log\Logger;

/**
 * Craft's connector, closing the database connection once Craft has started, as Craft does itself from 5.2.7.
 *
 * See `CraftModule`.
 */
class CraftConnector extends BaseCraftConnector
{
    public function startApp(?Logger $logger = null): void
    {
        parent::startApp($logger);

        \Craft::$app->getDb()->close();
    }
}

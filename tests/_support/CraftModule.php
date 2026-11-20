<?php

namespace simplygoodwork\craftdolly\tests\support;

use Codeception\TestInterface;
use craft\test\Craft;

/**
 * Craft's test module, with Craft 5.2.7's fix for rolling back each test's transaction backported to older versions.
 *
 * The Yii2 module wraps each test in a transaction that it begins when the database connection opens. Before Craft
 * 5.2.7, the connection is already open by then, so no transaction begins, and every test's schema and content stay
 * in the database for the next test. From 5.2.7, Craft closes the connection after starting and opens it again once
 * the module is listening, as this does; on those versions, closing a closed connection and opening an open one does
 * nothing.
 */
class CraftModule extends Craft
{
    public function _before(TestInterface $test): void
    {
        parent::_before($test);

        \Craft::$app->getDb()->open();
    }

    /**
     * Craft's `recreateClient()`, with this suite's connector.
     */
    protected function recreateClient(): void
    {
        $entryUrl = $this->_getConfig('entryUrl');
        $entryFile = $this->_getConfig('entryScript') ?: basename($entryUrl);
        $entryScript = $this->_getConfig('entryScript') ?: parse_url($entryUrl, PHP_URL_PATH);

        $this->client = new CraftConnector([
            'SCRIPT_FILENAME' => $entryFile,
            'SCRIPT_NAME' => $entryScript,
            'SERVER_NAME' => parse_url($entryUrl, PHP_URL_HOST),
            'SERVER_PORT' => parse_url($entryUrl, PHP_URL_PORT) ?: '80',
            'HTTPS' => parse_url($entryUrl, PHP_URL_SCHEME) === 'https',
        ]);

        $this->configureClient($this->_getConfig());
    }
}

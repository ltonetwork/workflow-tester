<?php

namespace LegalThings\LiveContracts\Tester;

use Behat\Behat\Context\Context;
use Behat\Testwork\Suite\Exception as SuiteException;
use Behat\Testwork\Hook\Scope\BeforeSuiteScope;
use Behat\Testwork\Hook\Scope\HookScope;
use MongoDB;

/**
 * Manage the MongoDB by loading fixtures and cleaning up after each feature.
 */
class DBContext implements Context
{
    /**
     * @var MongoDB\Client
     */
    protected static $mongo;

    /**
     * @var string
     */
    protected static $fixturePath;


    /**
     * Create the MongoDB connection
     *
     * @param BeforeSuiteScope $scope
     *
     * @BeforeSuite
     */
    public static function connect(BeforeSuiteScope $scope)
    {
        if (!$scope->getSuite()->hasSetting('db')) {
            return;
        }

        $settings = $scope->getSuite()->getSetting('db');

        self::$mongo = new MongoDB\Client($settings['dsn'] ?? 'mongodb://localhost');
    }

    /**
     * Delete all database
     *
     * @param HookScope $scope
     *
     * @BeforeFeature
     * @ AfterScenario
     */
    public static function dropDatabases(HookScope $scope)
    {
        $settings = $scope->getSuite()->getSetting('db');

        if (!isset($settings['databases'])) {
            return;
        }

        foreach ($settings['databases'] as $database) {
            self::$mongo->dropDatabase($database);
        }
    }
}

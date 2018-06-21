<?php

namespace LegalThings\LiveContracts\Tester;

use Behat\Behat\Context\Context;
use Behat\Testwork\Hook\Scope\HookScope;
use Behat\Testwork\Hook\Scope\BeforeSuiteScope;
use Behat\Behat\Hook\Scope\AfterFeatureScope;
use Behat\Behat\Hook\Scope\BeforeScenarioScope;
use Behat\Behat\Hook\Scope\AfterScenarioScope;
use MongoDB;
use PHPUnit\Framework\SkippedTestError;

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
     * @param BeforeScenarioScope $scope
     *
     * @BeforeScenario
     */
    public function backupDatabase(BeforeScenarioScope $scope)
    {
        $settings = $scope->getSuite()->getSetting('db');

        if (!isset($settings['databases'])) {
            return;
        }

        $databases = iterator_to_array(self::$mongo->listDatabases());
        $existing = array_intersect($settings['databases'], array_map(function($db) {
            return $db->getName();
        }, $databases));

        if (!empty($existing)) {
            throw new SkippedTestError("Skipped scenario; database is dirty: " . join(', ' , $existing));
        }

        foreach ($settings['databases'] as $database) {
            if (in_array("{$database}_before", $databases)) {
                continue;
            }

            self::$mongo->admin->command([
                'copydb' => 1,
                'fromhost' => 'localhost',
                'fromdb' => $database,
                'todb' => "{$database}_before"
            ]);
        }
    }

    /**
     * Delete all database
     *
     * @param AfterScenarioScope $scope
     *
     * @AfterScenario
     */
    public function restoreDatabase(AfterScenarioScope $scope)
    {
        $settings = $scope->getSuite()->getSetting('db');

        if (!isset($settings['databases']) || (!$scope->getTestResult()->isPassed() && !empty($settings['dirty']))) {
            return;
        }

        foreach ($settings['databases'] as $database) {
            self::$mongo->dropDatabase($database);

            self::$mongo->admin->command([
                'copydb' => 1,
                'fromhost' => 'localhost',
                'fromdb' => "{$database}_before",
                'todb' => $database
            ]);
        }
    }

    /**
     * Delete all database
     *
     * @param HookScope $scope
     *
     * @BeforeSuite
     * @BeforeFeature
     * @AfterFeature
     */
    public static function dropDatabases(HookScope $scope)
    {
        $settings = $scope->getSuite()->getSetting('db');

        if (!isset($settings['databases'])) {
            return;
        }

        foreach ($settings['databases'] as $database) {
            self::$mongo->dropDatabase("{$database}_before");
        }

        if (
            !($scope instanceof BeforeSuiteScope) &&
            !($scope instanceof AfterFeatureScope && $scope->getTestResult()->isPassed()) &&
            !empty($settings['dirty'])
        ) {
            return;
        }

        foreach ($settings['databases'] as $database) {
            self::$mongo->dropDatabase($database);
        }
    }
}

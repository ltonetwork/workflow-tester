<?php

namespace LTO\LiveContracts\Tester;

use Behat\Behat\Context\Context;
use Behat\Testwork\Hook\Scope\HookScope;
use Behat\Testwork\Hook\Scope\BeforeSuiteScope;
use Behat\Behat\Hook\Scope\AfterFeatureScope;
use Behat\Behat\Hook\Scope\BeforeScenarioScope;
use Behat\Behat\Hook\Scope\AfterScenarioScope;
use MongoDB;
use PHPUnit\Framework\SkippedTestError;
use Behat\Gherkin\Node\TableNode;
use MongoDB\Model\BSONDocument;

/**
 * Manage the MongoDB by loading fixtures and cleaning up after each feature.
 */
class DBContext implements Context
{
    use BehatInputConversion;

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

    /**
     * @Then a :method request has been send to :url with:
     *
     * @throws PHPUnit\Framework\AssertionFailedError
     * @param string $method
     * @param string $url
     * @param TableNode $table
     */
    public function httpRequestIsPresent(string $method, string $url, TableNode $table)
    {
        $data = $this->obtainLastFlowHttpRequest();
        if (!isset($data)) {
            Assert::fail("No http request found");
        }

        Assert::assertSame($method, $data['request']['method']);
        Assert::assertSame($url, $data['request']['url']);

        $expected = $this->tableToPairs($table);
        $body = $data['request']['body'];

        foreach ($expected as $key => $value) {
            Assert::assertTrue(isset($body[$key]));
            Assert::assertSame($value, $body[$key]);
        }

        $this->last_http_request = $data;
    }

    /**
     * @Then that request received a :status response with:
     *
     * @param string $status
     * @param TableNode $table 
     */
    public function checkHttpJsonResponse(string $status, TableNode $table)
    {
        if (!isset($this->last_http_request)) {
            Assert::fail("No http request found");
        }

        $expected = $this->tableToPairs($table);
        $response = $this->last_http_request['response'];
        $body = $response['body'];

        Assert::assertSame($status, $response['status']);        
        Assert::assertTrue(is_array($body), "Invalid response body type: " . gettype($body));

        foreach ($expected as $key => $value) {
            Assert::assertTrue(isset($body[$key]));
            Assert::assertSame($value, $body[$key]);
        }
    }

    /**
     * Obtain last captured workflow http request
     *
     * @return array
     */
    protected function obtainLastFlowHttpRequest()
    {
        $mongo = self::$mongo;
        $database = 'lto_workflow';

        $db = $mongo->selectDatabase($database);
        $options = [
            'sort' => ['_id' => -1],
            'limit' => 1
        ];

        $result = $db->http_request_logs->find([], $options)->toArray();

        return isset($result[0]) ? $this->formatRequest($result[0]) : null;
    }

    /**
     * Format request captured by workflow-engine
     *
     * @param MongoDB\Model\BSONDocument $data
     * @return array
     */
    protected function formatRequest(BSONDocument $data)
    {
        $request = $data->request;
        $response = $data->response;

        return [
            'request' => [
                'url' => $request->uri,
                'method' => $request->method,
                'body' => $request->body instanceof BSONDocument ?
                    iterator_to_array($request->body) : 
                    $request->body
            ],
            'response' => [
                'status' => (string)$response->status,
                'body' => $response->body instanceof BSONDocument ?
                    iterator_to_array($response->body) : 
                    $response->body
            ]
        ];
    }
}

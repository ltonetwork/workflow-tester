<?php

namespace LTO\LiveContracts\Tester;

use Behat\Behat\Context\Context;
use Behat\Behat\Hook\Scope\BeforeScenarioScope;
use Behat\Gherkin\Node\PyStringNode;
use Behat\Gherkin\Node\TableNode;
use GuzzleHttp\ClientInterface as HttpClient;
use LTO\Account;
use LTO\Event;

/**
 * Defines application features from the specific context.
 */
class ProcessContext implements Context
{
    use BehatInputConversion;

    /**
     * @var EventChainContext
     */
    protected $chainContext;

    /**
     * @var HttpClient
     */
    protected $httpClient;

    /**
     * @var string
     */
    protected $basePath;

    /**
     * @var Process[]
     */
    protected $processes;

    /**
     * Get related contexts.
     * @BeforeScenario
     */
    public function gatherContexts(BeforeScenarioScope $scope)
    {
        $environment = $scope->getEnvironment();

        $this->chainContext = $environment->getContext(EventChainContext::class);
        $this->httpClient = $environment->getContext(HttpContext::class)->getClient();
    }

    /**
     * Determine the base path.
     * @BeforeScenario
     */
    public function determineBasePath(BeforeScenarioScope $scope)
    {
        $environment = $scope->getEnvironment();

        $paths = $environment->getSuite()->getSetting('paths');
        $curPath = realpath($paths[0]);
        $this->basePath = preg_replace('~/features$~', '', $curPath);
    }

    /**
     * Get a process by reference
     *
     * @param string $ref
     * @return Process
     */
    public function getProcess(string $ref): Process
    {
        if (!isset($this->processes[$ref])) {
            $chain = $this->chainContext->getChain();
            $this->processes[$ref] = new Process($chain, $ref);
        }

        return $this->processes[$ref];
    }

    /**
     * Get the projection of a process
     *
     * @param Process       $process
     * @param Account|null $account
     * @return array
     */
    public function getProjection(Process $process, ?Account $account = null): array
    {
        $projection = $process->getProjection();
        $account = $account ?? $this->chainContext->getCreator();

        if (!isset($projection)) {
            $response = $this->httpClient->request(
                'GET',
                'processes/' . $process->id,
                ['account' => $account, 'headers' => ['Accept' => 'application/json;view=complete']]
            );

            $projection = json_decode($response->getBody(), true);
            $process->setProjection($projection);
        }

        return $projection;
    }

    /**
     * Add the process to the event chain
     *
     * @param Process $process
     */
    protected function addProcessToChain(Process $process): void
    {
        $account = $process->getCreator() ?? $this->chainContext->getCreator();

        $chain = $this->chainContext->getChain();
        $chain->add(new Event($process))->signWith($account);
    }


    /**
     * @Given :accountRef creates the :processRef process using the :scenarioRef scenario
     */
    public function createProcessUsingScenario(string $accountRef, string $processRef, string $scenarioRef): void
    {
        $account = $this->chainContext->getAccount($accountRef);
        $process = $this->getProcess($processRef);

        $process->setCreator($account);
        $process->loadScenario($scenarioRef, $this->basePath);

        $scenarioEvent = new Event($process->scenario);
        $this->chainContext->getChain()->add($scenarioEvent)->signWith($account);
    }

    /**
     * @Given :accountRef is (also) the :actor actor of the :processRef process
     */
    public function defineActor(string $accountRef, string $actor, string $processRef): void
    {
        $account = $this->chainContext->getAccount($accountRef);
        $identity = $this->chainContext->createIdentity($account);

        $this->getProcess($processRef)->setActor($actor, $identity);
    }

    /**
     * @When the :processRef process is started
     */
    public function startProcessAction(string $processRef): void
    {
        $process = $this->getProcess($processRef);

        if ($process->getProjection() !== null) {
            Assert::fail("Process \"$processRef\" is already started");
        }

        $this->addProcessToChain($process);

        $this->chainContext->submit();
    }

    /**
     * @When :accountRef runs the :actionKey action of the :processRef process
     * @When :accountRef runs the :actionKey action of the :processRef process giving a(n) :responseKey response
     */
    public function runAction(
        string $accountRef,
        string $actionKey,
        string $processRef,
        ?string $responseKey = null
    ): void {
        $this->runActionWithData($accountRef, $actionKey, $processRef, $responseKey);
    }

    /**
     * @When :accountRef runs the :actionKey action of the :processRef process with:
     * @When :accountRef runs the :actionKey action of the :processRef process giving a(n) :responseKey response with:
     */
    public function runActionWithData(
        string $accountRef,
        string $actionKey,
        string $processRef,
        ?string $responseKey = null,
        ?TableNode $table = null,
        ?PyStringNode $markdown = null
    ): void {
        $account = $this->chainContext->getAccount($accountRef);
        $process = $this->getProcess($processRef);

        if (!$process->hasActorWithSignkey($account->getPublicSignKey())) {
            Assert::fail("\"$accountRef\" is not an actor of the \"$processRef\" process");
        }

        if ($process->getProjection() === null) {
            $this->addProcessToChain($process);
        }

        $data = $this->convertInputToData($table, $markdown);
        $response = $process->createResponse($actionKey, $responseKey, $data);

        $chain = $this->chainContext->getChain();
        (new Event($response))->addTo($chain)->signWith($account);

        $this->chainContext->submit($account);
    }

    /**
     * @Then the :processRef process has asset :assetKey
     * @Then the :processRef process has asset :assetKey with:
     */
    public function checkAsset(string $processRef, string $assetKey, ?TableNode $table = null): void
    {
        $process = $this->getProcess($processRef);
        $projection = $this->getProjection($process);

        Assert::assertArrayHasKey($assetKey, $projection['assets']);

        if (isset($table)) {
            Assert::assertArrayByDotkey($this->tableToPairs($table), $projection['assets'][$assetKey]);
        }
    }

    /**
     * @Then :title is in the history of the :processRef process
     */
    public function hasInHistory(string $processRef, string $title): void
    {
        $process = $this->getProcess($processRef);
        $projection = $this->getProjection($process);

        Assert::assertCointainsByDotkey($title, 'title', $projection['previous']);
    }

    /**
     * @Then the :processRef process is in the :state state
     */
    public function checkState(string $processRef, string $state): void
    {
        $process = $this->getProcess($processRef);
        $projection = $this->getProjection($process);

        Assert::assertArrayHasKey('current', $projection);
        Assert::assertSame($projection['current']['key'], $state);
    }

    /**
     * @Then the :processRef process is completed
     */
    public function checkCompleted(string $processRef): void
    {
        $this->checkState($processRef, ':success');
    }

    /**
     * @Then the :processRef process has failed
     */
    public function checkFailed(string $processRef): void
    {
        $this->checkState($processRef, ':failed');
    }
}

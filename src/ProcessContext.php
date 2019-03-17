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
                ['account' => $account, 'headers' => ['Accept' => 'application/json']]
            );

            $projection = json_decode($response->getBody(), true);
            $process->setProjection($projection);
        }

        return $projection;
    }


    /**
     * @Given :accountRef creates the :processRef process using scenario :scenarioRef
     *
     * @param string $accountRef
     * @param string $processRef
     * @param string $scenarioRef
     */
    public function createProcessUsingScenario(string $accountRef, string $processRef, string $scenarioRef)
    {
        $account = $this->chainContext->getAccount($accountRef);
        $process = $this->getProcess($processRef);

        $process->loadScenario($scenarioRef, $this->basePath);

        $event = new Event($process->scenario);
        $this->chainContext->getChain()->add($event)->signWith($account);
    }

    /**
     * @Given the :processRef process has id :processId
     *
     * @param string $processRef
     * @param string $processId
     */
    public function setProcessId(string $processRef, string $processId)
    {
        $this->getProcess($processRef)->id = $processId;
    }

    /**
     * @Given :accountRef is the :actor actor of the :processRef process
     *
     * @param string $accountRef
     * @param string $actor
     * @param string $processRef
     */
    public function defineActor(string $accountRef, string $actor, string $processRef)
    {
        $account = $this->chainContext->getAccount($accountRef);

        $this->getProcess($processRef)->actors[$actor] = $account;
    }

    /**
     * @When :accountRef runs the :actionKey action of the :processRef process
     * @When :accountRef runs the :actionKey action of the :processRef process as :actor
     * @When :accountRef runs the :actionKey action of the :processRef process giving an :responseKey response
     * @When :accountRef runs the :actionKey action of the :processRef process giving an :responseKey response as :actor
     *
     * @param string $accountRef
     * @param string $actionKey
     * @param string $processRef
     * @param string|null $actor
     * @parma string|null $responseKey
     */
    public function runAction(
        string $accountRef,
        string $actionKey,
        string $processRef,
        ?string $actor = null,
        ?string $responseKey = null
    ) {
        $this->runActionWithData($accountRef, $actionKey, $processRef, $actor, $responseKey);
    }

    /**
     * @When :accountRef runs the :actionKey action of the :processRef process with:
     * @When :accountRef runs the :actionKey action of the :processRef process as :actor with:
     * @When :accountRef runs the :actionKey action of the :processRef process giving a(n) :responseKey response with:
     * @When :accountRef runs the :actionKey action of the :processRef process giving a(n) :responseKey response as :actor with:
     *
     * @param string $accountRef
     * @param string $actionKey
     * @param string $processRef
     * @param string|null $actor
     * @parma string|null $responseKey
     * @param TableNode|null $table
     * @param PyStringNode|null $markdown
     */
    public function runActionWithData(
        string $accountRef,
        string $actionKey,
        string $processRef,
        ?string $actor = null,
        ?string $responseKey = null,
        ?TableNode $table = null,
        ?PyStringNode $markdown = null
    ) {
        $account = $this->chainContext->getAccount($accountRef);
        $process = $this->getProcess($processRef);

        if (!isset($actor)) {
            $actor = array_search($account, $process->actors, true);

            if (!$actor) {
                throw new \OutOfBoundsException("\"$accountRef\" is not an actor of the \"$processRef\" process");
            }
        } elseif (($process->actors[$actor] ?? null) !== $account) {
            throw new \OutOfBoundsException("\"$accountRef\" is not the \"$actor\" actor of the \"$processRef\" process");
        }

        $data = $this->convertInputToData($table, $markdown);
        $response = $process->createResponse($actionKey, $actor, $responseKey, $data);

        $chain = $this->chainContext->getChain();
        (new Event($response))->addTo($chain)->signWith($account);

        $this->chainContext->submit($account);
    }

    /**
     * @Then the :processRef process has asset :assetKey
     * @Then the :processRef process has asset :assetKey with:
     *
     * @param string $processRef
     * @param string $assetKey
     * @param TableNode|null $table
     */
    public function checkAsset(string $processRef, string $assetKey, ?TableNode $table = null)
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
     *
     * @param string $processRef
     * @param string $title
     */
    public function hasInHistory(string $processRef, string $title)
    {
        $process = $this->getProcess($processRef);
        $projection = $this->getProjection($process);

        Assert::assertCointainsByDotkey($title, 'title', $projection['previous']);
    }

    /**
     * @Then the :processRef process is in the :state state
     *
     * @param string $processRef
     * @param string $state
     */
    public function checkState(string $processRef, string $state)
    {
        $process = $this->getProcess($processRef);
        $projection = $this->getProjection($process);

        Assert::assertArrayHasKey('current', $projection);
        Assert::assertSame($projection['current']['key'], $state);
    }

    /**
     * @Then the :processRef process is completed
     *
     * @param string $processRef
     */
    public function checkCompleted(string $processRef)
    {
        $this->checkState($processRef, ':success');
    }

    /**
     * @Then the :processRef process has failed
     *
     * @param string $processRef
     */
    public function checkFailed(string $processRef)
    {
        $this->checkState($processRef, ':failed');
    }
}

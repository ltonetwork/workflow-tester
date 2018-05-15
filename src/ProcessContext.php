<?php

namespace LegalThings\LiveContracts\Tester;

use Behat\Behat\Context\Context;
use Behat\Behat\Context\Exception as ContextException;
use Behat\Behat\Hook\Scope\BeforeScenarioScope;
use Behat\Gherkin\Node\PyStringNode;
use Behat\Gherkin\Node\TableNode;
use LTO\Account;
use LTO\Event;
use LTO\EventChain;
use LegalThings\LiveContracts\Tester\EventChainContext;
use LegalThings\LiveContracts\Tester\Process;
use Jasny\DotKey;

/**
 * Defines application features from the specific context.
 */
class ProcessContext implements Context
{
    /**
     * @var EventChainContext
     */
    protected $chainContext;

    /**
     * @var string
     */
    protected $basePath;

    /**
     * @var Process[]
     */
    protected $processes;


    /**
     * Get event related contexts
     *
     * @BeforeScenario
     */
    public function gatherContexts(BeforeScenarioScope $scope)
    {
        $environment = $scope->getEnvironment();

        $this->chainContext = $environment->getContext(EventChainContext::class);

        $paths = $environment->getSuite()->getSetting('paths');
        $this->basePath = dirname($paths[0]);
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
            $this->processes[$ref] = new Process($chain);
        }

        return $this->processes[$ref];
    }

    /**
     * Convert table to structured data
     */
    protected function tableToJson(TableNode $table)
    {
        $data = [];
        $dotkey = DotKey::on($data);

        foreach ($table->getTable() as $item) {
            $dotkey->put($item[0], $item[1]);
        }

        return $data;
    }


    /**
     * @Given ":accountRef" creates the ":processRef" process using scenario ":scenarioRef"
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

        $this->chainContext->getChain()->add(new Event($process->scenario))->signWith($account);
    }

    /**
     * @Given the ":processRef" process has id ":id"
     *
     * @param string $processRef
     * @param string $scenarioRef
     */
    public function setProcessId(string $processRef, string $processId)
    {
        $this->getProcess($processRef)->id = $processId;
    }

    /**
     * @Given ":accountRef" is the ":actor" actor of the ":processRef" process
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
     * @When ":accountRef" runs the ":actionKey" action of the ":processRef" process with:
     * @When ":accountRef" runs the ":actionKey" action of the ":processRef" process as ":actor" with:
     * @When ":accountRef" responds with ":responseKey" on the ":actionKey" action of the ":processRef" process with:
     * @When ":accountRef" responds with ":responseKey" on the ":actionKey" action of the ":processRef" process as ":actor" with:
     *
     * @param string $accountRef
     * @param string $actionKey
     * @param string $processRef
     * @param string|null $actor
     * @parma string|null $responseKey
     * @param TableNode|null $table
     * @param PyStringNode|null $markdown
     */
    public function runAction(
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
        } elseif (($process->actors[$actor] ?? null) !== $account) {
            throw new ContextException("\"$accountRef\" is not the \"$actor\" actor of the \"$processRef\" process");
        }

        if (isset($table)) {
            $data = $this->tableToJson($table);
        } else {
            $data = isset($markdown) ? json_decode($markdown->getRaw()) : null;
        }

        $response = $process->createResponse($actionKey, $actor, $responseKey, $data);

        $chain = $this->chainContext->getChain();
        (new Event($response))->addTo($chain)->signWith($account);

        $this->chainContext->submit($account);
    }

    /**
     * @Then the ":processRef" process has asset ":assetKey" with:
     *
     * @param string $processRef
     * @param string $assetKey
     * @param TableNode|null $table
     * @param PyStringNode|null $markdown
     */
    public function checkAsset(
        string $processRef,
        string $assetKey,
        ?TableNode $table = null,
        ?PyStringNode $markdown = null
    ) {
        //$projection = $this->getProcess($processRef)->getProjection();
    }

    /**
     * @Then the ":processRef" process is in the ":state" state
     *
     * @param string $processRef
     * @param string $state
     */
    public function checkState(string $processRef, string $state)
    {
        // TODO
    }
}

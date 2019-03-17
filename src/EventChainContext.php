<?php

namespace LTO\LiveContracts\Tester;

use Behat\Behat\Context\Context;
use Behat\Behat\Hook\Scope\BeforeScenarioScope;
use Behat\Testwork\Suite\Exception\SuiteException;
use Behat\Testwork\Hook\Scope\BeforeSuiteScope;
use Behat\Gherkin\Node\TableNode;
use GuzzleHttp\ClientInterface as HttpClient;
use LTO\AccountFactory;
use LTO\Account;
use LTO\Event;

/**
 * Defines application features from the specific context.
 */
class EventChainContext implements Context
{
    use BehatInputConversion;

    /**
     * @var string
     */
    protected static $suiteName;

    /**
     * @var AccountFactory
     */
    public $accountFactory;

    /**
     * @var HttpClient
     */
    protected $httpClient;

    /**
     * @var Account[]
     */
    protected $accounts;

    /**
     * @var Account|null
     */
    protected $creator;

    /**
     * @var EventChain|null  The main chain
     */
    protected $chain;

    /**
     * @var string Public key of the node
     */
    protected $systemSignKey;


    /**
     * Initializes context.
     */
    public function __construct()
    {
        $this->accountFactory = new AccountFactory('T',0);
    }


    /**
     * Initialize the suite.
     * @BeforeSuite
     *
     * @param BeforeSuiteScope $scope
     */
    public static function initSuite(BeforeSuiteScope $scope)
    {
        self::$suiteName = $scope->getSuite()->getName();
    }

    /**
     * Get the http client from the http context.
     * @BeforeScenario
     */
    public function gatherHttpClient(BeforeScenarioScope $scope)
    {
        $this->httpClient = $scope->getEnvironment()->getContext(HttpContext::class)->getClient();
    }

    /**
     * Get system sign key.
     * @BeforeScenario
     */
    public function fetchSystemKey(BeforeScenarioScope $scope)
    {
        $response = $this->httpClient->request('GET', '/', ['headers' => ['Accept' => 'application/json']]);
        $data = json_decode($response->getBody());

        $this->systemSignKey = $data->services->events->signkey ?? null;
    }

    /**
     * Get an account by ref or create it if needed
     *
     * @param string $accountRef
     * @return Account
     */
    public function getAccount(string $accountRef): Account
    {
        if (!isset($this->accounts[$accountRef])) {
            $this->accounts[$accountRef] = $this->accountFactory->seed($accountRef);
        }

        return $this->accounts[$accountRef];
    }

    /**
     * Get the main event chain
     *
     * @return EventChain
     * @throws SuiteException if event chain hasn't been created
     */
    public function getChain(): EventChain
    {
        if (!isset($this->chain)) {
            throw new SuiteException("the chain hasn't been created", self::$suiteName);
        }

        return $this->chain;
    }

    /**
     * Get the account that created the chain.
     *
     * @return Account
     */
    public function getCreator(): Account
    {
        if (!isset($this->creator)) {
            throw new SuiteException("the chain hasn't been created", self::$suiteName);
        }

        return $this->creator;
    }

    /**
     * Update the projection of the chain
     *
     * @param Account $account
     */
    protected function updateProjection(Account $account)
    {
        $response = $this->httpClient->request(
            'GET',
            'event-chains/' . $this->getChain()->id,
            ['account' => $account, 'headers' => ['Accept' => 'application/json']]
        );
        $projection= json_decode($response->getBody());

        $chain = $this->getChain();
        $chain->update($projection);
        $chain->linkIdentities($this->accounts);
    }

    /**
     * Send the chain
     *
     * @param Account $account  Account that is signing the request
     */
    public function submit(Account $account)
    {
        $this->httpClient->request('POST', 'event-chains', ['json' => $this->getChain(), 'account' => $account]);
        $this->updateProjection($account);
    }

    /**
     * Create an identity based on an account
     *
     * @param Account $account
     * @return array
     */
    public function createIdentity(Account $account): array
    {
        return [
            '$schema' => "https://specs.livecontracts.io/v0.2.0/identity/schema.json#",
            "id" => $account->id,
            "node" => "amqps://localhost",
            "signkeys" => [
                "default" => $account->getPublicSignKey(),
                "system" => $this->systemSignKey,
            ],
            "encryptkey" => $account->getPublicEncryptKey()
        ];
    }


    /**
     * @Given a chain is created by :accountRef
     *
     * @param string $accountRef
     */
    public function chainIsCreatedBy(string $accountRef)
    {
        $account = $this->getAccount($accountRef);

        $this->chain = new EventChain();
        $this->chain->initFor($account);

        $account->id = $this->chain->createResourceId($accountRef);

        $identityEvent = new Event($this->createIdentity($account));
        $identityEvent->addTo($this->chain)->signWith($account);

        $this->creator = $account;
    }

    /**
     * @Given :accountRef signs with :key
     *
     * @param string $accountRef
     * @param string $key
     */
    public function createAccountWithSignKey(string $accountRef, string $key)
    {
        if (isset($this->accounts[$accountRef])) {
            throw new SuiteException("the \"$accountRef\" account has already been defined", self::$suiteName);
        }

        $account = $this->accountFactory->create($key);
        $this->accounts[$accountRef] = $account;
    }


    /**
     * @Then :accountRef is present
     * @Then :accountRef is present with:
     *
     * @param string $accountRef
     * @param TableNode|null $table
     */
    public function accountIsPresent(string $accountRef, ?TableNode $table = null)
    {
        $account = $this->getAccount($accountRef);

        if (!$this->chain->isSynced()) {
            throw new SuiteException("the chain has unsubmitted events", self::$suiteName);
        }

        $identity = array_reduce($this->chain->identities, function($found, $identity) use ($account) {
            return $found ?? ($identity->signkeys->user === $account->getPublicSignKey() ? $identity : null);
        }, null);

        if (!isset($identity)) {
            Assert::fail("\"$accountRef\" is not an identity on the chain");
        }

        if (isset($table)) {
            Assert::assertArrayByDotkey($this->tableToPairs($table), $identity);
        }
    }
}

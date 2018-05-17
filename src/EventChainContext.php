<?php

namespace LegalThings\LiveContracts\Tester;

use Behat\Behat\Context\Context;
use Behat\Behat\Context\Exception as ContextException;
use Behat\Testwork\Hook\Scope\BeforeSuiteScope;
use Behat\Gherkin\Node\TableNode;
use DateTimeImmutable;
use LTO\AccountFactory;
use LTO\Account;
use LTO\HTTPSignature;
use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Handler\CurlHandler;
use Psr\Http\Message\RequestInterface;
use LegalThings\LiveContracts\Tester\EventChain;
use LegalThings\LiveContracts\Tester\Assert;
use LegalThings\LiveContracts\Tester\BehatInputConversion;

/**
 * Defines application features from the specific context.
 */
class EventChainContext implements Context
{
    use BehatInputConversion;

    /**
     * @var HttpClient
     */
    public static $httpClient;

    /**
     * @var AccountFactory
     */
    public $accountFactory;

    /**
     * @var DateTimeImmutable
     */
    protected $today;

    /**
     * @var Account[]
     */
    protected $accounts;

    /**
     * @var EventChain  The main chain
     */
    protected $chain;


    /**
     * Initializes context.
     */
    public function __construct()
    {
        $this->accountFactory = new AccountFactory('T',0);
        $this->today = new DateTimeImmutable();
    }

    /**
     * Get HTTP endpoint
     *
     * @param BeforeSuiteScope $scope
     *
     * @BeforeSuite
     */
    public static function initHttpClient(BeforeSuiteScope $scope)
    {
        $environment = $scope->getEnvironment();
        $endpoint = $environment->getSuite()->getSetting('endpoint') ?? 'http://localhost';

        $stack = new HandlerStack();
        $stack->setHandler(new CurlHandler());
        $stack->push(function(callable $handler) {
            return function(RequestInterface $request, $options) use ($handler) {
                $signedRequest = (new HTTPSignature($request))->signWith($options['account']);
                return $handler($request, $options);
            };
        });

        self::$httpClient = new HttpClient(['base_uri' => $endpoint, 'stack' => $stack]);

        // Test connection
        self::$httpClient->get('/');
    }

    /**
     * Get an account by ref or create it if needed
     *
     * @param string $accountRef
     * @return Account
     */
    public function getAccount(string $accountRef): Account
    {
        if (isset($this->accounts[$accountRef])) {
            $account = $this->accounts[$accountRef];
        } else {
            $account = $this->accountFactory->seed($accountRef);
            $this->accounts[$accountRef] = $account;
        }

        return $account;
    }

    /**
     * Get the main event chain
     *
     * @return EventChain
     * @throws ContextException if event chain hasn't been created
     */
    public function getChain()
    {
        if (!isset($this->chain)) {
            throw new ContextException("the chain hasn't been created");
        }

        return $this->chain;
    }

    /**
     * Update the projection of the chain
     *
     * @param Account $account
     */
    protected function updateProjection(Account $account)
    {
        $response = self::$httpClient->get('/events/' . $this->getChain()->id, compact('account'));
        $projection = json_decode($response->getBody());

        $this->getChain()->setProjection($projection);
    }

    /**
     * Send the chain
     *
     * @param Account $account  Account that is signing the request
     */
    public function submit(Account $account)
    {
        self::$httpClient->post('/events', ['json' => $this->getChain(), 'account' => $account]);

        $this->updateProjection($account);
    }


    /**
     * @Given today is :date
     *
     * @param string $date
     */
    public function todayIs($date)
    {
        $this->today = new DateTimeImmutable($date);
    }

    /**
     * @Given a chain is created by :accountRef
     *
     * @param string $accountRef
     */
    public function chainIsCreatedBy(string $accountRef)
    {
        $this->chain = $this->getAccount($accountRef)->createEventChain();
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
            throw new ContextException("the \"$accountRef\" account has already been defined");
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

        $projection = $this->chain->getProjection();
        if (!isset($projection)) {
            throw new ContextException("the chain has unsubmitted events");
        }

        $identity = array_reduce($projection['identities'], function($found, $identity) use ($account) {
            return $found ?? ($identity['id'] === $account->id ? $identity : null);
        }, null);

        if (!isset($identity)) {
            Assert::fail("\"$accountRef\" is not an identity on the chain");
        }

        if (isset($table)) {
            Assert::assertArrayByDotkey($this->tableToPairs($table), $identity);
        }
    }
}

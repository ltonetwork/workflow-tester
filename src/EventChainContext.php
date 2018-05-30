<?php

namespace LegalThings\LiveContracts\Tester;

use Behat\Behat\Context\Context;
use Behat\Testwork\Suite\Exception\SuiteException;
use Behat\Testwork\Hook\Scope\BeforeSuiteScope;
use Behat\Gherkin\Node\TableNode;
use DateTimeImmutable;
use LTO\AccountFactory;
use LTO\Account;
use LTO\Event;
use LTO\HTTPSignature;
use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Handler\CurlHandler;
use Psr\Http\Message\RequestInterface;
use LegalThings\LiveContracts\Tester\EventChain;
use LegalThings\LiveContracts\Tester\Assert;
use LegalThings\LiveContracts\Tester\BehatInputConversion;
use Ramsey\Uuid\Uuid;
use UnexpectedValueException;

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
     * Initialize the suite
     *
     * @param BeforeSuiteScope $scope
     *
     * @BeforeSuite
     */
    public static function initSuite(BeforeSuiteScope $scope)
    {
        self::$suiteName = $scope->getSuite()->getName();
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

        $stack = HandlerStack::create();
        $stack->push(function(callable $handler) {
            return function(RequestInterface $request, $options) use ($handler) {
                $signedRequest = isset($options['account'])
                    ? (new HTTPSignature($request, ['(request-target)', 'date']))->signWith($options['account'])
                    : $request;
                return $handler($signedRequest, $options);
            };
        });
        $stack->push(function(callable $handler) {
            return function(RequestInterface $request, $options) use ($handler) {
                return $handler($request, $options)->then(function($response) {
                    if ($response->getStatusCode() >= 400) {
                        echo (string)$response->getBody();
                    }
                    return $response;
                });
            };
        });

        self::$httpClient = new HttpClient(['base_uri' => $endpoint, 'handler' => $stack]);

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
     * Update the projection of the chain
     *
     * @param Account $account
     */
    protected function updateProjection(Account $account)
    {
        $response = self::$httpClient->get('events/event-chains/' . $this->getChain()->id, compact('account'));

        list($contentType) = explode(';', $response->getHeaderLine('Content-Type'));

        if ($contentType !== 'application/json') {
            throw new UnexpectedValueException("Expected application/json, got $contentType");
        }

        $projection = json_decode($response->getBody());

        if (!isset($projection)) {
            throw new UnexpectedValueException("Response is not not valid JSON");
        }

        $this->getChain()->setProjection($projection);
    }

    /**
     * Send the chain
     *
     * @param Account $account  Account that is signing the request
     */
    public function submit(Account $account)
    {
        self::$httpClient->post('events/event-chains/', ['json' => $this->getChain(), 'account' => $account]);

        $this->updateProjection($account);
    }

    /**
     * Create an identity based on an account
     *
     * @param string  $name
     * @param Account $account
     * @return array
     */
    public function createIdentity(string $name, Account $account): array
    {
        $account->id = Uuid::uuid4();

        return [
            '$schema' => "https://specs.livecontracts.io/v0.1.0/identity/schema.json#",
            "id" => $account->id,
            "info" => [
                "name" => $name
            ],
            "node" => "amqps://localhost",
            "signkeys" => [
                "user" => $account->getPublicSignKey(),
                "system" => $account->getPublicSignKey()
            ],
            "encryptkey" => $account->getPublicEncryptKey()
        ];
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
        $account = $this->getAccount($accountRef);

        $this->chain = new EventChain();
        $this->chain->initFor($account);

        $identityEvent = new Event($this->createIdentity($accountRef, $account));
        $identityEvent->addTo($this->chain)->signWith($account);
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

        $projection = $this->chain->getProjection();
        if (!isset($projection)) {
            throw new SuiteException("the chain has unsubmitted events", self::$suiteName);
        }

        $identity = array_reduce($projection->identities, function($found, $identity) use ($account) {
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

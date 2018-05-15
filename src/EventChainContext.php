<?php

namespace LegalThings\LiveContracts\Tester;

use Behat\Behat\Context\Context;
use Behat\Behat\Context\Exception as ContextException;
use Behat\Behat\Hook\Scope\BeforeScenarioScope;
use Behat\Gherkin\Node\PyStringNode;
use Behat\Gherkin\Node\TableNode;
use DateTimeImmutable;
use LTO\AccountFactory;
use LTO\Account;
use LTO\EventChain;
use LTO\HTTPSignature;
use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Handler\CurlHandler;
use Psr\Http\Message\RequestInterface;

/**
 * Defines application features from the specific context.
 */
class EventChainContext implements Context
{
    /**
     * @var AccountFactory
     */
    public $accountFactory;

    /**
     * @var HttpClient
     */
    protected $httpClient;

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
     * @BeforeScenario
     */
    public function initHttpClient(BeforeScenarioScope $scope)
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

        $this->httpClient = new HttpClient(['base_uri' => $endpoint, 'stack' => $stack]);
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
     * Send the chain
     *
     * @param Account $account  Account that is signing the request
     */
    public function submit(Account $account)
    {
        $this->httpClient->post('/events', ['json' => $this->getChain(), 'account' => $account]);
    }


    /**
     * @Given today is ":date"
     *
     * @param string $date
     */
    public function todayIs($date)
    {
        $this->today = new DateTimeImmutable($date);
    }

    /**
     * @Given a chain is created by ":accountRef"
     *
     * @param string $accountRef
     */
    public function aChainIsCreatedBy(string $accountRef)
    {
        $this->chain = $this->getAccount($accountRef)->createEventChain();
    }

    /**
     * @Then ":accountRef" is present with:
     *
     * @param string $accountRef
     * @param TableNode|null $table
     * @param PyStringNode|null $markdown
     */
    public function checkAsset(string $accountRef, ?TableNode $table = null, ?PyStringNode $markdown = null)
    {
        // TODO
    }
}

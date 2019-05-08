<?php

namespace LTO\LiveContracts\Tester;

use JsonSerializable;
use LTO\Account;
use LTO\EventChain;
use BadMethodCallException;
use RuntimeException;

/**
 * Representation of a Live Contracts process
 */
class Process implements JsonSerializable
{
    /**
     * @var string
     */
    public $schema = 'https://specs.livecontracts.io/v0.2.0/process/schema.json#';

    /**
     * @var string
     */
    public $id;

    /**
     * @var array
     */
    public $scenario;

    /**
     * @var \stdClass[]
     */
    public $actors = [];

    /**
     * @var EventChain
     */
    protected $chain;

    /**
     * @var array
     */
    protected $projection;

    /**
     * The hash of last event of the chain when the projection was fetched
     * @var string
     */
    protected $eventAtProjection;

    /**
     * @var Account|null
     */
    protected $creator;

    /**
     * @var bool
     */
    protected $started = false;


    /**
     * Process constructor.
     *
     * @param EventChain  $chain
     * @param string|null $ref
     */
    public function __construct(EventChain $chain, ?string $ref = null)
    {
        $this->chain = $chain;
        $this->id = $chain->createResourceId($ref);
    }


    /**
     * Check if the process has been started or mark the process as started.
     *
     * @param bool|null $set
     * @return bool
     */
    public function isStarted(?bool $set = null)
    {
        if ($set !== null) {
            $this->started = $set;
        }

        return $this->started;
    }

    /**
     * Set the creator of the process
     *
     * @param Account $account
     * @throws BadMethodCallException
     */
    public function setCreator(Account $account): void
    {
        if ($this->creator !== null && $this->creator->getPublicSignKey() === $account->getPublicSignKey()) {
            return;
        }

        if ($this->creator !== null) {
            throw new BadMethodCallException("Process creator already set");
        }

        $this->creator = $account;
    }

    /**
     * Get the creator of the process.
     *
     * @return Account|null
     */
    public function getCreator(): ?Account
    {
        return $this->creator;
    }


    /**
     * Load a scenario
     *
     * @param string $name
     * @param string $path
     * @return void
     * @throws BadMethodCallException if the scenario is already set
     * @throws RuntimeException if the scenario can't be loaded
     */
    public function loadScenario(string $name, string $path): void
    {
        if (isset($this->scenario)) {
            throw new BadMethodCallException("Scenario already set");
        }

        $this->id = $this->chain->createResourceId('process:' . $name);

        $this->scenario = ScenarioLoader::getInstance()->load($name, $path);
        $this->scenario->id = $this->chain->createResourceId('scenario:' . $path);
    }

    /**
     * Set the actor to an identity.
     *
     * @param string $actorKey
     * @param \stdClass|array $identity
     */
    public function setActor(string $actorKey, $identity): void
    {
        if (isset($this->actors[$actorKey])) {
            throw new BadMethodCallException("Actor '$actorKey' already set");
        }

        $this->actors[$actorKey] = (object)['identity' => (object)$identity];
    }

    /**
     * Check if the process has an actor with the given signkey
     *
     * @param string $signkey
     * @return bool
     */
    public function hasActorWithSignkey(string $signkey): bool
    {
        foreach ($this->actors as $actor) {
            if (($actor->identity->signkeys['default'] ?? null) === $signkey) {
                return true;
            }
        }

        return false;
    }

    /**
     * Create a response of an action
     *
     * @param string $actionKey
     * @param string $key
     * @param mixed  $data
     * @return array
     * @throws BadMethodCallException if the scenario is not set
     */
    public function createResponse(string $actionKey, ?string $key, $data = null): array
    {
        if (!isset($key)) {
            $key = $this->scenario->actions->$actionKey->default_response ?? 'ok';
        }

        $response = [
            '$schema' => 'https://specs.livecontracts.io/v0.2.0/response/schema.json#',
            'process' => $this->id,
            'action' => [
                'key' => $actionKey
            ],
            'key' => $key,
            'data' => $data
        ];

        return $response;
    }


    /**
     * Get the projection of the process
     *
     * @return array|null
     */
    public function getProjection(): ?array
    {
        return $this->eventAtProjection === $this->chain->getLatestHash() ? $this->projection : null;
    }

    /**
     * Set the projection of the process
     *
     * @param array $projection
     */
    public function setProjection(array $projection): void
    {
        $this->projection = $projection;
        $this->eventAtProjection === $this->chain->getLatestHash();
    }

    /**
     * Prepare JSON serialization.
     *
     * @return \stdClass
     */
    public function jsonSerialize(): \stdClass
    {
        return (object)[
            '$schema' => $this->schema,
            'id' => $this->id,
            'scenario' => $this->scenario,
            'actors' => $this->actors
        ];
    }
}

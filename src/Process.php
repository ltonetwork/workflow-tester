<?php
/**
 * Created by PhpStorm.
 * User: arnold
 * Date: 13-5-18
 * Time: 21:56
 */

namespace LegalThings\LiveContracts\Tester;

use LTO\Account;
use LTO\EventChain;
use BadMethodCallException;
use RuntimeException;
use OutOfBoundsException;

/**
 * Representation of a Live Contracts process
 */
class Process
{
    /**
     * @var string
     */
    public $id;

    /**
     * @var array
     */
    public $scenario;

    /**
     * @var Account[]
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
     * Process constructor.
     *
     * @param EventChain $chain
     */
    public function __construct(EventChain $chain)
    {
        $this->chain = $chain;
        $this->id = $chain->createProjectionId();
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

        if (!file_exists("$path/$name/scenario.json")) {
            throw new RuntimeException("Unable to load scenario: \"$path/$name/scenario.json\" not found");
        }

        $json = file_get_contents("$path/$name/scenario.json");

        if ($json !== false) {
            $scenario = json_decode($json);
        }

        if (empty($scenario)) {
            throw new RuntimeException("Unable to load scenario: failed to parse \"$path/$name/scenario.json\"");
        }

        $this->scenario = $scenario;
    }

    /**
     * Create a response of an action
     *
     * @param string $actionKey
     * @param string $actor
     * @param string $key
     * @param mixed  $data
     * @return array
     * @throws BadMethodCallException if the scenario is not set
     */
    public function createResponse(string $actionKey, string $actor, ?string $key, $data = null): array
    {
        if (!isset($this->actors[$actor])) {
            throw new OutOfBoundsException("Actor '$actor' is not present in process");
        }

        if (!isset($key)) {
            $key = $this->scenario->actions->$actionKey->default_response ?? 'ok';
        }

        $response = [
            '$schema' => 'https://specs.livecontracts.io/v0.1.0/response/schema.json#',
            'process' => [
                'id' => 'lt:/processes/' . $this->id,
                'scenario' => [
                    'id' => $this->scenario->id ?? null
                ]
            ],
            'action' => [
                'key' => $actionKey
            ],
            'actor' => [
                'key' => $actor,
                'id' => $this->actors[$actor]->id
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
    public function setProjection(array $projection)
    {
        $this->projection = $projection;
        $this->eventAtProjection === $this->chain->getLatestHash();
    }
}
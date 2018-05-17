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
     * @var \stdClass
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
        $this->id = new $chain->createProjectionId();
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
     * @param string $response
     * @param mixed  $data
     * @return array
     * @throws BadMethodCallException if the scenario is not set
     */
    public function createResponse(string $actionKey, string $actor, ?string $response, $data = null): array
    {
        $response = [
            'process' => [
                'id' => $this->id
            ],
            'action' => [
                'key' => $actionKey
            ],
            'actor' => [
                'key' => $actor
            ],
            'key' => $response,
            'data' => $data
        ];

        if (isset($this->scenario)) {
            $response['process']['scenario'] = ['id' => $this->scenario->id];
        }

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

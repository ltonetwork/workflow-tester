<?php declare(strict_types=1);

namespace LTO\LiveContracts\Tester;

use InvalidArgumentException;
use BadMethodCallException;
use RuntimeException;

use function Jasny\objectify;

/**
 * Service to load scenarios.
 */
class ScenarioLoader
{
    protected const SCENARIO_FILES = [
        '%s.yml',
        '%s.json',
        '%s/scenario.yml',
        '%s/scenario.json'
    ];

    /**
     * @var self
     */
    private static $instance;

    /**
     * Singleton method.
     * @todo Ewhh a singleton. Need to find out how to do proper DI with behat.
     *
     * @return self
     */
    public static function getInstance(): self
    {
        if (!isset(self::$instance)) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Load a scenario
     *
     * @param string $name
     * @param string $path
     * @return \stdClass
     * @throws BadMethodCallException if the scenario is already set
     * @throws RuntimeException if the scenario can't be loaded
     */
    public function load(string $name, string $path): \stdClass
    {
        $file = $this->findScenarioFile($name, $path);
        $scenario = $this->parseScenario($file);

        return $scenario;
    }

    /**
     * Find the scenario file.
     * Can be `name.yml`, `name.json`, `name/scenario.yml` or`name/scenario.json`.
     *
     * @param string $name
     * @param string $path
     * @return string
     * @throws BadMethodCallException if the scenario is already set
     * @throws RuntimeException if the scenario can't be loaded
     */
    protected function findScenarioFile(string $name, string $path): string
    {
        foreach (self::SCENARIO_FILES as $option) {
            $file = $path . '/' . sprintf($option, $name);

            if (file_exists($file)) {
                return $file;
            }
        }

        throw new RuntimeException("Unable to load scenario \"$name\". Neither YAML or JSON file found");
    }

    /**
     * Parse the scenario.
     *
     * @param string $file
     * @return mixed
     */
    protected function parseScenario(string $file)
    {
        switch (pathinfo($file, PATHINFO_EXTENSION)) {
            case 'yml':
                return $this->parseYamlScenario($file);
            case 'json':
                return $this->parseJsonScenario($file);
        }

        throw new InvalidArgumentException("Dont know how to parse \"$file\". (How did we get here?)");
    }

    /**
     * Parse the scenario from a JSON file.
     *
     * @param string $file
     * @return \stdClass
     */
    protected function parseYamlScenario(string $file): \stdClass
    {
        $tagToStruct = function($value, $tag) {
            $key = substr($tag, 1);
            return ["<$key>" => $value];
        };

        $callbacks = [
            '!if' => $tagToStruct,
            '!ref' => $tagToStruct,
            '!eval' => $tagToStruct,
            '!ifset' => $tagToStruct,
            '!switch' => $tagToStruct,
            '!merge' => $tagToStruct,
            '!tpl' => $tagToStruct,
            '!apply' => $tagToStruct,
            '!dateFormat' => $tagToStruct,
            '!id' => $tagToStruct,
        ];

        $scenario = yaml_parse_file($file, 0, $ndocs, $callbacks);

        if (!is_array($scenario)) {
            throw new RuntimeException("Unable to load scenario: failed to parse \"$file\"");
        }

        return objectify($scenario);
    }

    /**
     * Parse the scenario from a JSON file.
     *
     * @param string $file
     * @return \stdClass
     */
    protected function parseJsonScenario(string $file): \stdClass
    {
        $json = file_get_contents($file);
        $scenario = ($json !== false) ? json_decode($json) : null;

        if (!is_object($scenario)) {
            $err = json_last_error_msg();
            throw new RuntimeException("Unable to load scenario: failed to parse \"$file\": $err");
        }

        return $scenario;
    }
}

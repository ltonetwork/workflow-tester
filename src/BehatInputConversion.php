<?php

namespace LTO\LiveContracts\Tester;

use Behat\Gherkin\Node\PyStringNode;
use Behat\Gherkin\Node\TableNode;
use Jasny\DotKey;

/**
 * Convert behat input table to json data or key/value pairs.
 */
trait BehatInputConversion
{
    /**
     * Convert Behat input data to
     *
     * @param TableNode|null    $table
     * @param PyStringNode|null $markdown
     * @param array             $variables  Variables that can be substituted
     * @return array|null
     */
    protected function convertInputToData(?TableNode $table, ?PyStringNode $markdown, array $variables = []): ?array
    {
        if (isset($table)) {
            return $this->tableToData($table, $variables);
        }

        if (isset($markdown)) {
            return json_decode($markdown->getRaw());
        }

        return null;
    }

    /**
     * Convert table to structured data
     *
     * @param TableNode $table
     * @param array     $variables  Variables that can be substituted
     * @return array
     */
    protected function tableToData(TableNode $table, array $variables = [])
    {
        $data = (object)[];
        $dotkey = DotKey::on($data);

        foreach ($table->getTable() as [$key, $value]) {
            if (preg_match('/^\$\{(.*)\}$/', $value, $matches)) {
                $value = DotKey::on($variables)->get($matches[1]);
            }

            $dotkey->put($key, $value);
        }

        return (array)$data;
    }

    /**
     * Convert table to key/value pairs
     *
     * @param TableNode $table
     * @param array     $variables  Variables that can be substituted
     * @return array
     */
    protected function tableToPairs(TableNode $table, array $variables = [])
    {
        $entries = $table->getTable();

        $keys = array_column($entries, 0);
        $values = array_column($entries, 1);

        foreach ($values as &$value) {
            if (preg_match('/^\$\{(.*)\}$/', $value, $matches)) {
                $value = DotKey::on($variables)->get($matches[1]);
            }
        }

        return array_combine($keys, $values);
    }
}
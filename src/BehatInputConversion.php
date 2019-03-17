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
     * @param TableNode|null $table
     * @param PyStringNode|null $markdown
     * @return array|null
     */
    protected function convertInputToData(?TableNode $table, ?PyStringNode $markdown): ?array
    {
        if (isset($table)) {
            return $this->tableToData($table);
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
     * @return array
     */
    protected function tableToData(TableNode $table)
    {
        $data = (object)[];
        $dotkey = DotKey::on($data);

        foreach ($table->getTable() as $item) {
            $dotkey->put($item[0], $item[1]);
        }

        return (array)$data;
    }

    /**
     * Convert table to key/value pairs
     *
     * @param TableNode $table
     * @return array
     */
    protected function tableToPairs(TableNode $table)
    {
        $entries = $table->getTable();

        return array_combine(array_column($entries, 0), array_column($entries, 1));
    }
}
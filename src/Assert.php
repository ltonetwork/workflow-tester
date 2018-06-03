<?php
/**
 * Created by PhpStorm.
 * User: arnold
 * Date: 17-5-18
 * Time: 5:36
 */

namespace LegalThings\LiveContracts\Tester;

use PHPUnit\Framework\Assert as Base;
use Jasny\DotKey;

abstract class Assert extends Base
{
    /**
     * Assert array using dotkey notation.
     *
     * @param array        $expected
     * @param array|object $target
     */
    public static function assertArrayByDotkey(array $expected, $target)
    {
        $dotkey = DotKey::on($target);

        $actual = [];

        foreach ($expected as $key => $value) {
            $actual[$key] = $dotkey->get($key);
        }

        self::assertEquals($expected, $actual);
    }
}

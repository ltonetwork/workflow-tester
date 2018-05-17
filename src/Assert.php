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
     * @param array $expected
     * @param array $array
     */
    public static function assertArrayByDotkey(array $expected, array $array)
    {
        $dotkey = DotKey::on($array);

        foreach ($expected as $key => $value) {
            self::assertEquals($value, $dotkey->get($key));
        }
    }
}

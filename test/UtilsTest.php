<?php

namespace ORMiny;

class UtilsTest extends \PHPUnit_Framework_TestCase
{
    public function testStartsWith()
    {
        $startsWith = Utils::createStartWithFunction('prefix');
        $this->assertTrue(is_callable($startsWith));
        $this->assertTrue($startsWith('prefixed'));
        $this->assertFalse($startsWith('not prefixed'));
    }

    public function testGetPrefixed()
    {
        $source = [
            'foo_a' => 'b_c',
            'foo_b' => 'a_a',
            'bar_c' => 'c_d',
            'bar_d' => 'a_b'
        ];

        $this->assertEquals(
            ['a_a', 'a_b'],
            array_values(Utils::filterPrefixedElements($source, 'a_'))
        );
        $this->assertEquals(
            ['a', 'b'],
            array_values(Utils::filterPrefixedElements($source, 'a_', Utils::FILTER_REMOVE_PREFIX))
        );
        $this->assertEquals(
            ['foo_a' => 'b_c', 'foo_b' => 'a_a'],
            Utils::filterPrefixedElements($source, 'foo_', Utils::FILTER_USE_KEYS)
        );
        $this->assertEquals(
            ['a' => 'b_c', 'b' => 'a_a'],
            Utils::filterPrefixedElements($source, 'foo_', Utils::FILTER_USE_KEYS | Utils::FILTER_REMOVE_PREFIX)
        );
    }

    public function testNotNull()
    {
        $this->assertTrue(Utils::notNull(1));
        $this->assertTrue(Utils::notNull(0));
        $this->assertTrue(Utils::notNull(true));
        $this->assertTrue(Utils::notNull(false));
        $this->assertFalse(Utils::notNull(null));
    }
}

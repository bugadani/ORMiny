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

    public function testNotNull()
    {
        $this->assertTrue(Utils::notNull(1));
        $this->assertTrue(Utils::notNull(0));
        $this->assertTrue(Utils::notNull(true));
        $this->assertTrue(Utils::notNull(false));
        $this->assertFalse(Utils::notNull(null));
    }
}

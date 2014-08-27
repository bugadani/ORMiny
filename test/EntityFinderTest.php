<?php

namespace ORMiny;

use Modules\Annotation\AnnotationReader;
use Modules\DBAL\Driver;
use Modules\DBAL\Platform\MySQL;

class EntityFinderTest extends \PHPUnit_Framework_TestCase
{
    /**
     * @var EntityManager
     */
    private $entityManager;

    /**
     * @var Driver
     */
    private $driver;

    /**
     * @var EntityFinder
     */
    private $entityFinder;

    public function setUp()
    {
        $platform     = new MySQL();
        $this->driver = $this->getMockBuilder('Modules\\DBAL\\Driver')
            ->disableOriginalConstructor()
            ->setMethods(['getPlatform', 'query'])
            ->getMockForAbstractClass();

        $this->driver->expects($this->any())
            ->method('getPlatform')
            ->will($this->returnValue($platform));

        $this->entityManager = new EntityManager($this->driver, new AnnotationReader());
        $this->entityManager->register('TestEntity', 'ORMiny\\TestEntity');
        $this->entityManager->register('RelatedEntity', 'ORMiny\\RelatedEntity');
        $this->entityManager->register('DeepRelationEntity', 'ORMiny\\DeepRelationEntity');
        $this->entityManager->register(
            'HasOneRelationEntity',
            'ORMiny\\HasOneRelationEntity'
        );
        $this->entityManager->register(
            'ManyManyRelationEntity',
            'ORMiny\\ManyManyRelationEntity'
        );

        $this->entityFinder = $this->entityManager->find('TestEntity');
    }

    private function createMockStatement($return)
    {
        $mockStatement = $this->getMockBuilder('Modules\\DBAL\\Driver\\Statement')
            ->disableOriginalConstructor()
            ->setMethods(['fetchAll', 'fetch'])
            ->getMockForAbstractClass();

        $mockStatement->expects($this->any())
            ->method('fetchAll')
            ->will($this->returnValue($return));

        $mockStatement->expects($this->any())
            ->method('fetch')
            ->will(call_user_func_array([$this, 'onConsecutiveCalls'], $return));

        return $mockStatement;
    }

    private function expectQueries(array $queries)
    {
        $statements    = [];
        $queryMatchers = [];
        foreach ($queries as $query) {
            if (!is_array($query)) {
                $query = [$query, []];
            }
            if (!isset($query[1])) {
                $query[1] = [];
            }
            $queryMatchers[] = [$this->equalTo($query[0])];
            $statements[]    = $this->createMockStatement($query[1]);
        }

        $driverExpect = $this->driver
            ->expects($this->exactly(count($queries)))
            ->method('query');

        call_user_func_array([$driverExpect, 'withConsecutive'], $queryMatchers)
            ->will(call_user_func_array([$this, 'onConsecutiveCalls'], $statements));
    }

    private function expectQuery($query, array $return = [])
    {
        $this->expectQueries([[$query, $return]]);
    }

    public function testThatSimpleCountQueryIsCorrect()
    {
        $this->expectQuery('SELECT count(*) as count FROM test');

        $this->entityFinder->count();
    }

    public function testThatFiltersCanBeAppliedToCount()
    {
        $this->expectQuery('SELECT count(*) as count FROM test WHERE key=foo');

        $this->entityFinder->where('key=foo')->count();
    }

    public function testThatDeleteByPkUsesEqualSignForOne()
    {
        $this->expectQuery('DELETE FROM test WHERE key=?');

        $this->entityFinder->delete(5);
    }

    public function testThatDeleteByPkUsesInForMultiple()
    {
        $this->expectQuery('DELETE FROM test WHERE key IN(?, ?)');

        $this->entityFinder->delete(4, 5);
    }
}

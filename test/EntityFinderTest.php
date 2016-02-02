<?php

namespace ORMiny\Test;

use Annotiny\AnnotationReader;
use DBTiny\Driver;
use DBTiny\Driver\Statement;
use DBTiny\Platform\MySQL;
use ORMiny\Drivers\AnnotationMetadataDriver;
use ORMiny\EntityManager;

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
        $this->driver = $this->getMockBuilder(Driver::class)
                             ->disableOriginalConstructor()
                             ->setMethods(['getPlatform', 'query'])
                             ->getMockForAbstractClass();

        $this->driver->expects($this->any())
                     ->method('getPlatform')
                     ->will($this->returnValue($platform));

        $driver = new AnnotationMetadataDriver(new AnnotationReader());

        $this->entityManager = new EntityManager($this->driver, $driver);
        $this->entityManager->register('TestEntity', TestEntity::class);
        $this->entityManager->register('RelatedEntity', RelatedEntity::class);
        $this->entityManager->register('DeepRelationEntity', DeepRelationEntity::class);
        $this->entityManager->register('HasOneRelationEntity', HasOneRelationEntity::class);
        $this->entityManager->register('ManyManyRelationEntity', ManyManyRelationEntity::class);

        $this->entityFinder = $this->entityManager->find('TestEntity');
    }

    private function createMockStatement($return)
    {
        $mockStatement = $this->getMockBuilder(Statement::class)
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

        $this->entityManager->commit();
    }

    public function testThatDeleteByPkUsesInForMultiple()
    {
        $this->expectQuery('DELETE FROM test WHERE key IN(?, ?)');

        $this->entityFinder->delete(4, 5);

        $this->entityManager->commit();
    }

    public function testThatUpdateSetsFields()
    {
        $this->expectQuery('UPDATE test SET field2=? WHERE key=?');

        $this->entityFinder->where('key=?')->update(['field2' => 'foobar']);

        $this->entityManager->commit();
    }
}

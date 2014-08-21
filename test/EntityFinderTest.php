<?php

namespace Modules\ORM;

use Modules\Annotation\AnnotationReader;
use Modules\DBAL\Driver;
use Modules\DBAL\Driver\Statement;
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

    /**
     * @var Statement
     */
    private $mockStatement;

    public function setUp()
    {
        $platform     = new MySQL();
        $this->driver = $this->getMockBuilder('Modules\\DBAL\\Driver')
            ->disableOriginalConstructor()
            ->setMethods(['getPlatform'])
            ->getMockForAbstractClass();

        $this->mockStatement = $this->getMockBuilder('Modules\\DBAL\\Driver\\Statement')
            ->disableOriginalConstructor()
            ->setMethods(['fetchAll', 'fetch'])
            ->getMockForAbstractClass();

        $this->driver->expects($this->any())
            ->method('getPlatform')
            ->will($this->returnValue($platform));

        $this->driver->expects($this->any())
            ->method('query')
            ->will($this->returnValue($this->mockStatement));

        $this->entityManager = new EntityManager($this->driver, new AnnotationReader());
        $this->entityManager->register('TestEntity', 'Modules\\ORM\\TestEntity');
        $this->entityManager->register('RelatedEntity', 'Modules\\ORM\\RelatedEntity');
        $this->entityManager->register('DeepRelationEntity', 'Modules\\ORM\\DeepRelationEntity');
        $this->entityManager->register(
            'HasOneRelationEntity',
            'Modules\\ORM\\HasOneRelationEntity'
        );
        $this->entityManager->register(
            'ManyManyRelationEntity',
            'Modules\\ORM\\ManyManyRelationEntity'
        );

        $this->entityFinder = $this->entityManager->find('TestEntity');
    }

    private function expectQuery($query, array $return = [])
    {
        $this->driver->expects($this->once())
            ->method('query')
            ->with($this->equalTo($query));

        $this->mockStatement->expects($this->any())
            ->method('fetchAll')
            ->will(
                $this->returnValue($return)
            );

        $this->mockStatement->expects($this->any())
            ->method('fetch')
            ->will(
                call_user_func_array([$this, 'onConsecutiveCalls'], $return)
            );
    }

    public function testThatSimpleCountQueryIsCorrect()
    {
        $this->expectQuery('SELECT count(*) as count FROM test');

        $this->entityFinder->count();
    }

    public function testThatFiltersCanBeAppliedToCount()
    {
        $this->expectQuery('SELECT count(*) as count FROM test WHERE field=foo');

        $this->entityFinder->where('field=foo')->count();
    }

    public function testThatDeleteByPkUsesEqualSignForOne()
    {
        $this->expectQuery('DELETE FROM test WHERE field=?');

        $this->entityFinder->deleteByPk(5);
    }

    public function testThatDeleteByPkUsesInForMultiple()
    {
        $this->expectQuery('DELETE FROM test WHERE field IN(?, ?)');

        $this->entityFinder->deleteByPk([4, 5]);
    }
}

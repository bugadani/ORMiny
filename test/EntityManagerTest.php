<?php

namespace ORMiny\Test;

use Annotiny\AnnotationReader;
use DBTiny\Driver;
use DBTiny\Platform\MySQL;
use ORMiny\Drivers\AnnotationMetadataDriver;
use ORMiny\Entity;
use ORMiny\EntityManager;

class EntityManagerTest extends \PHPUnit_Framework_TestCase
{
    /**
     * @var EntityManager
     */
    private $entityManager;

    /**
     * @var Driver
     */
    private $driver;

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
        $this->entityManager->register('MultipleRelationEntity', MultipleRelationEntity::class);
        $this->entityManager->register('HasManyRelationEntity', HasManyRelationEntity::class);
        $this->entityManager->register('HasManyTargetEntity', HasManyTargetEntity::class);
    }

    public function testThatTheAppropriateEntityIsReturned()
    {
        $entity = $this->entityManager->getEntityForObject(new RelatedEntity());
        $this->assertInstanceOf(Entity::class, $entity);
        $this->assertEquals(RelatedEntity::class, $entity->getClassName());
    }
}

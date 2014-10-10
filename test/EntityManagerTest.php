<?php

namespace ORMiny;

use Modules\Annotation\AnnotationReader;
use Modules\DBAL\Driver;
use Modules\DBAL\Platform\MySQL;
use ORMiny\Drivers\AnnotationMetadataDriver;

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
        $this->driver = $this->getMockBuilder('Modules\\DBAL\\Driver')
            ->disableOriginalConstructor()
            ->setMethods(['getPlatform', 'query'])
            ->getMockForAbstractClass();

        $this->driver->expects($this->any())
            ->method('getPlatform')
            ->will($this->returnValue($platform));

        $driver = new AnnotationMetadataDriver(new AnnotationReader());

        $this->entityManager = new EntityManager($this->driver, $driver);
        $this->entityManager->register('TestEntity', 'ORMiny\\TestEntity');
        $this->entityManager->register('RelatedEntity', 'ORMiny\\RelatedEntity');
        $this->entityManager->register('DeepRelationEntity', 'ORMiny\\DeepRelationEntity');
        $this->entityManager->register('MultipleRelationEntity', 'ORMiny\\MultipleRelationEntity');
        $this->entityManager->register('HasOneRelationEntity', 'ORMiny\\HasOneRelationEntity');
        $this->entityManager->register('HasManyRelationEntity', 'ORMiny\\HasManyRelationEntity');
        $this->entityManager->register('HasManyTargetEntity', 'ORMiny\\HasManyTargetEntity');
        $this->entityManager->register('ManyManyRelationEntity', 'ORMiny\\ManyManyRelationEntity');
    }

    public function testThatTheAppropriateEntityIsReturned()
    {
        $entity = $this->entityManager->getEntityForObject(new RelatedEntity());
        $this->assertInstanceOf('ORMiny\\Entity', $entity);
        $this->assertEquals('ORMiny\\RelatedEntity', $entity->getMetadata()->getClassName());
    }
}

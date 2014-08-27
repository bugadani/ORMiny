<?php

namespace Modules\ORM;

use Modules\Annotation\AnnotationReader;
use Modules\DBAL\Driver;
use Modules\DBAL\Platform\MySQL;

class EntityTest extends \PHPUnit_Framework_TestCase
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

        $this->entityManager = new EntityManager($this->driver, new AnnotationReader());
        $this->entityManager->register('TestEntity', 'Modules\\ORM\\TestEntity');
        $this->entityManager->register('RelatedEntity', 'Modules\\ORM\\RelatedEntity');
        $this->entityManager->register('DeepRelationEntity', 'Modules\\ORM\\DeepRelationEntity');
        $this->entityManager->register(
            'HasOneRelationEntity',
            'Modules\\ORM\\HasOneRelationEntity'
        );
        $this->entityManager->register(
            'HasManyRelationEntity',
            'Modules\\ORM\\HasManyRelationEntity'
        );
        $this->entityManager->register(
            'HasManyTargetEntity',
            'Modules\\ORM\\HasManyTargetEntity'
        );
        $this->entityManager->register(
            'ManyManyRelationEntity',
            'Modules\\ORM\\ManyManyRelationEntity'
        );
    }

    private function createMockStatement(array $return)
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

    public function testCreate()
    {
        $entity = new Entity($this->entityManager, 'Modules\\ORM\\TestEntity', 'test');
        $entity->addField('field', 'key');
        $entity->setPrimaryKey('key');
        $object = $entity->create(['key' => 'value']);

        $this->assertInstanceOf('Modules\\ORM\\TestEntity', $object);
        $this->assertEquals('value', $object->field);
    }

    public function testCreateFromManager()
    {
        $entity = $this->entityManager->get('TestEntity');
        $object = $entity->create(
            [
                'key'             => 'value',
                'field2'          => 'value2',
                'fieldWithSetter' => 'foobar'
            ]
        );

        $this->assertEquals('value', $object->field);
        $this->assertEquals('foobar via setter and getter', $object->getField());

        $this->assertEquals(
            [
                'key'             => 'value',
                'field2'          => 'value2 via setter and getter',
                'fieldWithSetter' => 'foobar via setter and getter'
            ],
            $entity->toArray($object)
        );
    }

    public function testThatInsertIsCalledForRecordWithoutPrimaryKeySet()
    {
        $this->expectQuery('INSERT INTO test (fieldWithSetter, field2) VALUES (?, ?)');

        $entity = $this->entityManager->get('TestEntity');
        $object = $entity->create(
            [
                'key'             => null,
                'field2'          => 'value2',
                'fieldWithSetter' => 'foobar'
            ]
        );

        $entity->save($object);
    }

    public function testThatUpdateIsCalledForRecordsWithPrimaryKeySet()
    {
        $this->expectQuery('UPDATE test SET fieldWithSetter=?, field2=? WHERE key=?');

        $entity = $this->entityManager->get('TestEntity');
        $object = $entity->create(
            [
                'key'             => 'value',
                'field2'          => 'value2',
                'fieldWithSetter' => 'foobar'
            ]
        );

        $entity->save($object);
    }

    public function testThatUpdateCanSetNewPrimaryKey()
    {
        $this->expectQuery('UPDATE test SET key=?, fieldWithSetter=?, field2=? WHERE key=?');

        $entity = $this->entityManager->get('TestEntity');
        $object = $entity->create(
            [
                'key'             => 'value',
                'field2'          => 'value2',
                'fieldWithSetter' => 'foobar'
            ]
        );

        $object->field = 'foo';

        $entity->save($object);
    }

    public function testThatDeleteIsNotCalledForNewRecords()
    {
        $entity = $this->entityManager->get('TestEntity');
        $object = $entity->create();

        $this->driver->expects($this->never())
            ->method('query');

        $entity->delete($object);
    }

    public function testThatDeleteIsCalledForRecordsWithPkSet()
    {
        $this->expectQuery('DELETE FROM test WHERE key=?');

        $entity = $this->entityManager->get('TestEntity');
        $object = $entity->create(['key' => 'value']);

        $entity->delete($object);
    }

    public function testThatGetReturnsFalseWhenNoRecordIsReturned()
    {
        $this->expectQuery('SELECT pk, fk FROM hasOne WHERE pk=?');

        $entity = $this->entityManager->get('HasOneRelationEntity');
        $object = $entity->get(5);

        $this->assertFalse($object);
    }

    public function testThatGetReturnsEmptyArrayWhenNoRecordIsReturned()
    {
        $this->expectQuery('SELECT pk, fk FROM hasOne WHERE pk IN(?, ?)');

        $entity  = $this->entityManager->get('HasOneRelationEntity');
        $objects = $entity->get(5, 6);

        $this->assertEmpty($objects);
    }

    public function testThatGetConstructsQueryWithoutJoinForOne()
    {
        $this->expectQuery(
            'SELECT pk, fk FROM hasOne WHERE pk=?',
            [
                [
                    'pk' => 5,
                    'fk' => 1
                ]
            ]
        );

        $entity = $this->entityManager->get('HasOneRelationEntity');
        $object = $entity->get(5);

        $this->assertInstanceOf('Modules\\ORM\\HasOneRelationEntity', $object);
        $this->assertEquals(5, $object->pk);
        $this->assertEquals(1, $object->fk);
    }

    public function testThatGetConstructsTheRightQueryForMany()
    {
        $this->expectQuery(
            'SELECT pk, fk FROM hasOne WHERE pk IN(?, ?)',
            [
                [
                    'pk' => 5,
                    'fk' => 1
                ],
                [
                    'pk' => 6,
                    'fk' => 2
                ]
            ]
        );

        $objects = $this->entityManager->find('HasOneRelationEntity')->get(5, 6);
        $this->assertCount(2, $objects);

        $this->assertEquals(1, $objects[5]->fk);
        $this->assertEquals(2, $objects[6]->fk);
    }

    public function testGetSingleRecordWithRelated()
    {
        $this->expectQueries(
            [
                [
                    'SELECT hasOne.pk, hasOne.fk, hasOneRelation.primaryKey as hasOneRelation_primaryKey FROM hasOne' .
                    ' LEFT JOIN related hasOneRelation ON fk=hasOneRelation.primaryKey WHERE hasOne.pk IN(?, ?)',
                    [
                        [
                            'pk'                        => 5,
                            'fk'                        => 1,
                            'hasOneRelation_primaryKey' => 1
                        ],
                        [
                            'pk'                        => 6,
                            'fk'                        => 2,
                            'hasOneRelation_primaryKey' => 2
                        ]
                    ]
                ],
                ['UPDATE hasOne SET fk=? WHERE pk=?'],
                ['DELETE FROM related WHERE primaryKey=?'],
            ]
        );

        $entity = $this->entityManager
            ->get('HasOneRelationEntity');

        $objects = $entity
            ->find()
            ->with('hasOneRelation')
            ->get(5, 6);

        $this->assertCount(2, $objects);

        $this->assertEquals(5, $objects[5]->pk);
        $this->assertEquals(6, $objects[6]->pk);

        $this->assertInstanceOf('Modules\\ORM\\RelatedEntity', $objects[5]->relation);
        $this->assertInstanceOf('Modules\\ORM\\RelatedEntity', $objects[6]->relation);

        $this->assertNotSame($objects[5]->relation, $objects[6]->relation);

        $this->assertEquals(1, $objects[5]->relation->primaryKey);

        unset($objects[5]->relation);
        $entity->save($objects[5]);

        $this->assertNull($objects[5]->fk);
    }

    public function testGetSingleRecordWithDeepRelated()
    {
        $this->expectQuery(
            'SELECT deep.pk, deep.fk, relation.pk as relation_pk, relation.fk as relation_fk, relation_hasOneRelation.primaryKey as relation_hasOneRelation_primaryKey FROM deep ' .
            'LEFT JOIN hasOne relation ON fk=relation.pk ' .
            'LEFT JOIN related relation_hasOneRelation ON relation_fk=relation_hasOneRelation.primaryKey ' .
            'WHERE deep.pk=?',
            [
                [
                    'pk'                                 => 5,
                    'fk'                                 => 1,
                    'relation_pk'                        => 1,
                    'relation_fk'                        => 1,
                    'relation_hasOneRelation_primaryKey' => 1
                ]
            ]
        );

        $object = $this->entityManager
            ->find('DeepRelationEntity')
            ->with('relation.hasOneRelation')
            ->get(5);

        $this->assertInstanceOf('Modules\\ORM\\DeepRelationEntity', $object);
        $this->assertInstanceOf('Modules\\ORM\\HasOneRelationEntity', $object->relation[1]);
        $this->assertInstanceOf('Modules\\ORM\\RelatedEntity', $object->relation[1]->relation);

        $this->assertEquals(1, $object->relation[1]->relation->primaryKey);
    }

    public function testGetSingleRecordWithFilters()
    {
        $this->expectQuery(
            'SELECT pk, fk FROM deep WHERE pk = ? GROUP BY fk ORDER BY pk ASC LIMIT 3 OFFSET 2',
            [
                [
                    'pk' => 5,
                    'fk' => 1
                ]
            ]
        );

        $entityFinder = $this->entityManager->find('DeepRelationEntity');

        $object = $entityFinder
            ->where('pk = ' . $entityFinder->parameter(5))
            ->setFirstResult(2)
            ->setMaxResults(3)
            ->orderBy('pk', 'asc')
            ->groupBy('fk')
            ->get();

        $this->assertInstanceOf('Modules\\ORM\\DeepRelationEntity', $object[5]);

        $this->assertEquals(5, $object[5]->pk);
    }

    public function testThatConditionsAreAppliedToDelete()
    {
        $this->expectQuery('DELETE FROM test WHERE key=? LIMIT 2 OFFSET 1');

        $this->entityManager
            ->find('TestEntity')
            ->where('key=?')
            ->setFirstResult(1)
            ->setMaxResults(2)
            ->delete();
    }

    public function testThatJoinTableIsAddedProperlyToManyManyRelations()
    {
        $this->expectQuery(
            'SELECT many_many.pk, many_many.fk, relation.primaryKey as relation_primaryKey FROM many_many ' .
            'LEFT JOIN many_many_related ON many_many.fk=many_many_related.many_many_fk ' .
            'LEFT JOIN related relation ON many_many_related.related_primaryKey=relation.primaryKey ' .
            'WHERE pk=?',
            [
                [
                    'pk'                  => 1,
                    'fk'                  => 1,
                    'relation_primaryKey' => 1
                ],
                [
                    'pk'                  => 1,
                    'fk'                  => 1,
                    'relation_primaryKey' => 2
                ],
                [
                    'pk'                  => 2,
                    'fk'                  => 1,
                    'relation_primaryKey' => 1
                ],
                [
                    'pk'                  => 2,
                    'fk'                  => 1,
                    'relation_primaryKey' => 2
                ]
            ]
        );

        $objects = $this->entityManager
            ->find('ManyManyRelationEntity')
            ->where('pk=?')
            ->with('relation')
            ->get();

        $this->assertCount(2, $objects);
    }

    public function testDeletingNonexistentRecordWithRelationsOnlyPerformsSelect()
    {
        $this->expectQueries(
            [
                [
                    'SELECT many_many.pk, many_many.fk, relation.primaryKey as relation_primaryKey FROM many_many ' .
                    'LEFT JOIN many_many_related ON many_many.fk=many_many_related.many_many_fk ' .
                    'LEFT JOIN related relation ON many_many_related.related_primaryKey=relation.primaryKey ' .
                    'WHERE many_many.pk=?',
                    []
                ]
            ]
        );
        $this->entityManager
            ->find('ManyManyRelationEntity')
            ->delete(2);
    }

    public function testDeletingRecordWithRelations()
    {
        $this->expectQueries(
            [
                [
                    'SELECT many_many.pk, many_many.fk, relation.primaryKey as relation_primaryKey FROM many_many ' .
                    'LEFT JOIN many_many_related ON many_many.fk=many_many_related.many_many_fk ' .
                    'LEFT JOIN related relation ON many_many_related.related_primaryKey=relation.primaryKey ' .
                    'WHERE many_many.pk=?',
                    [
                        [
                            'pk'                  => 1,
                            'fk'                  => 1,
                            'relation_primaryKey' => 1
                        ],
                        [
                            'pk'                  => 1,
                            'fk'                  => 1,
                            'relation_primaryKey' => 2
                        ]
                    ],
                ],
                ['DELETE FROM many_many_related WHERE many_many_fk IN(?, ?)'],
                ['DELETE FROM many_many WHERE pk=?']
            ]
        );
        $this->entityManager
            ->find('ManyManyRelationEntity')
            ->delete(2);
    }

    public function testInsertAndUpdateSimpleRecord()
    {
        //field2 is set because its getter returns a value
        $this->expectQueries(
            [
                ['INSERT INTO test (fieldWithSetter, field2) VALUES (?, ?)'],
                ['UPDATE test SET field2=? WHERE key=?']
            ]
        );

        $entity = $this->entityManager->get('TestEntity');
        $object = $entity->create(
            [
                'fieldWithSetter' => 'foo'
            ]
        );
        $entity->save($object);

        $object->setField2('bar');

        $entity->save($object);
    }

    public function testUpdateRecordWithManyToManyRelation()
    {
        //field2 is set because its getter returns a value
        $this->expectQueries(
            [
                [
                    'SELECT many_many.pk, many_many.fk, relation.primaryKey as relation_primaryKey FROM many_many ' .
                    'LEFT JOIN many_many_related ON many_many.fk=many_many_related.many_many_fk ' .
                    'LEFT JOIN related relation ON many_many_related.related_primaryKey=relation.primaryKey ' .
                    'WHERE many_many.pk=?',
                    [
                        [
                            'pk'                  => 1,
                            'fk'                  => 1,
                            'relation_primaryKey' => 1
                        ],
                        [
                            'pk'                  => 1,
                            'fk'                  => 1,
                            'relation_primaryKey' => 2
                        ]
                    ]
                ],
                ['INSERT INTO related () VALUES ()'],
                ['DELETE FROM many_many_related WHERE (many_many_fk=? AND related_primaryKey=?)'],
                ['INSERT INTO many_many_related (many_many_fk, related_primaryKey) VALUES (?, ?)']
            ]
        );

        $entity = $this->entityManager->get('ManyManyRelationEntity');
        $object = $entity->find()->with('relation')->get(2);

        unset($object->relation[1]);

        $object->relation[] = new RelatedEntity();

        $entity->save($object);
    }

    public function testUpdateRecordWithHasManyRelation()
    {
        $this->expectQueries(
            [
                [
                    'SELECT has_many.pk, relation.primaryKey as relation_primaryKey, ' .
                    'relation.foreignKey as relation_foreignKey FROM has_many ' .
                    'LEFT JOIN related relation ON pk=relation.foreignKey WHERE has_many.pk=?',
                    [
                        [
                            'pk'                  => 1,
                            'relation_primaryKey' => 1,
                            'relation_foreignKey' => 1
                        ],
                        [
                            'pk'                  => 1,
                            'relation_primaryKey' => 2,
                            'relation_foreignKey' => 1
                        ],
                        [
                            'pk'                  => 1,
                            'relation_primaryKey' => 3,
                            'relation_foreignKey' => 1
                        ]
                    ]
                ],
                ['UPDATE related SET primaryKey=? WHERE primaryKey=?'],
                ['DELETE FROM related WHERE primaryKey=?'],
                ['DELETE FROM related WHERE primaryKey IN(?, ?)'],
                ['DELETE FROM has_many WHERE pk=?']
            ]
        );

        $entity = $this->entityManager->get('HasManyRelationEntity');
        $object = $entity->find()->with('relation')->get(2);

        $this->assertInstanceOf('Modules\\ORM\\HasManyRelationEntity', $object);
        $this->assertCount(3, $object->relation);
        $this->assertContainsOnly('Modules\\ORM\\HasManyTargetEntity', $object->relation);

        unset($object->relation[1]);
        $object->relation[2]->primaryKey = 5;

        $entity->save($object);
        $entity->delete($object);
    }

    public function testThatLimitIsNotAppliedWhenTablesAreJoined()
    {
        $this->expectQuery(
            'SELECT many_many.pk, many_many.fk, relation.primaryKey as relation_primaryKey FROM many_many ' .
            'LEFT JOIN many_many_related ON many_many.fk=many_many_related.many_many_fk ' .
            'LEFT JOIN related relation ON many_many_related.related_primaryKey=relation.primaryKey',
            [
                [
                    'pk'                  => 1,
                    'fk'                  => 1,
                    'relation_primaryKey' => 1
                ],
                [
                    'pk'                  => 1,
                    'fk'                  => 2,
                    'relation_primaryKey' => 2
                ],
                [
                    'pk'                  => 2,
                    'fk'                  => 1,
                    'relation_primaryKey' => 1
                ],
                [
                    'pk'                  => 2,
                    'fk'                  => 2,
                    'relation_primaryKey' => 2
                ],
                [
                    'pk'                  => 2,
                    'fk'                  => 3,
                    'relation_primaryKey' => 3
                ],
                [
                    'pk'                  => 3,
                    'fk'                  => 1,
                    'relation_primaryKey' => 1
                ],
                [
                    'pk'                  => 4,
                    'fk'                  => 1,
                    'relation_primaryKey' => 1
                ],
                [
                    'pk'                  => 4,
                    'fk'                  => 2,
                    'relation_primaryKey' => 2
                ],
                [
                    'pk'                  => 4,
                    'fk'                  => 3,
                    'relation_primaryKey' => 3
                ],
                [
                    'pk'                  => 5,
                    'fk'                  => 1,
                    'relation_primaryKey' => 1
                ],
            ]
        );

        $entity  = $this->entityManager->get('ManyManyRelationEntity');
        $objects = $entity->find()
            ->with('relation')
            ->setFirstResult(2)
            ->setMaxResults(3)
            ->get();

        $this->assertCount(3, $objects);

        $this->assertCount(1, $objects[3]->relation);
        $this->assertCount(3, $objects[4]->relation);
        $this->assertCount(1, $objects[5]->relation);
    }
}

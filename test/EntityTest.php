<?php

namespace ORMiny\Test;

use Annotiny\AnnotationReader;
use DBTiny\Driver;
use DBTiny\Driver\Statement;
use DBTiny\Platform\MySQL;
use ORMiny\Entity;
use ORMiny\EntityManager;
use ORMiny\Metadata\Field;
use ORMiny\Drivers\AnnotationMetadataDriver;
use ORMiny\Metadata\Getter\PropertyGetter;
use ORMiny\Metadata\Setter\PropertySetter;

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

    private function createMockStatement(array $return)
    {
        $mockStatement = $this->getMockBuilder(Statement::class)
                              ->disableOriginalConstructor()
                              ->setMethods(['fetchAll', 'fetch', 'rowCount'])
                              ->getMockForAbstractClass();

        $mockStatement->expects($this->any())
                      ->method('rowCount')
                      ->will($this->returnValue(count($return)));

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
                $query = [$query, [], []];
            } else {
                if (!isset($query[1])) {
                    $query[1] = [];
                }
                if (!isset($query[2])) {
                    $query[2] = [];
                }
            }
            $queryMatchers[] = [$this->equalTo($query[0]), $this->equalTo($query[1])];
            $statements[]    = $this->createMockStatement($query[2]);
        }

        $driverExpect = $this->driver
            ->expects($this->exactly(count($queries)))
            ->method('query');

        call_user_func_array([$driverExpect, 'withConsecutive'], $queryMatchers)
            ->will(call_user_func_array([$this, 'onConsecutiveCalls'], $statements));
    }

    private function expectQuery($query, array $params = [], array $return = [])
    {
        $this->expectQueries([[$query, $params, $return]]);
    }

    public function testCreate()
    {
        $entity   = new Entity($this->entityManager, TestEntity::class);
        $entity->setTable('test');
        $entity->addField('key', new Field(new PropertySetter($entity, 'field'), new PropertyGetter($entity, 'field')));
        $entity->setPrimaryKey('key');

        $object = $entity->create(['key' => 'value']);

        $this->assertInstanceOf(TestEntity::class, $object);
        $this->assertEquals('value', $object->field);
    }

    public function testCreateFromManager()
    {
        $entity = $this->entityManager->get('TestEntity');
        /** @var TestEntity $object */
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

    public function testThatSelectCommits()
    {
        $this->expectQueries(
            [
                [
                    'INSERT INTO test (fieldWithSetter, field2) VALUES (?, ?)',
                    [
                        'foobar via setter and getter',
                        'value2 via setter and getter'
                    ]
                ],
                [
                    'SELECT key, fieldWithSetter, field2 FROM test WHERE key=?',
                    [
                        5
                    ]
                ]
            ]
        );

        $entity = $this->entityManager->get('TestEntity');
        $object = $entity->create(
            [
                'field2'          => 'value2',
                'fieldWithSetter' => 'foobar'
            ]
        );

        $entity->save($object);

        $entity->get(5);
    }

    public function testThatInsertIsCalledForRecordWithoutPrimaryKeySet()
    {
        $this->expectQuery(
            'INSERT INTO test (fieldWithSetter, field2) VALUES (?, ?)',
            ['foobar via setter and getter', 'value2 via setter and getter']
        );

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

    public function testThatUpdateIsCalledForRecordsThatExistInDatabaseWhenPrimaryKeyIsSet()
    {
        $this->expectQueries(
            [
                [
                    'SELECT key FROM test WHERE key=?',
                    ['value'],
                    [
                        [
                            'key' => 'value'
                        ]
                    ]
                ],
                [
                    'UPDATE test SET fieldWithSetter=?, field2=? WHERE key=?',
                    [
                        'foobar via setter and getter',
                        'value2 via setter and getter',
                        'value'
                    ]
                ]
            ]
        );

        $entity = $this->entityManager->get('TestEntity');
        $object = $entity->create(
            [
                'key'             => 'value',
                'field2'          => 'value2',
                'fieldWithSetter' => 'foobar'
            ]
        );

        $entity->save($object);

        $this->entityManager->commit();
    }

    public function testThatUpdateCanSetNewPrimaryKey()
    {
        $this->expectQueries(
            [
                [
                    'SELECT key FROM test WHERE key=?',
                    ['value'],
                    [
                        [
                            'key' => 'value'
                        ]
                    ]
                ],
                [
                    'UPDATE test SET key=?, fieldWithSetter=?, field2=? WHERE key=?',
                    [
                        'foo',
                        'foobar via setter and getter',
                        'value2 via setter and getter',
                        'value'
                    ]
                ]
            ]
        );

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

        $this->entityManager->commit();
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
        $this->expectQuery('DELETE FROM test WHERE key=?', ['value']);

        $entity = $this->entityManager->get('TestEntity');
        $object = $entity->create(['key' => 'value']);

        $entity->delete($object);

        $this->entityManager->commit();
    }

    public function testThatGetReturnsFalseWhenNoRecordIsReturned()
    {
        $this->expectQuery('SELECT pk, fk FROM hasOne WHERE pk=?', [5]);

        $entity = $this->entityManager->get('HasOneRelationEntity');
        $object = $entity->get(5);

        $this->assertFalse($object);
    }

    public function testThatGetReturnsEmptyArrayWhenNoRecordIsReturned()
    {
        $this->expectQuery('SELECT pk, fk FROM hasOne WHERE pk IN(?, ?)', [5, 6]);

        $entity  = $this->entityManager->get('HasOneRelationEntity');
        $objects = $entity->get(5, 6);

        $this->assertEmpty($objects);
    }

    public function testThatGetConstructsQueryWithoutJoinForOne()
    {
        $this->expectQuery(
            'SELECT pk, fk FROM hasOne WHERE pk=?',
            [5],
            [
                [
                    'pk' => 5,
                    'fk' => 1
                ]
            ]
        );

        $entity = $this->entityManager->get('HasOneRelationEntity');
        $object = $entity->get(5);

        $this->assertInstanceOf(HasOneRelationEntity::class, $object);
        $this->assertEquals(5, $object->pk);
        $this->assertEquals(1, $object->fk);
    }

    public function testThatGetConstructsTheRightQueryForMany()
    {
        $this->expectQuery(
            'SELECT pk, fk FROM hasOne WHERE pk IN(?, ?)',
            [5, 6],
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
                    ' LEFT JOIN related hasOneRelation ON hasOne.fk=hasOneRelation.primaryKey WHERE hasOne.pk IN(?, ?)',
                    [5, 6],
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
                ['DELETE FROM related WHERE primaryKey=?', [1]],
                ['UPDATE hasOne SET fk=? WHERE pk=?', [null, 5]],
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

        $this->assertInstanceOf(RelatedEntity::class, $objects[5]->relation);
        $this->assertInstanceOf(RelatedEntity::class, $objects[6]->relation);

        $this->assertNotSame($objects[5]->relation, $objects[6]->relation);

        $this->assertEquals(1, $objects[5]->relation->primaryKey);

        unset($objects[5]->relation);
        $entity->save($objects[5]);

        $this->assertNull($objects[5]->fk);
        $this->entityManager->commit();
    }

    public function testGetSingleRecordWithDeepRelated()
    {
        $this->expectQuery(
            'SELECT deep.pk, deep.fk, relation.pk as relation_pk, relation.fk as relation_fk, relation_hasOneRelation.primaryKey as relation_hasOneRelation_primaryKey FROM deep ' .
            'LEFT JOIN hasOne relation ON deep.fk=relation.pk ' .
            'LEFT JOIN related relation_hasOneRelation ON relation.fk=relation_hasOneRelation.primaryKey ' .
            'WHERE deep.pk=?',
            [5],
            [
                [
                    'pk'                                 => 5,
                    'fk'                                 => 1,
                    'relation_pk'                        => 1,
                    'relation_fk'                        => 3,
                    'relation_hasOneRelation_primaryKey' => 3
                ]
            ]
        );

        $object = $this->entityManager
            ->find('DeepRelationEntity')
            ->with('relation.hasOneRelation')
            ->get(5);

        $this->assertInstanceOf(DeepRelationEntity::class, $object);
        $this->assertInstanceOf(HasOneRelationEntity::class, $object->relation[1]);
        $this->assertInstanceOf(RelatedEntity::class, $object->relation[1]->relation);

        $this->assertEquals(3, $object->relation[1]->fk);
        $this->assertEquals(3, $object->relation[1]->relation->primaryKey);
    }

    public function testGetSingleRecordWithFilters()
    {
        $this->expectQuery(
            'SELECT pk, fk FROM deep WHERE pk = ? GROUP BY fk ORDER BY pk ASC LIMIT 3 OFFSET 2',
            [5],
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

        $this->assertInstanceOf(DeepRelationEntity::class, $object[5]);

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

        $this->entityManager->commit();
    }

    public function testThatJoinTableIsAddedProperlyToManyManyRelations()
    {
        $this->expectQuery(
            'SELECT many_many.pk, many_many.fk, relation.primaryKey as relation_primaryKey FROM many_many ' .
            'LEFT JOIN joinTable ON many_many.fk=joinTable.many_many_fk ' .
            'LEFT JOIN related relation ON joinTable.related_primaryKey=relation.primaryKey ' .
            'WHERE pk=?',
            [],
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


    public function testThatManyManyRelationsSetPropertyToEmptyArray()
    {
        $this->expectQuery(
            'SELECT many_many.pk, many_many.fk, relation.primaryKey as relation_primaryKey FROM many_many ' .
            'LEFT JOIN joinTable ON many_many.fk=joinTable.many_many_fk ' .
            'LEFT JOIN related relation ON joinTable.related_primaryKey=relation.primaryKey ' .
            'WHERE many_many.pk=?',
            [1],
            [
                [
                    'pk'                  => 6,
                    'fk'                  => 8,
                    'relation_primaryKey' => null
                ]
            ]
        );

        $object = $this->entityManager
            ->find('ManyManyRelationEntity')
            ->with('relation')
            ->getByPrimaryKey(1);

        $this->assertInternalType('array', $object->relation);
        $this->assertEmpty($object->relation);
    }

    public function testDeletingNonexistentRecordWithRelationsOnlyPerformsSelect()
    {
        $this->expectQueries(
            [
                [
                    'SELECT many_many.pk, many_many.fk, relation.primaryKey as relation_primaryKey FROM many_many ' .
                    'LEFT JOIN joinTable ON many_many.fk=joinTable.many_many_fk ' .
                    'LEFT JOIN related relation ON joinTable.related_primaryKey=relation.primaryKey ' .
                    'WHERE many_many.pk=?',
                    [2],
                    []
                ]
            ]
        );
        $this->entityManager
            ->find('ManyManyRelationEntity')
            ->delete(2);

        $this->entityManager->commit();
    }

    public function testDeletingRecordWithRelations()
    {
        $this->expectQueries(
            [
                [
                    'SELECT many_many.pk, many_many.fk, relation.primaryKey as relation_primaryKey FROM many_many ' .
                    'LEFT JOIN joinTable ON many_many.fk=joinTable.many_many_fk ' .
                    'LEFT JOIN related relation ON joinTable.related_primaryKey=relation.primaryKey ' .
                    'WHERE many_many.pk=?',
                    [2],
                    [
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
                    ],
                ],
                ['DELETE FROM joinTable WHERE many_many_fk=?', [1]],
                ['DELETE FROM many_many WHERE pk=?', [2]]
            ]
        );
        $this->entityManager
            ->find('ManyManyRelationEntity')
            ->delete(2);

        $this->entityManager->commit();
    }

    public function testInsertAndUpdateSimpleRecord()
    {
        //field2 is set because its getter returns a value
        $this->expectQueries(
            [
                [
                    'INSERT INTO test (fieldWithSetter, field2) VALUES (?, ?)',
                    ['foo via setter and getter', ' and getter']
                ],
                ['UPDATE test SET field2=? WHERE key=?', ['bar via setter and getter', null]]
            ]
        );

        $entity = $this->entityManager->get('TestEntity');
        /** @var TestEntity $object */
        $object = $entity->create(
            [
                'fieldWithSetter' => 'foo'
            ]
        );
        $entity->save($object);

        $object->setField2('bar');

        $entity->save($object);

        $this->entityManager->commit();
    }

    public function testUpdateRecordWithHasOneRelation()
    {
        //field2 is set because its getter returns a value
        $this->expectQueries(
            [
                [
                    'SELECT hasOne.pk, hasOne.fk, hasOneRelation.primaryKey as hasOneRelation_primaryKey FROM hasOne' .
                    ' LEFT JOIN related hasOneRelation ON hasOne.fk=hasOneRelation.primaryKey WHERE hasOne.pk=?',
                    [2],
                    [
                        [
                            'pk'                        => 1,
                            'fk'                        => null,
                            'hasOneRelation_primaryKey' => null
                        ]
                    ]
                ],
                [
                    'SELECT primaryKey FROM related WHERE primaryKey=?',
                    [2],
                    [
                        [
                            'primaryKey' => 2
                        ]
                    ]
                ],
                ['UPDATE hasOne SET fk=? WHERE pk=?', [2, 1]]
            ]
        );

        $entity = $this->entityManager->get('HasOneRelationEntity');
        $object = $entity->find()->with('hasOneRelation')->get(2);

        $this->assertNull($object->relation);

        $object->relation = $this->entityManager
            ->get('RelatedEntity')
            ->create(['primaryKey' => 2]);

        $entity->save($object);

        $this->entityManager->commit();
    }

    public function testUpdateRecordWithManyToManyRelation()
    {
        //field2 is set because its getter returns a value
        $this->expectQueries(
            [
                [
                    'SELECT many_many.pk, many_many.fk, relation.primaryKey as relation_primaryKey FROM many_many ' .
                    'LEFT JOIN joinTable ON many_many.fk=joinTable.many_many_fk ' .
                    'LEFT JOIN related relation ON joinTable.related_primaryKey=relation.primaryKey ' .
                    'WHERE many_many.pk=?',
                    [2],
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
                ['DELETE FROM joinTable WHERE (many_many_fk=? AND related_primaryKey=?)', [1, 1]],
                ['INSERT INTO joinTable (many_many_fk, related_primaryKey) VALUES (?, ?)', [1, 3]],
                [
                    'INSERT INTO joinTable (many_many_fk, related_primaryKey) VALUES (?, ?)',
                    [1, null]
                ]
            ]
        );

        $entity = $this->entityManager->get('ManyManyRelationEntity');
        $object = $entity->find()->with('relation')->get(2);

        unset($object->relation[1]);

        $object->relation[] = 3;
        $object->relation[] = new RelatedEntity;

        $entity->save($object);

        $this->entityManager->commit();
    }

    public function testTableNameAliasIsUsed()
    {
        $this->expectQuery(
            'SELECT alias.pk, relation.primaryKey as relation_primaryKey, ' .
            'relation.foreignKey as relation_foreignKey FROM has_many alias ' .
            'LEFT JOIN related relation ON alias.pk=relation.foreignKey WHERE alias.pk=?',
            [2]
        );

        $this->entityManager->find('HasManyRelationEntity', 'alias')->with('relation')->get(2);
    }

    public function testUpdateAndDeleteQueriesDontGetExecutedWithoutCommit()
    {
        $this->expectQueries(
            [
                [
                    'SELECT has_many.pk, relation.primaryKey as relation_primaryKey, ' .
                    'relation.foreignKey as relation_foreignKey FROM has_many ' .
                    'LEFT JOIN related relation ON has_many.pk=relation.foreignKey WHERE has_many.pk=?',
                    [2],
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
                ]
            ]
        );

        $entity = $this->entityManager->get('HasManyRelationEntity');
        $object = $entity->find()->with('relation')->get(2);

        $this->assertInstanceOf(HasManyRelationEntity::class, $object);
        $this->assertCount(3, $object->relation);
        $this->assertContainsOnly(HasManyTargetEntity::class, $object->relation);

        unset($object->relation[1]);
        $object->relation[2]->primaryKey = 5;

        $entity->save($object);
        $entity->delete($object);
    }

    public function testUpdateRecordWithHasManyRelation()
    {
        $this->expectQueries(
            [
                [
                    'SELECT has_many.pk, relation.primaryKey as relation_primaryKey, ' .
                    'relation.foreignKey as relation_foreignKey FROM has_many ' .
                    'LEFT JOIN related relation ON has_many.pk=relation.foreignKey WHERE has_many.pk=?',
                    [2],
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
                ['UPDATE related SET primaryKey=? WHERE primaryKey=?', [5, 2]],
                ['DELETE FROM related WHERE primaryKey=?', [1]],
                ['DELETE FROM related WHERE foreignKey=?', [1]],
                ['DELETE FROM has_many WHERE pk=?', [1]]
            ]
        );

        $entity = $this->entityManager->get('HasManyRelationEntity');
        $object = $entity->find()->with('relation')->get(2);

        $this->assertInstanceOf(HasManyRelationEntity::class, $object);
        $this->assertCount(3, $object->relation);
        $this->assertContainsOnly(HasManyTargetEntity::class, $object->relation);

        unset($object->relation[1]);
        $object->relation[2]->primaryKey = 5;

        $entity->save($object);
        $entity->delete($object);

        $this->entityManager->commit();
    }

    public function testThatLimitIsNotAppliedWhenTablesAreJoined()
    {
        $this->expectQuery(
            'SELECT many_many.pk, many_many.fk, relation.primaryKey as relation_primaryKey FROM many_many ' .
            'LEFT JOIN joinTable ON many_many.fk=joinTable.many_many_fk ' .
            'LEFT JOIN related relation ON joinTable.related_primaryKey=relation.primaryKey',
            [],
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

    public function testLoadRelation()
    {
        $this->expectQuery(
            'SELECT primaryKey, foreignKey FROM related WHERE foreignKey=?',
            [1],
            [
                [
                    'primaryKey' => 1,
                    'foreignKey' => 1
                ],
                [
                    'primaryKey' => 2,
                    'foreignKey' => 1
                ]
            ]
        );


        $entity = $this->entityManager->get('HasManyRelationEntity');
        $object = $entity->create(
            [
                'pk' => 1
            ]
        );

        $entity->loadRelation($object, 'relation');

        $this->assertCount(2, $object->relation);
    }

    public function testUpdateReceivesParametersInCorrectOrder()
    {
        $this->expectQuery(
            'UPDATE related SET foreignKey=? WHERE foreignKey=?',
            [2, 1]
        );
        $entity       = $this->entityManager->get('HasManyTargetEntity');
        $entityFinder = $entity->find();
        $entityFinder->where(
            $entity->expression()->eq(
                'foreignKey',
                $entityFinder->parameter(1)
            )
        )->update(['foreignKey' => 2]);

        $this->entityManager->commit();
    }

    public function testSettingBelongsToRelationObjectUpdatesForeignKey()
    {
        $this->expectQueries(
            [
                [
                    'SELECT primaryKey, foreignKey FROM related WHERE primaryKey=?',
                    [2],
                    [
                        [
                            'primaryKey' => 2,
                            'foreignKey' => null
                        ]
                    ]
                ],
                [
                    'UPDATE related SET foreignKey=? WHERE primaryKey=?',
                    [1, 2]
                ]
            ]
        );

        $entity         = $this->entityManager->get('HasManyTargetEntity');
        $relationEntity = $this->entityManager->get('HasManyRelationEntity');
        /** @var HasManyTargetEntity $object */
        $object = $entity->get(2);

        $object->belongs = $relationEntity->create(['pk' => 1], true);

        $entity->save($object);

        $this->entityManager->commit();
    }

    public function testEntityWithMultipleRelations()
    {
        $this->expectQueries(
            [
                [
                    'SELECT multiple.pk, multiple.fk, multiple.fk2, relation.pk as relation_pk, relation.fk as relation_fk, ' .
                    'deepRelation.pk as deepRelation_pk, deepRelation.fk as deepRelation_fk, deepRelation_relation.pk as deepRelation_relation_pk, ' .
                    'deepRelation_relation.fk as deepRelation_relation_fk, ' .
                    'deepRelation_relation_hasOneRelation.primaryKey as deepRelation_relation_hasOneRelation_primaryKey ' .
                    'FROM multiple LEFT JOIN hasOne relation ON multiple.fk=relation.pk ' .
                    'LEFT JOIN deep deepRelation ON multiple.fk2=deepRelation.pk ' .
                    'LEFT JOIN hasOne deepRelation_relation ON deepRelation.fk=deepRelation_relation.pk ' .
                    'LEFT JOIN related deepRelation_relation_hasOneRelation ON deepRelation_relation.fk=deepRelation_relation_hasOneRelation.primaryKey ' .
                    'WHERE multiple.pk=?',
                    [3],
                    [
                        [
                            'pk'                                              => 3,
                            'fk'                                              => 2,
                            'fk2'                                             => 5,
                            'relation_pk'                                     => 2,
                            'relation_fk'                                     => 4,
                            'deepRelation_pk'                                 => 5,
                            'deepRelation_fk'                                 => 6,
                            'deepRelation_relation_pk'                        => 6,
                            'deepRelation_relation_fk'                        => 4,
                            'deepRelation_relation_hasOneRelation_primaryKey' => 4,
                            'deepRelation_relation_hasOneRelation_foreignKey' => 8
                        ]
                    ]
                ],
                ['INSERT INTO multiple () VALUES ()']
            ]
        );
        $entity = $this->entityManager->get('MultipleRelationEntity');
        $entity->find()->with('relation', 'deepRelation.relation.hasOneRelation')->get(3);

        $entity->save($entity->create());
    }

    public function testAliasIsAppliedForEntityWithMultipleRelations()
    {
        $this->expectQuery(
            'SELECT alias.pk, alias.fk, alias.fk2, relation.pk as relation_pk, relation.fk as relation_fk, ' .
            'deepRelation.pk as deepRelation_pk, deepRelation.fk as deepRelation_fk, deepRelation_relation.pk as deepRelation_relation_pk, ' .
            'deepRelation_relation.fk as deepRelation_relation_fk, ' .
            'deepRelation_relation_hasOneRelation.primaryKey as deepRelation_relation_hasOneRelation_primaryKey ' .
            'FROM multiple alias LEFT JOIN hasOne relation ON alias.fk=relation.pk ' .
            'LEFT JOIN deep deepRelation ON alias.fk2=deepRelation.pk ' .
            'LEFT JOIN hasOne deepRelation_relation ON deepRelation.fk=deepRelation_relation.pk ' .
            'LEFT JOIN related deepRelation_relation_hasOneRelation ON deepRelation_relation.fk=deepRelation_relation_hasOneRelation.primaryKey ' .
            'WHERE alias.pk=?',
            [3]
        );
        $entity = $this->entityManager->get('MultipleRelationEntity');
        $entity->find('alias')->with('relation', 'deepRelation.relation.hasOneRelation')->get(3);
    }

    public function testParameterOrderIsCorrect()
    {
        $this->expectQuery(
            'SELECT pk, fk FROM hasOne WHERE (a=?) AND pk IN(?, ?)',
            [2, 5, 6],
            []
        );

        $entity = $this->entityManager->get('HasOneRelationEntity');
        $finder = $entity->find();
        $finder->where($entity->expression()->eq('a', $finder->parameter(2)))->get(5, 6);
    }
}

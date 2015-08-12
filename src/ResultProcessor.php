<?php

/**
 * This file is part of the ORMiny library.
 * (c) Dániel Buga <bugadani@gmail.com>
 *
 * For licensing information see the LICENSE file.
 */

namespace ORMiny;

class ResultProcessor
{
    /**
     * @var EntityManager
     */
    private $manager;

    public function __construct(EntityManager $manager)
    {
        $this->manager = $manager;
    }

    public function processRecords(Entity $entity, array $with, $records, $readOnly)
    {
        $pkField = $entity->getPrimaryKey();

        $objects          = [];
        $currentKey       = null;
        $object           = null;
        $recordsToProcess = [];

        $relations = array_map(
            [$entity, 'getRelation'],
            array_intersect($with, $entity->getRelationNames())
        );

        foreach ($records as $record) {
            //Extract columns that are relevant for the current metadata
            $key = $record[ $pkField ];
            if ($currentKey !== $key) {
                if ($object !== null) {
                    //Process and save the previous object
                    $this->processRelated(
                        $entity,
                        $object,
                        $readOnly,
                        $recordsToProcess,
                        $relations,
                        $with
                    );
                    $recordsToProcess       = [];
                    $objects[ $currentKey ] = $object;
                }
                $currentKey = $key;
                $object     = $this->createObject(
                    $entity,
                    $record,
                    $relations,
                    $readOnly
                );
            }
            //Store the record to be processed for the related entities
            $recordsToProcess[] = $record;
        }
        if ($object !== null) {
            //Process and save the last object
            $this->processRelated(
                $entity,
                $object,
                $readOnly,
                $recordsToProcess,
                $relations,
                $with
            );
            $objects[ $key ] = $object;
        }

        return $objects;
    }

    /**
     * @param Entity              $entity
     * @param array               $record
     * @param Metadata\Relation[] $relations
     * @param                     $readOnly
     *
     * @return mixed
     */
    private function createObject(Entity $entity, array $record, array $relations, $readOnly)
    {
        $fields = $entity->getFieldNames();

        $object = $entity->create(array_intersect_key($record, $fields), true);
        $entity->setReadOnly($object, $readOnly);

        foreach ($relations as $relation) {
            $relation->set($object, $relation->getEmptyValue());
        }

        return $object;
    }

    /**
     * @param Entity              $entity
     * @param                     $object
     * @param                     $readOnly
     * @param array               $records
     * @param Metadata\Relation[] $relations
     * @param array               $with
     */
    private function processRelated(Entity $entity, $object, $readOnly, array $records, array $relations, array $with)
    {
        if (empty($records)) {
            return;
        }
        foreach ($relations as $relation) {
            $relationName  = $relation->getRelationName();

            $value = $this->processRecords(
                $relation->getEntity(),
                Utils::filterPrefixedElements($with, $relationName . '.', Utils::FILTER_REMOVE_PREFIX),
                $this->stripRelationPrefix($records, $relationName . '_'),
                $readOnly
            );

            if ($relation->isSingle()) {
                $value = current($value);
            }

            if (!empty($value)) {
                $entity->setRelationValue($object, $relationName, $value);
            }
        }
    }

    /**
     * @param array  $records
     * @param string $prefix
     *
     * @return \ArrayIterator
     */
    private function stripRelationPrefix(array $records, $prefix)
    {
        //Strip the relation prefix from the columns

        return array_map(
            function ($rawRecord) use ($prefix) {
                return Utils::filterPrefixedElements(
                    $rawRecord,
                    $prefix,
                    Utils::FILTER_REMOVE_PREFIX | Utils::FILTER_USE_KEYS
                );
            },
            $records
        );
    }
}

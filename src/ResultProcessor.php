<?php

/**
 * This file is part of the ORMiny library.
 * (c) Dániel Buga <bugadani@gmail.com>
 *
 * For licensing information see the LICENSE file.
 */

namespace ORMiny;

use ORMiny\Annotations\Relation;

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
        $metadata = $entity->getMetadata();
        $pkField  = $metadata->getPrimaryKey();

        $objects          = [];
        $currentKey       = null;
        $object           = null;
        $recordsToProcess = [];

        $relations = array_map(
            [$metadata, 'getRelation'],
            array_intersect($with, $metadata->getRelationNames())
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

    private function createObject(Entity $entity, array $record, array $relations, $readOnly)
    {
        $metadata = $entity->getMetadata();
        $fields   = $metadata->getFieldNames();

        $object = $entity->handle(
            $metadata->create(
                array_intersect_key($record, $fields)
            )
        );
        $entity->setReadOnly($object, $readOnly);

        foreach ($relations as $relation) {
            /** @var Relation $relation */
            $relation->setEmptyValue($object);
        }

        return $object;
    }

    private function processRelated(Entity $entity, $object, $readOnly, array $records, array $relations, array $with)
    {
        if (empty($records)) {
            return;
        }
        foreach ($relations as $relation) {
            /** @var Relation $relation */
            $value = $this->processRecords(
                $this->manager->get($relation->target),
                Utils::filterPrefixedElements(
                    $with,
                    $relation->name . '.',
                    Utils::FILTER_REMOVE_PREFIX
                ),
                $this->stripRelationPrefix($records, $relation->name . '_'),
                $readOnly
            );

            if ($relation->isSingle()) {
                $value = current($value);
            }

            if (!empty($value)) {
                $entity->setRelationValue($object, $relation->name, $value);
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

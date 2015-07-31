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

    public function processRecords(
        Entity $entity,
        array $with,
        $records,
        $readOnly
    )
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
                $object     = $this->createObject($entity, $record, $relations, $readOnly);
            }
            //Store the record to be processed for the related entities
            $recordsToProcess[] = $record;
        }
        if ($object !== null) {
            //Process and save the last object
            $this->processRelated($entity, $object, $readOnly, $recordsToProcess, $relations, $with);
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
                $this->filterRelations($with, $relation->name . '.'),
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
        $prefixLength = strlen($prefix);

        return array_map(
            function ($rawRecord) use ($prefix, $prefixLength) {
                $record = [];
                //Filter for fields that are needed for the current record and remove field name prefixes
                foreach ($rawRecord as $key => $value) {
                    if (strpos($key, $prefix) === 0) {
                        $key            = substr($key, $prefixLength);
                        $record[ $key ] = $value;
                    }
                }

                return $record;
            },
            $records
        );
    }

    /**
     * @param $with
     * @param $prefix
     *
     * @return array
     */
    private function filterRelations(array $with, $prefix)
    {
        //Filter $with to remove elements that are not prefixed for the current relation
        $prefixLength = strlen($prefix);

        return array_map(
            function ($relationName) use ($prefixLength) {
                return substr($relationName, $prefixLength);
            },
            array_filter(
                $with,
                Utils::createStartWith($prefix)
            )
        );
    }
}

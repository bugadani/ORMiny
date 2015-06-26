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

    public function processRecords(
        Entity $entity,
        array $with,
        \Traversable $records,
        $readOnly
    )
    {
        $metadata = $entity->getMetadata();
        $pkField  = $metadata->getPrimaryKey();

        $objects          = [];
        $currentKey       = null;
        $object           = null;
        $recordsToProcess = [];

        foreach ($records as $record) {
            //Extract columns that are relevant for the current metadata
            $key = $record[ $pkField ];
            if ($currentKey !== $key) {
                if ($object !== null) {
                    $this->processRelated(
                        $entity,
                        $object,
                        $readOnly,
                        $recordsToProcess,
                        $with
                    );
                    $recordsToProcess       = [];
                    $objects[ $currentKey ] = $object;
                }
                $currentKey = $key;
                $object     = $this->createObject($entity, $record, $with);
                if ($readOnly) {
                    $entity->setReadOnly($object);
                }
            }
            //Store the record to be processed for the related entities
            $recordsToProcess[] = $record;
        }
        if ($object !== null) {
            $this->processRelated($entity, $object, $readOnly, $recordsToProcess, $with);
            $objects[ $key ] = $object;
        }

        return $objects;
    }

    private function createObject(Entity $entity, array $record, array $with)
    {
        $metadata = $entity->getMetadata();
        $fields   = $metadata->getFieldNames();

        $object = $entity->handle(
            $metadata->create(
                array_intersect_key($record, $fields)
            )
        );
        foreach (array_filter($with, [$metadata, 'hasRelation']) as $relationName) {
            $metadata->setRelationValue($object, $relationName, $metadata->getRelation($relationName)
                                                                         ->isSingle() ? null : []);
        }

        return $object;
    }

    private function processRelated(Entity $entity, $object, $readOnly, $records, $with)
    {
        $metadata = $entity->getMetadata();
        foreach (array_filter($with, [$metadata, 'hasRelation']) as $relationName) {
            $relation = $metadata->getRelation($relationName);

            $value = $this->processRecords(
                $this->manager->get($relation->target),
                $this->filterRelations($with, $relationName . '.'),
                new \ArrayIterator(
                    $this->stripRelationPrefix($records, $relationName . '_')
                ),
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
     * @param $records
     * @param $prefix
     *
     * @return array
     */
    private function stripRelationPrefix(array $records, $prefix)
    {
        //Strip the relation prefix from the columns
        $prefixLength = strlen($prefix);

        return array_map(
            function ($rawRecord) use ($prefix, $prefixLength) {
                $record = [];
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
                function ($relationName) use ($prefix) {
                    return strpos($relationName, $prefix) === 0;
                }
            )
        );
    }
}

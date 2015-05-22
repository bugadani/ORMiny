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
        EntityMetadata $metadata,
        array $with,
        \Traversable $records,
        $readOnly
    ) {
        $entity  = $this->manager->get($metadata->getClassName());
        $pkField = $metadata->getPrimaryKey();

        $objects          = [];
        $currentKey       = null;
        $object           = null;
        $recordsToProcess = [];
        $fields           = $metadata->getFields();

        foreach ($records as $record) {
            //Extract columns that are relevant for the current metadata
            $data = array_intersect_key($record, $fields);
            $key  = $data[ $pkField ];
            if ($currentKey !== $key) {
                if ($object !== null) {
                    $this->processRelated(
                        $metadata,
                        $object,
                        $readOnly,
                        $recordsToProcess,
                        $with
                    );
                    $recordsToProcess       = [];
                    $objects[ $currentKey ] = $object;
                }
                $currentKey = $key;
                $object     = $entity->create($data);
                if ($readOnly) {
                    $entity->setReadOnly($object);
                }
            }
            //Store the record to be processed for the related entities
            $recordsToProcess[] = $record;
        }
        if ($object !== null) {
            $this->processRelated($metadata, $object, $readOnly, $recordsToProcess, $with);
            $objects[ $key ] = $object;
        }

        return $objects;
    }

    private function processRelated(EntityMetadata $metadata, $object, $readOnly, $records, $with)
    {
        foreach (array_filter($with, [$metadata, 'hasRelation']) as $relationName) {
            $relation = $metadata->getRelation($relationName);

            $value = $this->processRecords(
                $this->manager->get($relation->target)->getMetadata(),
                $this->filterRelations($with, $relationName . '.'),
                new \ArrayIterator(
                    $this->stripRelationPrefix($records, $relationName . '_')
                ),
                $readOnly
            );

            if ($relation->type === Relation::HAS_ONE || $relation->type === Relation::BELONGS_TO) {
                $value = current($value);
            }
            if (!empty($value)) {
                $entity = $this->manager->get($metadata->getClassName());
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
        return array_map(
            function ($rawRecord) use ($prefix) {
                $record       = [];
                $prefixLength = strlen($prefix);
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

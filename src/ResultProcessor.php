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
    private $readOnly;

    public function __construct(EntityManager $manager)
    {
        $this->manager = $manager;
    }

    public function processRecords(EntityMetadata $entity, $with, $readOnly, array $records)
    {
        $this->readOnly = $readOnly;

        return $this->process($entity, $records, $with);
    }

    private function process(EntityMetadata $metadata, array $records, array $with)
    {
        $entity  = $this->manager->get($metadata->getClassName());
        $pkField = $metadata->getPrimaryKey();

        $objects          = [];
        $currentKey       = null;
        $object           = null;
        $recordsToProcess = [];
        $fields           = $metadata->getFields();

        foreach ($records as $record) {
            //This loop extracts columns that are relevant for the current metadata
            $data = array_intersect_key($record, $fields);
            $key  = $data[$pkField];
            if ($currentKey !== $key) {
                if ($object !== null) {
                    $this->processRelatedRecords($metadata, $object, $recordsToProcess, $with);
                    $recordsToProcess     = [];
                    $objects[$currentKey] = $object;
                }
                $currentKey = $key;
                $object     = $entity->create($data);
                if ($this->readOnly) {
                    $entity->setReadOnly($object);
                }
            }
            //The rest of the record is stored to be processed for the related entities
            $recordsToProcess[] = array_diff_key($record, $fields);
        }
        if ($object !== null) {
            $this->processRelatedRecords($metadata, $object, $recordsToProcess, $with);
            $objects[$key] = $object;
        }

        return $objects;
    }

    private function processRelatedRecords(EntityMetadata $metadata, $object, $records, $with)
    {
        foreach (array_filter($with, [$metadata, 'hasRelation']) as $relationName) {

            //Filter $with to remove elements that are not prefixed for the current relation
            $withPrefix = $relationName . '.';
            $with       = array_map(
                function ($relationName) use ($withPrefix) {
                    return substr($relationName, strlen($withPrefix));
                },
                array_filter(
                    $with,
                    function ($relationName) use ($withPrefix) {
                        return strpos($relationName, $withPrefix) === 0;
                    }
                )
            );

            //Strip the relation prefix from the columns
            $records = array_map(
                function ($rawRecord) use ($relationName) {
                    $record       = [];
                    $prefixLength = strlen($relationName) + 1;
                    foreach ($rawRecord as $key => $value) {
                        if (strpos($key, $relationName . '_') === 0) {
                            $key          = substr($key, $prefixLength);
                            $record[$key] = $value;
                        }
                    }

                    return $record;
                },
                $records
            );

            $relation         = $metadata->getRelation($relationName);
            $relationMetadata = $this->manager->get($relation->target)->getMetadata();
            $value            = $this->process($relationMetadata, $records, $with);

            switch ($relation->type) {
                case Relation::HAS_ONE:
                case Relation::BELONGS_TO:
                    $value = current($value);
                    break;
            }

            $this->manager
                ->get($metadata->getClassName())
                ->setRelationValue($object, $relationName, $value);
        }
    }
}

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

    private $with;
    private $readOnly;
    private $relationStack;

    public function __construct(EntityManager $manager)
    {
        $this->manager = $manager;
    }

    public function processRecords(EntityMetadata $entity, $with, $readOnly, array $records)
    {
        $this->with     = $with;
        $this->readOnly = $readOnly;

        $this->relationStack = [];

        return $this->process($entity, $records);
    }

    /**
     * @param EntityMetadata $metadata
     * @param array          $records
     *
     * @return array
     */
    private function process(EntityMetadata $metadata, $records)
    {
        $entity  = $this->manager->get($metadata->getClassName());
        $pkField = $metadata->getPrimaryKey();

        $objects          = [];
        $currentKey       = null;
        $object           = null;
        $recordsToProcess = [];
        $fields           = $metadata->getFields();

        foreach ($records as $record) {
            $data = array_intersect_key($record, $fields);
            $key  = $data[$pkField];
            if ($currentKey !== $key) {
                if ($object !== null) {
                    $this->processRelatedRecords($metadata, $object, $recordsToProcess);
                    $recordsToProcess     = [];
                    $objects[$currentKey] = $object;
                }
                $currentKey = $key;
                $object     = $entity->create($data);
                if ($this->readOnly) {
                    $entity->setReadOnly($object);
                }
            }
            $recordsToProcess[] = array_diff_key($record, $fields);
        }
        if ($object !== null) {
            $this->processRelatedRecords($metadata, $object, $recordsToProcess);
            $objects[$key] = $object;
        }

        return $objects;
    }

    /**
     * @param EntityMetadata $metadata
     * @param                $object
     * @param array          $recordsToProcess
     */
    private function processRelatedRecords(EntityMetadata $metadata, $object, $recordsToProcess)
    {
        //copied from EntityFinder
        $with = $this->with;
        if (!empty($this->relationStack)) {
            $withPrefix   = implode('.', $this->relationStack) . '.';
            $prefixLength = strlen($withPrefix);

            $with = array_map(
                function ($relationName) use ($prefixLength) {
                    return substr($relationName, $prefixLength);
                },
                array_filter(
                    $with,
                    function ($relationName) use ($withPrefix) {
                        return strpos($relationName, $withPrefix) === 0;
                    }
                )
            );
        }

        foreach (array_filter($with, [$metadata, 'hasRelation']) as $relationName) {
            $this->relationStack[] = $relationName;

            $relation = $metadata->getRelation($relationName);

            $value = $this->process(
                $this->manager->get($relation->target)->getMetadata(),
                array_map(
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
                    $recordsToProcess
                )
            );
            switch ($metadata->getRelation($relationName)->type) {
                case Relation::HAS_ONE:
                case Relation::BELONGS_TO:
                    $value = current($value);
                    break;
            }
            $this->manager->get($metadata->getClassName())
                ->setRelationValue($object, $relationName, $value);

            array_pop($this->relationStack);
        }
    }
}

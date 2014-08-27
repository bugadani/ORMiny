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
    private $with;
    private $readOnly;
    private $relationStack;

    public function processRecords(Entity $entity, $with, $readOnly, array $records)
    {
        $this->with     = $with;
        $this->readOnly = $readOnly;

        $this->relationStack = [];

        return $this->process($entity, $records);
    }

    /**
     * @param Entity $entity
     * @param array  $records
     *
     * @return array
     */
    private function process($entity, $records)
    {
        $pkField = $entity->getPrimaryKey();

        $objects          = [];
        $currentKey       = null;
        $object           = null;
        $recordsToProcess = [];
        $fields           = $entity->getFields();

        foreach ($records as $record) {
            $data = array_intersect_key($record, $fields);
            $key  = $data[$pkField];
            if ($currentKey !== $key) {
                if ($object !== null) {
                    $this->processRelatedRecords($entity, $object, $recordsToProcess);
                    $recordsToProcess     = [];
                    $objects[$currentKey] = $object;
                }
                $currentKey = $key;
                $object     = $entity->create($data);
                if($this->readOnly) {
                    $entity->setReadOnly($object);
                }
            }
            $recordsToProcess[] = array_diff_key($record, $fields);
        }
        if ($object !== null) {
            $this->processRelatedRecords($entity, $object, $recordsToProcess);
            $objects[$key] = $object;
        }

        return $objects;
    }

    /**
     * @param Entity       $entity
     * @param              $object
     * @param array        $recordsToProcess
     */
    private function processRelatedRecords($entity, $object, $recordsToProcess)
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

        foreach (array_filter($with, [$entity, 'hasRelation']) as $relationName) {
            $this->relationStack[] = $relationName;

            $value = $this->process(
                $entity->getRelatedEntity($relationName),
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
            switch ($entity->getRelation($relationName)->type) {
                case Relation::HAS_ONE:
                case Relation::BELONGS_TO:
                    $value = current($value);
                    break;
            }
            $entity->setRelationValue($object, $relationName, $value);

            array_pop($this->relationStack);
        }
    }
}

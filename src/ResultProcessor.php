<?php

namespace Modules\ORM;

class ResultProcessor
{
    private $with;
    private $relationStack;

    public function processRecords(Entity $entity, $with, array $records)
    {
        $this->with          = $with;
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
                    $this->processRelatedRecords(
                        $entity,
                        $object,
                        $recordsToProcess
                    );
                    $recordsToProcess     = [];
                    $objects[$currentKey] = $object;
                }
                $currentKey = $key;
                $object     = $entity->create($data);
            }
            $recordsToProcess[] = array_diff_key($record, $fields);
        }
        if ($object !== null) {
            $this->processRelatedRecords(
                $entity,
                $object,
                $recordsToProcess
            );
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

        $with = array_filter($with, [$entity, 'hasRelation']);

        foreach ($with as $relationName) {
            $this->relationStack[] = $relationName;

            array_walk(
                $recordsToProcess,
                [$this, 'getRelevantColumns'],
                $relationName . '_'
            );
            $value = $this->process(
                $entity->getRelatedEntity($relationName),
                $recordsToProcess
            );
            if ($entity->getRelation($relationName)->type === 'has one') {
                $value = current($value);
            }
            $entity->setRelationValue($object, $relationName, $value);

            array_pop($this->relationStack);
        }
    }

    private function getRelevantColumns(&$rawRecord, $key, $prefix)
    {
        if ($prefix === '') {
            return;
        }
        $record       = [];
        $prefixLength = strlen($prefix);
        foreach ($rawRecord as $key => $value) {
            if (strpos($key, $prefix) === 0) {
                $key          = substr($key, $prefixLength);
                $record[$key] = $value;
            }
        }
        $rawRecord = $record;
    }
}

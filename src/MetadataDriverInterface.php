<?php

/**
 * This file is part of the ORMiny library.
 * (c) Dániel Buga <bugadani@gmail.com>
 *
 * For licensing information see the LICENSE file.
 */

namespace ORMiny;

/**
 * Interface MetadataDriverInterface
 *
 * @package ORMiny
 */
interface MetadataDriverInterface
{
    /**
     * @param EntityManager $manager
     */
    public function setEntityManager(EntityManager $manager);

    /**
     * @param Entity $entity
     */
    public function readEntityMetadata(Entity $entity);
}

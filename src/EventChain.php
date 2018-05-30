<?php

namespace LegalThings\LiveContracts\Tester;

use LTO\EventChain as Base;
use stdClass;

/**
 * Event chain with projection
 */
class EventChain extends Base
{
    /**
     * @var stdClass
     */
    protected $projection;

    /**
     * The hash of last event of the chain when the projection was fetched
     * @var string
     */
    protected $eventAtProjection;


    /**
     * Get the projection of the event chain
     *
     * @return array|null
     */
    public function getProjection(): ?stdClass
    {
        return $this->eventAtProjection === $this->getLatestHash() ? $this->projection : null;
    }

    /**
     * Set the projection of the event chain
     *
     * @param stdClass $projection
     * @return void
     */
    public function setProjection(stdClass $projection): void
    {
        $this->projection = $projection;
        $this->eventAtProjection = $this->getLatestHash();
    }
}

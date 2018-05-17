<?php

namespace LegalThings\LiveContracts\Tester;

use LTO\EventChain as Base;

/**
 * Event chain with projection
 */
class EventChain extends Base
{
    /**
     * @var array
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
    public function getProjection(): ?array
    {
        return $this->eventAtProjection === $this->getLatestHash() ? $this->projection : null;
    }

    /**
     * Set the projection of the event chain
     *
     * @param array $projection
     */
    public function setProjection(array $projection)
    {
        $this->projection = $projection;
        $this->eventAtProjection === $this->getLatestHash();
    }
}

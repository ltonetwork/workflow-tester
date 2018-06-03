<?php

namespace LegalThings\LiveContracts\Tester;

use LTO\Event;
use LTO\EventChain as Base;
use LTO\Account;
use stdClass;
use UnexpectedValueException;

/**
 * Event chain with projection
 */
class EventChain extends Base
{
    /**
     * @var stdClass[]
     */
    public $identities;

    /**
     * @var stdClass[]
     */
    public $comments;

    /**
     * @var string[]
     */
    public $resources;

    /**
     * The hash of last event of the chain when the projection was fetched
     * @var string
     */
    protected $lastSendEvent;


    /**
     * Update the event chain
     *
     * @param stdClass $projection
     * @return void
     */
    public function update(stdClass $projection): void
    {
        foreach ($this->events as $i => $event) {
            $hash = $projection->events[$i]->hash;

            if ($hash !== $event->hash) {
                throw new UnexpectedValueException("Chain mismatch on event $i: '$hash' is not '$event->hash'");
            }
        }

        foreach (array_slice($projection->events, count($this->events)) as $data) {
            $this->events[] = $this->castEvent($data);
        }

        $this->lastSendEvent = $this->getLatestHash();

        $this->identities = $projection->identities;
        $this->comments = $projection->comments;
        $this->resources = $projection->resources;
    }

    /**
     * Set identity ids for each account.
     *
     * @param Account[] $accounts
     * @return void
     */
    public function linkIdentities(array $accounts): void
    {
        $index = [];

        foreach ($this->identities as $identity) {
            foreach ($identity->signkeys as $key) {
                $index[$key] = $identity->id;
            }
        }

        foreach ($accounts as $account) {
            $key = $account->getPublicSignKey();
            $account->id = isset($index[$key]) ? $index[$key] : null;
        }
    }

    /**
     * Check if there are no unsend events
     *
     * @return bool
     */
    public function isSynced(): bool
    {
        return $this->getLatestHash() === $this->lastSendEvent;
    }

    /**
     * Cast data to an event
     *
     * @param stdClass $data
     * @return Event
     */
    protected function castEvent($data): Event
    {
        $event = new Event();

        foreach ($data as $key => $value) {
            $event->$key = $value;
        }

        return $event;
    }
}

<?php

/*
 * This file is part of the broadway/snapshotting package.
 *
 * (c) Qandidate.com <opensource@qandidate.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Broadway\Snapshotting\EventSourcing;

use Broadway\Domain\AggregateRoot;
use Broadway\EventSourcing\EventSourcingRepository;
use Broadway\EventStore\EventStore;
use Broadway\Repository\Repository;
use Broadway\Snapshotting\Snapshot\Snapshot;
use Broadway\Snapshotting\Snapshot\SnapshotNotFoundException;
use Broadway\Snapshotting\Snapshot\SnapshotRepository;
use Broadway\Snapshotting\Snapshot\Trigger;

class SnapshottingEventSourcingRepository implements Repository
{
    private $eventSourcingRepository;
    private $eventStore;
    private $snapshotRepository;
    private $trigger;

    public function __construct(
        EventSourcingRepository $eventSourcingRepository,
        EventStore $eventStore,
        SnapshotRepository $snapshotRepository,
        Trigger $trigger
    ) {
        $this->eventSourcingRepository = $eventSourcingRepository;
        $this->eventStore              = $eventStore;
        $this->snapshotRepository      = $snapshotRepository;
        $this->trigger                 = $trigger;
    }

    /**
     * {@inheritdoc}
     */
    public function load($id): AggregateRoot
    {
        $snapshot = $this->snapshotRepository->load($id);
        if (null === $snapshot) {
            return $this->eventSourcingRepository->load($id);
        }

        $aggregateRoot = $snapshot->getAggregateRoot();
        $aggregateRoot->initializeState(
            $this->eventStore->loadFromPlayhead($id, $snapshot->getPlayhead() + 1)
        );

        return $aggregateRoot;
    }

    /**
     * {@inheritdoc}
     */
    public function loadUntilPlayhead($id, $playhead) : AggregateRoot
    {
        $snapshot = $this->snapshotRepository->load($id);
        if ($snapshot === null || $snapshot->getPlayhead() > $playhead) {
            return $this->eventSourcingRepository->loadUntilPlayhead($id, $playhead);
        }

        $aggregateRoot = $snapshot->getAggregateRoot();
        $aggregateRoot->initializeState(
            $this->eventStore->loadFromPlayheadSlice($id, $snapshot->getPlayhead() + 1, $playhead)
        );
        return $aggregateRoot;
    }

    /**
     * {@inheritdoc}
     */
    public function save(AggregateRoot $aggregate) : void
    {
        $takeSnapshot = $this->trigger->shouldSnapshot($aggregate);

        $this->eventSourcingRepository->save($aggregate);

        if ($takeSnapshot) {
            $this->snapshotRepository->save(
                new Snapshot($aggregate)
            );
        }
    }

    // ToDo: Rebuild
    public function rebuild($id) : void
    {
        $eventStream = $this->eventStore->load($id);

        if ($eventStream->getIterator()->count() >= $this->trigger->getEventCount()) {
            $this->snapshotRepository->save(
                new Snapshot($this->load($id))
            );
        }
    }
}

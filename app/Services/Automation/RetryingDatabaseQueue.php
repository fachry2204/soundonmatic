<?php

namespace App\Services\Automation;

use Illuminate\Queue\DatabaseQueue;

class RetryingDatabaseQueue extends DatabaseQueue
{
    public function deleteReserved($queue, $id)
    {
        if ($this->database->getDriverName() !== 'sqlite') return parent::deleteReserved($queue, $id);

        // Retry only the acknowledgement transaction, never the release job.
        $this->database->transaction(function () use ($id) {
            if ($this->database->table($this->table)->lockForUpdate()->find($id)) {
                $this->database->table($this->table)->where('id', $id)->delete();
            }
        }, 5);
    }

    public function deleteAndRelease($queue, $job, $delay)
    {
        if ($this->database->getDriverName() !== 'sqlite') return parent::deleteAndRelease($queue, $job, $delay);

        $this->database->transaction(function () use ($queue, $job, $delay) {
            $this->database->table($this->table)->where('id', $job->getJobId())->delete();
            $this->release($queue, $job->getJobRecord(), $delay);
        }, 5);
    }

    public function pop($queue = null)
    {
        if ($this->database->getDriverName() !== 'sqlite') {
            return parent::pop($queue);
        }

        // SQLite on PHP < 8.4 uses deferred transactions. Two consumers can
        // read the same candidate before either reserves it. Retry the whole
        // transaction on a lock collision; this is not a failed release job.
        $queue = $this->getQueue($queue);
        return $this->database->transaction(function () use ($queue) {
            $record = $this->getNextAvailableJob($queue);
            return $record ? $this->marshalJob($queue, $record) : null;
        }, 5);
    }
}

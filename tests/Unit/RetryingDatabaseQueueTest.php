<?php

namespace Tests\Unit;

use App\Services\Automation\RetryingDatabaseQueue;
use Illuminate\Database\QueryException;
use Illuminate\Database\SQLiteConnection;
use Tests\TestCase;

class RetryingDatabaseQueueTest extends TestCase
{
    public function test_sqlite_lock_during_release_rolls_back_and_does_not_duplicate_the_job(): void
    {
        $connection = new SQLiteConnection(new \PDO('sqlite::memory:'), ':memory:', '', ['driver' => 'sqlite', 'name' => 'retry-test']);
        $connection->statement('CREATE TABLE jobs (id INTEGER PRIMARY KEY AUTOINCREMENT, queue TEXT, payload TEXT, attempts INTEGER, reserved_at INTEGER, available_at INTEGER, created_at INTEGER)');
        $record = (object) ['id' => 1, 'queue' => 'status-checks', 'payload' => '{}', 'attempts' => 1, 'reserved_at' => time(), 'available_at' => time(), 'created_at' => time()];
        $connection->table('jobs')->insert((array) $record);
        $inserts = 0;
        $connection->beforeExecuting(function ($query) use (&$inserts): void {
            if (str_starts_with($query, 'insert') && ++$inserts === 1) {
                throw new QueryException('sqlite', $query, [], new \PDOException('database is locked', 5));
            }
        });
        $job = \Mockery::mock(\Illuminate\Queue\Jobs\DatabaseJob::class);
        $job->shouldReceive('getJobId')->andReturn(1);
        $job->shouldReceive('getJobRecord')->andReturn(new \Illuminate\Queue\Jobs\DatabaseJobRecord($record));
        (new RetryingDatabaseQueue($connection, 'jobs', 'default', 3000))->deleteAndRelease('status-checks', $job, 30);
        $this->assertSame(2, $inserts);
        $this->assertSame(1, $connection->table('jobs')->count());
        $this->assertNull($connection->table('jobs')->first()->reserved_at);
    }

    public function test_sqlite_lock_during_acknowledgement_retries_only_the_delete(): void
    {
        $connection = new SQLiteConnection(new \PDO('sqlite::memory:'), ':memory:', '', ['driver' => 'sqlite', 'name' => 'retry-test']);
        $connection->statement('CREATE TABLE jobs (id INTEGER PRIMARY KEY)');
        $connection->table('jobs')->insert(['id' => 1]);
        $deletes = 0;
        $connection->beforeExecuting(function ($query) use (&$deletes): void {
            if (str_starts_with($query, 'delete') && ++$deletes === 1) {
                throw new QueryException('sqlite', $query, [], new \PDOException('database is locked', 5));
            }
        });
        $queue = new RetryingDatabaseQueue($connection, 'jobs', 'default', 3000);
        $queue->deleteReserved('default', 1);
        $this->assertSame(2, $deletes);
        $this->assertSame(0, $connection->table('jobs')->count());
    }
}

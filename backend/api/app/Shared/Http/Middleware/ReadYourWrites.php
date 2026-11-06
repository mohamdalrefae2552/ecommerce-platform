<?php

declare(strict_types=1);

namespace App\Shared\Http\Middleware;

use App\Shared\Auth\AuthUser;
use App\Shared\Http\Problems\DomainProblem;
use Closure;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Database\DatabaseManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Symfony\Component\HttpFoundation\Response;

/**
 * "The user sees what they just created" with a read replica (requirement
 * 3, R3-4). Reads go to the replica and writes to the primary (config
 * database.connections.pgsql read/write); the replica replays the primary's
 * WAL a few milliseconds later (replication lag).
 *
 *   - Within one request: `sticky` — after the request has written, its
 *     reads use the primary (POST /orders answers with the order it saved).
 *   - Across requests: the next request of the same caller (the order page
 *     right after "place order", the refresh right after login) may come
 *     before the replica has replayed the write. A caller that wrote is
 *     remembered for `database.read_your_writes_seconds` (well above the
 *     observed lag); during that window its requests read from the primary
 *     too. The caller is the user, or the IP for anonymous writes.
 *
 * Everyone else keeps reading from the replica.
 */
final readonly class ReadYourWrites
{
    public function __construct(
        private DatabaseManager $db,
        private Repository $cache,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $connection = $this->db->connection();
        $connection->forgetRecordModificationState();

        // Inside the caller's window every read uses the primary. Only a real
        // write of THIS request (recordsModified) opens a new window, so
        // reading during a window does not extend it.
        $connection->useWriteConnectionWhenReading($this->cache->has($this->key($request)));

        try {
            $response = $next($request);
        } finally {
            $connection->useWriteConnectionWhenReading(false);
        }

        if ($connection->hasModifiedRecords()) {
            // Keyed by the user known after the request (a login has none before).
            $this->cache->put($this->key($request), true, max(1, Config::integer('database.read_your_writes_seconds')));
        }

        return $response;
    }

    private function key(Request $request): string
    {
        try {
            $user = $request->user();
        } catch (DomainProblem) {
            // Expired token: identify the caller by IP instead.
            $user = null;
        }

        return 'read-your-writes:'.($user instanceof AuthUser ? 'user:'.$user->userId() : 'ip:'.$request->ip());
    }
}

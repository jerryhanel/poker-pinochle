<?php

/**
 * In-memory mysqli stand-in for Lib unit tests. PHP 7.4+ compatible.
 *
 * The Lib classes type-hint `mysqli` (Presence, GameStore), so FakeMysqli has
 * to BE a mysqli to get past those hints — a plain class would raise a
 * TypeError. mysqli is not a final class, so we extend it, but never call the
 * parent constructor (no connection is ever opened) and override every method
 * the Lib layer calls.
 *
 * When the mysqli extension is not loaded (e.g. a dev box that only has
 * mysqlnd/pdo_mysql), an empty global `mysqli` class and the MYSQLI_ASSOC
 * constants are declared here so the type hints and fetch modes still resolve.
 *
 * insert_id / host_info: with the real extension loaded, mysqli's own property
 * handlers own those names (and PHP 8.1+ declares them typed on the parent),
 * so they can't be redeclared here. They are served through __get(), which
 * works when the fallback mysqli class is in use. Tests that need insert_id
 * against the real extension should read nextInsertId() instead.
 */

if (!class_exists('mysqli', false)) {
    // phpcs:ignore
    class mysqli
    {
    }
}
if (!defined('MYSQLI_ASSOC')) {
    define('MYSQLI_ASSOC', 1);
}
if (!defined('MYSQLI_NUM')) {
    define('MYSQLI_NUM', 2);
}
if (!defined('MYSQLI_BOTH')) {
    define('MYSQLI_BOTH', 3);
}

class FakeMysqli extends mysqli
{
    /** @var array<int,array<int,array<string,mixed>>> queued result sets, consumed one per query()/prepare() */
    private $resultQueue = [];

    /** @var int[] queued insert ids, consumed one per insert_id read */
    private $insertIdQueue = [];

    /** @var int last insert id handed out (returned again once the queue is empty) */
    private $lastInsertId = 0;

    /** @var string[] every SQL string passed to query()/prepare(), in order */
    private $sqlLog = [];

    /** @var array<int,array> every bind_param() call's params, in order */
    private $paramLog = [];

    /** @var string[] begin/commit/rollback calls, in order */
    public $txLog = [];

    public function __construct()
    {
        // Deliberately does not call parent::__construct(): no connection.
    }

    // ---- scripting -----------------------------------------------------------

    /** Queue a result set (list of assoc rows) for the next query()/prepare() call. */
    public function addRows(array $rows): void
    {
        $this->resultQueue[] = array_values($rows);
    }

    /** Queue a value for the next insert_id read. */
    public function addInsertId(int $id): void
    {
        $this->insertIdQueue[] = $id;
    }

    /** @return string[] */
    public function getSql(): array
    {
        return $this->sqlLog;
    }

    /** @return array<int,array> */
    public function getParams(): array
    {
        return $this->paramLog;
    }

    /** Consume the next queued insert id (what an `insert_id` read returns). */
    public function nextInsertId(): int
    {
        if (!empty($this->insertIdQueue)) {
            $this->lastInsertId = array_shift($this->insertIdQueue);
        }
        return $this->lastInsertId;
    }

    /** @internal used by FakeStmt */
    public function recordParams(array $params): void
    {
        $this->paramLog[] = $params;
    }

    private function nextResult(): FakeResult
    {
        $rows = empty($this->resultQueue) ? [] : array_shift($this->resultQueue);
        return new FakeResult($rows);
    }

    // ---- mysqli surface --------------------------------------------------------
    // Parameters are left untyped and return types omitted so the overrides stay
    // signature-compatible with the real extension under both PHP 7.4 and 8.x.
    // (#[...] is a comment on PHP 7.4.)

    /** @return FakeStmt */
    #[\ReturnTypeWillChange]
    public function prepare($query)
    {
        $this->sqlLog[] = (string) $query;
        return new FakeStmt($this, $this->nextResult());
    }

    /** @return FakeResult */
    #[\ReturnTypeWillChange]
    public function query($query, $result_mode = 0)
    {
        $this->sqlLog[] = (string) $query;
        return $this->nextResult();
    }

    /** @return string */
    #[\ReturnTypeWillChange]
    public function real_escape_string($string)
    {
        return (string) $string;
    }

    /** @return bool */
    #[\ReturnTypeWillChange]
    public function begin_transaction($flags = 0, $name = null)
    {
        $this->txLog[] = 'begin';
        return true;
    }

    /** @return bool */
    #[\ReturnTypeWillChange]
    public function commit($flags = 0, $name = null)
    {
        $this->txLog[] = 'commit';
        return true;
    }

    /** @return bool */
    #[\ReturnTypeWillChange]
    public function rollback($flags = 0, $name = null)
    {
        $this->txLog[] = 'rollback';
        return true;
    }

    /** Serves insert_id / host_info when the fallback mysqli class is in use. */
    public function __get($name)
    {
        if ($name === 'insert_id') {
            return $this->nextInsertId();
        }
        if ($name === 'host_info') {
            return 'FakeMysqli via memory';
        }
        return null;
    }
}

class FakeStmt
{
    /** @var FakeMysqli */
    private $db;

    /** @var FakeResult */
    private $result;

    /** @var array params from the last bind_param() call */
    public $params = [];

    /** @var string types from the last bind_param() call */
    public $types = '';

    public function __construct(FakeMysqli $db, FakeResult $result)
    {
        $this->db = $db;
        $this->result = $result;
    }

    public function bind_param(string $types, ...$params): bool
    {
        $this->types = $types;
        $this->params = $params;
        $this->db->recordParams($params);
        return true;
    }

    public function execute(): bool
    {
        return true;
    }

    public function get_result(): FakeResult
    {
        return $this->result;
    }

    public function close(): bool
    {
        return true;
    }
}

class FakeResult
{
    /** @var array<int,array<string,mixed>> */
    private $rows;

    /** @var int */
    private $pos = 0;

    /** @var int */
    public $num_rows;

    public function __construct(array $rows)
    {
        $this->rows = array_values($rows);
        $this->num_rows = count($this->rows);
    }

    public function fetch_assoc(): ?array
    {
        if ($this->pos >= count($this->rows)) {
            return null;
        }
        return $this->rows[$this->pos++];
    }

    public function fetch_all(int $mode = MYSQLI_ASSOC): array
    {
        $rest = array_slice($this->rows, $this->pos);
        $this->pos = count($this->rows);
        if ($mode === MYSQLI_NUM) {
            return array_map('array_values', $rest);
        }
        return $rest;
    }

    public function free(): void
    {
    }
}

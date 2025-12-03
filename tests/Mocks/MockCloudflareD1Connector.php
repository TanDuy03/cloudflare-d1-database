<?php

namespace Ntanduy\CFD1\Test\Mocks;

use Ntanduy\CFD1\CloudflareD1Connector;
use Saloon\Http\Response;
use PDO;
use PDOException;

class MockCloudflareD1Connector extends CloudflareD1Connector
{
    // Use static to persist DB state across connection re-resolves within a test
    private static ?PDO $sqlite = null;

    public function __construct(...$args)
    {
        parent::__construct(...$args);
        $this->ensureDatabaseExists();
    }

    public static function reset(): void
    {
        self::$sqlite = null;
    }

    private function ensureDatabaseExists(): void
    {
        if (self::$sqlite === null) {
            self::$sqlite = new PDO('sqlite::memory:');
            self::$sqlite->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            self::$sqlite->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        }
    }

    public function databaseQuery(string $query, array $params): Response
    {
        $this->ensureDatabaseExists();

        $results = [];
        $changes = 0;
        $lastRowId = null;
        $success = true;
        $errors = [];

        try {
            // Handle Transaction commands explicitly
            if (preg_match('/^\s*BEGIN/i', $query)) {
                self::$sqlite->beginTransaction();
                $changes = 0;
            } elseif (preg_match('/^\s*COMMIT/i', $query)) {
                self::$sqlite->commit();
                $changes = 0;
            } elseif (preg_match('/^\s*ROLLBACK/i', $query)) {
                self::$sqlite->rollBack();
                $changes = 0;
            } else {
                // Prepare and execute the query against the real in-memory SQLite
                $stmt = self::$sqlite->prepare($query);
                $stmt->execute($params);

                // Determine query type for response formatting
                $isSelect = preg_match('/^\s*(SELECT|WITH|PRAGMA|EXPLAIN)\b/i', $query);

                if ($isSelect) {
                    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    $changes = 0; // D1 returns 0 changes for SELECT
                } else {
                    $changes = $stmt->rowCount();
                    // Only get lastInsertId for INSERTs to mimic D1 behavior closely
                    if (preg_match('/^\s*INSERT\b/i', $query)) {
                        $lastRowId = self::$sqlite->lastInsertId();
                        // D1 returns the ID as integer usually, but PDO might return string
                        $lastRowId = $lastRowId ? (int) $lastRowId : null;
                    }
                }
            }

        } catch (PDOException $e) {
            $success = false;
            $errors = [
                [
                    'code' => $e->getCode() !== '00000' ? $e->getCode() : 7500,
                    'message' => $e->getMessage(),
                ]
            ];
        }

        // Construct D1-compatible JSON response
        $responseBody = json_encode([
            'success' => $success,
            'errors' => $errors,
            'messages' => [],
            'result' => [
                [
                    'results' => $results,
                    'meta' => [
                        'served_by' => 'mock-sqlite-memory',
                        'duration' => 0.001,
                        'changes' => $changes,
                        'last_row_id' => $lastRowId,
                        'rows_read' => count($results),
                        'rows_written' => $changes
                    ]
                ]
            ]
        ]);

        // Create PSR-7 objects for Saloon Response
        $psrResponse = new \GuzzleHttp\Psr7\Response(
            200,
            ['Content-Type' => 'application/json'],
            $responseBody
        );

        $psrRequest = new \GuzzleHttp\Psr7\Request('POST', '/mock-query');

        $request = new \Ntanduy\CFD1\D1\Requests\D1QueryRequest($this, 'mock-db', $query, $params);
        $pendingRequest = new \Saloon\Http\PendingRequest($this, $request);

        return new Response($psrResponse, $pendingRequest, $psrRequest);
    }
}
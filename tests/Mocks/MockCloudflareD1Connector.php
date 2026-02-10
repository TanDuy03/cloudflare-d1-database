<?php

declare(strict_types=1);

namespace Ntanduy\CFD1\Test\Mocks;

use Ntanduy\CFD1\CloudflareD1Connector;
use Ntanduy\CFD1\D1\Requests\D1QueryRequest;
use PDO;
use PDOException;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Saloon\Http\PendingRequest;

class MockCloudflareD1Connector extends CloudflareD1Connector
{
    // Use static to persist DB state across connection re-resolves within a test
    private static ?PDO $sqlite = null;

    public function __construct(...$args)
    {
        parent::__construct(...$args);
        $this->ensureDatabaseExists();
        $this->setupMockClient();
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

    private function setupMockClient(): void
    {
        $mockClient = new MockClient([
            D1QueryRequest::class => function (PendingRequest $pendingRequest) {
                // Extract request data
                $request = $pendingRequest->getRequest();

                if ($request instanceof D1QueryRequest) {
                    // Use reflection to get protected properties
                    $reflection = new \ReflectionClass($request);

                    $sqlProperty = $reflection->getProperty('sql');
                    $sqlProperty->setAccessible(true);
                    $query = $sqlProperty->getValue($request);

                    $paramsProperty = $reflection->getProperty('sqlParams');
                    $paramsProperty->setAccessible(true);
                    $params = $paramsProperty->getValue($request);

                    // Execute against in-memory SQLite
                    $responseBody = $this->executeSqliteQuery($query, $params);

                    return MockResponse::make($responseBody, 200);
                }

                // Default success response
                return MockResponse::make([
                    'success' => true,
                    'errors' => [],
                    'messages' => [],
                    'result' => [['results' => [], 'success' => true]],
                ], 200);
            },
        ]);

        $this->withMockClient($mockClient);
    }

    private function executeSqliteQuery(string $query, array $params): array
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
                ],
            ];
        }

        // Construct D1-compatible JSON response
        return [
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
                        'rows_written' => $changes,
                    ],
                ],
            ],
        ];
    }
}

<?php

namespace Ntanduy\CFD1\Test\Mocks;

use Ntanduy\CFD1\CloudflareD1Connector;
use Saloon\Http\Response;

class MockCloudflareD1Connector extends CloudflareD1Connector
{
    private static $idCounter = 1;
    private static $users = []; // Store created users

    public function databaseQuery(string $query, array $params): Response
    {
        $results = [];
        $lastRowId = 0;
        $changes = 0;

        // Handle different query types
        if (str_contains($query, 'INSERT') || str_contains($query, 'insert')) {
            // For INSERT queries, return a row ID
            $lastRowId = self::$idCounter;
            $changes = 1;

            // Store user data if it's a user insert
            if (str_contains($query, 'users')) {
                self::$users[self::$idCounter] = [
                    'id'                => self::$idCounter,
                    'name'              => $params[0] ?? 'Test User',
                    'email'             => $params[1] ?? 'test'.self::$idCounter.'@example.com',
                    'email_verified_at' => null,
                    'password'          => $params[2] ?? '$2y$10$TKh8H1.PfQx37YgCzwiKb.KjNyWgaHb9cbcoQgdIVFlYg7B77UdFm',
                    'remember_token'    => $params[3] ?? null,
                    'created_at'        => date('Y-m-d H:i:s'),
                    'updated_at'        => date('Y-m-d H:i:s'),
                ];
            }

            self::$idCounter++;
        } elseif (str_contains($query, 'SELECT') || str_contains($query, 'select')) {
            // For SELECT queries, return mock data if needed
            if (str_contains($query, 'exists')) {
                // For migration existence checks
                $results = [['exists' => 1]];
            } elseif (str_contains($query, 'migrations')) {
                // For migration queries, return empty migrations
                $results = [];
            } elseif (str_contains($query, 'users')) {
                // For user queries, return stored users or specific user
                if (!empty($params)) {
                    // If there are params, look for specific user
                    foreach (self::$users as $user) {
                        // Check if param matches ID or email
                        if (in_array($user['id'], $params) || in_array($user['email'], $params)) {
                            $results = [$user];
                            break;
                        }
                    }
                } else {
                    // Return all users
                    $results = array_values(self::$users);
                }
            }
        }

        // Create response body
        $responseBody = json_encode([
            'success' => true,
            'result'  => [
                [
                    'results' => $results,
                    'meta'    => [
                        'served_by'    => 'v1.2.3',
                        'duration'     => 0.1,
                        'changes'      => $changes,
                        'last_row_id'  => $lastRowId,
                        'rows_read'    => count($results),
                        'rows_written' => $changes,
                    ],
                ],
            ],
        ]);

        // Create PSR-7 response
        $psrResponse = new \GuzzleHttp\Psr7\Response(
            200,
            ['Content-Type' => 'application/json'],
            $responseBody
        );

        // Create PSR-7 request
        $psrRequest = new \GuzzleHttp\Psr7\Request('POST', '/test');

        // Create Saloon request and pending request
        $request = new \Ntanduy\CFD1\D1\Requests\D1QueryRequest($this, 'test', $query, $params);
        $pendingRequest = new \Saloon\Http\PendingRequest($this, $request);

        return new Response($psrResponse, $pendingRequest, $psrRequest);
    }
}

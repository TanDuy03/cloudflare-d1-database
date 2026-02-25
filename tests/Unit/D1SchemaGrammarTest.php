<?php

declare(strict_types=1);

use Ntanduy\CFD1\D1\D1SchemaGrammar;

/*
|--------------------------------------------------------------------------
| D1SchemaGrammar – replaceSystemTable / compileDropAll* coverage
|--------------------------------------------------------------------------
|
| Each test manipulates the static $supportsSchemaParameter property via
| Reflection so both branches (true / false) are exercised.  An afterEach
| hook restores the property to null so detection runs fresh in other tests.
|
*/

// ── helpers ─────────────────────────────────────────────────────────────

function setSupportsSchemaParameter(bool $value): void
{
    $ref = new ReflectionProperty(D1SchemaGrammar::class, 'supportsSchemaParameter');
    $ref->setValue(null, $value);
}

afterEach(function () {
    // Reset to null so the auto-detection runs cleanly in future tests
    $ref = new ReflectionProperty(D1SchemaGrammar::class, 'supportsSchemaParameter');
    $ref->setValue(null, null);
});

// ── replaceSystemTable ─────────────────────────────────────────────────

it('replaces sqlite_master with sqlite_schema in a given SQL string', function () {
    setSupportsSchemaParameter(true);
    $grammar = new D1SchemaGrammar;

    $method = new ReflectionMethod($grammar, 'replaceSystemTable');

    $input = "delete from \"main\".sqlite_master where type in ('table', 'index', 'trigger')";
    $result = $method->invoke($grammar, $input);

    expect($result)->toBe("delete from \"main\".sqlite_schema where type in ('table', 'index', 'trigger')");
});

it('returns the string unchanged when sqlite_master is not present', function () {
    setSupportsSchemaParameter(true);
    $grammar = new D1SchemaGrammar;

    $method = new ReflectionMethod($grammar, 'replaceSystemTable');

    $input = 'select * from users';
    $result = $method->invoke($grammar, $input);

    expect($result)->toBe('select * from users');
});

// ── compileDropAllTables ───────────────────────────────────────────────

it('compileDropAllTables replaces sqlite_master with sqlite_schema when supportsSchemaParameter is true', function () {
    setSupportsSchemaParameter(true);
    $grammar = new D1SchemaGrammar;

    $sql = $grammar->compileDropAllTables();

    expect($sql)->toContain('sqlite_schema')
        ->and($sql)->not->toContain('sqlite_master');
});

it('compileDropAllTables replaces sqlite_master with sqlite_schema when supportsSchemaParameter is false', function () {
    setSupportsSchemaParameter(false);
    $grammar = new D1SchemaGrammar;

    $sql = $grammar->compileDropAllTables();

    expect($sql)->toContain('sqlite_schema')
        ->and($sql)->not->toContain('sqlite_master');
});

it('compileDropAllTables produces correct SQL with a custom schema when supportsSchemaParameter is true', function () {
    setSupportsSchemaParameter(true);
    $grammar = new D1SchemaGrammar;

    $sql = $grammar->compileDropAllTables('custom');

    expect($sql)->toContain('sqlite_schema')
        ->and($sql)->not->toContain('sqlite_master');
});

// ── compileDropAllViews ────────────────────────────────────────────────

it('compileDropAllViews replaces sqlite_master with sqlite_schema when supportsSchemaParameter is true', function () {
    setSupportsSchemaParameter(true);
    $grammar = new D1SchemaGrammar;

    $sql = $grammar->compileDropAllViews();

    expect($sql)->toContain('sqlite_schema')
        ->and($sql)->not->toContain('sqlite_master');
});

it('compileDropAllViews replaces sqlite_master with sqlite_schema when supportsSchemaParameter is false', function () {
    setSupportsSchemaParameter(false);
    $grammar = new D1SchemaGrammar;

    $sql = $grammar->compileDropAllViews();

    expect($sql)->toContain('sqlite_schema')
        ->and($sql)->not->toContain('sqlite_master');
});

it('compileDropAllViews produces correct SQL with a custom schema when supportsSchemaParameter is true', function () {
    setSupportsSchemaParameter(true);
    $grammar = new D1SchemaGrammar;

    $sql = $grammar->compileDropAllViews('custom');

    expect($sql)->toContain('sqlite_schema')
        ->and($sql)->not->toContain('sqlite_master');
});

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

// ── compileGetAllTables ────────────────────────────────────────────────

it('compileGetAllTables returns the correct SQL', function () {
    setSupportsSchemaParameter(true);
    $grammar = new D1SchemaGrammar;

    expect($grammar->compileGetAllTables())
        ->toBe("select name, type from sqlite_schema where type = 'table' and name not like 'sqlite_%'");
});

// ── compileGetAllViews ─────────────────────────────────────────────────

it('compileGetAllViews returns the correct SQL', function () {
    setSupportsSchemaParameter(true);
    $grammar = new D1SchemaGrammar;

    expect($grammar->compileGetAllViews())
        ->toBe("select name, type from sqlite_schema where type = 'view'");
});

// ── compileTableExists ─────────────────────────────────────────────────

it('compileTableExists returns Laravel 10 SQL when called with zero arguments', function () {
    setSupportsSchemaParameter(true);
    $grammar = new D1SchemaGrammar;

    // Calling with NO arguments triggers the func_num_args() === 0 branch
    $sql = $grammar->compileTableExists();

    expect($sql)->toBe("select * from sqlite_schema where type = 'table' and name = ?");
});

// ── detectSchemaParameterSupport catch block ───────────────────────────

it('detectSchemaParameterSupport returns false when ReflectionException is thrown', function () {
    // Reset cache so the constructor triggers fresh detection
    $ref = new ReflectionProperty(D1SchemaGrammar::class, 'supportsSchemaParameter');
    $ref->setValue(null, null);

    // Subclass overrides only getParentClassForDetection() to return stdClass
    // (which has no compileDropAllTables), triggering ReflectionException in
    // the original detectSchemaParameterSupport() catch block.
    $grammar = new class extends D1SchemaGrammar
    {
        protected function getParentClassForDetection(): string
        {
            return \stdClass::class;
        }
    };

    $method = new ReflectionMethod(D1SchemaGrammar::class, 'detectSchemaParameterSupport');
    $result = $method->invoke($grammar);

    expect($result)->toBeFalse();
});

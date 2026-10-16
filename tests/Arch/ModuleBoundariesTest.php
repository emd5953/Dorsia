<?php

declare(strict_types=1);

/*
| Encodes every rule of the C§2 "Boundary rules" list. Two layers:
|  - Pest arch expectations (readable, class-level), and
|  - a comment-stripped source scan that also catches FQCN strings, non-class files
|    (config.php, routes, migrations) and raw SQL, which arch expectations cannot see.
| The scan helpers are exercised against synthetic sources below, so the test proves
| that a forbidden import really fails the rule.
*/

use Illuminate\Support\Str;

/** @return list<string> */
function archModules(): array
{
    return ['Inventory', 'Pricing', 'Reservations', 'Wallet', 'Payments', 'Realtime'];
}

/**
 * C§2 dependency table: what each module may use (besides Shared).
 *
 * @return array<string, list<string>>
 */
function archAllowedDependencies(): array
{
    return [
        'Inventory' => [],
        'Pricing' => ['Inventory'],
        'Wallet' => [],
        'Payments' => [],
        'Realtime' => [],
        'Reservations' => ['Inventory', 'Pricing', 'Wallet', 'Payments'],
    ];
}

/** @return list<string> */
function archPublicLayers(): array
{
    return ['Actions', 'Data', 'Enums', 'Events', 'Exceptions', 'Contracts'];
}

/** @return list<string> */
function archInternalLayers(): array
{
    return ['Models', 'Http', 'Console', 'Jobs', 'Listeners', 'Support', 'Database'];
}

/**
 * Tables owned by each module (C§3).
 *
 * @return array<string, list<string>>
 */
function archOwnedTables(): array
{
    return [
        'Inventory' => ['cities', 'venues', 'drops', 'slots', 'holds'],
        'Pricing' => ['search_events', 'demand_signals', 'price_quotes'],
        'Wallet' => ['ledger_accounts', 'ledger_transactions', 'ledger_entries'],
        'Payments' => ['payments', 'payment_webhook_events'],
        'Reservations' => ['reservations'],
        'Realtime' => [],
    ];
}

/** @return list<string> */
function archPhpFiles(string $directory): array
{
    if (! is_dir($directory)) {
        return [];
    }

    $files = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file instanceof SplFileInfo && $file->isFile() && $file->getExtension() === 'php') {
            $files[] = $file->getPathname();
        }
    }

    sort($files);

    return $files;
}

function archStripComments(string $source): string
{
    $code = '';

    foreach (token_get_all($source) as $token) {
        if (is_array($token)) {
            if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                continue;
            }

            $code .= $token[1];
        } else {
            $code .= $token;
        }
    }

    return $code;
}

/**
 * Module-to-module rules: dependency table and public-layers-only.
 *
 * @return list<string>
 */
function archModuleViolations(string $module, string $path, string $source): array
{
    $violations = [];
    $allowed = archAllowedDependencies()[$module] ?? [];

    preg_match_all('/App\\\\{1,2}Modules\\\\{1,2}(\w+)(?:\\\\{1,2}(\w+))?/', archStripComments($source), $matches, PREG_SET_ORDER);

    foreach ($matches as $match) {
        $target = $match[1];
        $layer = $match[2] ?? '';

        if ($target === $module) {
            continue;
        }

        if (! in_array($target, $allowed, true)) {
            $violations[] = "{$path}: {$module} must not use {$target} (".$match[0].')';

            continue;
        }

        if (! in_array($layer, archPublicLayers(), true)) {
            $violations[] = "{$path}: {$module} may use {$target} only through ".implode('/', archPublicLayers()).' ('.$match[0].')';
        }
    }

    return $violations;
}

/**
 * @return list<string>
 */
function archSharedViolations(string $path, string $source): array
{
    return preg_match('/App\\\\{1,2}Modules\\b/', archStripComments($source)) === 1
        ? ["{$path}: App\\Shared must not use App\\Modules"]
        : [];
}

/**
 * Cross-module raw SQL, exists: rules and foreign keys.
 *
 * @return list<string>
 */
function archDataViolations(string $module, string $path, string $source): array
{
    $code = archStripComments($source);
    $violations = [];

    $foreign = [];

    foreach (archOwnedTables() as $owner => $tables) {
        if ($owner !== $module) {
            array_push($foreign, ...$tables);
        }
    }

    // Modules never query members directly (C§4); other modules' tables are off limits too.
    $forbiddenQueries = [...$foreign, 'members'];
    $queryTables = implode('|', array_map(preg_quote(...), $forbiddenQueries));

    $queryPatterns = [
        '/\b(?:from|join|into|update)\s+"?('.$queryTables.')"?(?![\w])/i',
        '/\b(?:table|from|join|leftJoin|rightJoin|crossJoin|joinSub)\(\s*[\'"]('.$queryTables.')(?:\s+as\s+\w+)?[\'"]/',
        '/\bexists:('.$queryTables.')\b/',
        '/Rule::exists\(\s*[\'"]('.$queryTables.')[\'"]/',
    ];

    foreach ($queryPatterns as $pattern) {
        if (preg_match_all($pattern, $code, $found) > 0) {
            foreach ($found[1] as $table) {
                $violations[] = "{$path}: {$module} must not touch table {$table} directly";
            }
        }
    }

    // Foreign keys: only within the module and to members.id.
    $foreignKeyPatterns = [
        '/constrained\(\s*[\'"](\w+)[\'"]/',
        '/->on\(\s*[\'"](\w+)[\'"]\s*\)/',
        '/REFERENCES\s+"?(\w+)"?/i',
        '/foreign(?:Id|Uuid)\(\s*[\'"](\w+)_id[\'"]\s*\)(?:->\w+\([^)]*\))*->constrained\(\s*\)/',
    ];

    foreach ($foreignKeyPatterns as $index => $pattern) {
        if (preg_match_all($pattern, $code, $found) === 0) {
            continue;
        }

        foreach ($found[1] as $name) {
            $table = $index === 3 ? Str::plural($name) : $name;

            if (in_array($table, $foreign, true)) {
                $violations[] = "{$path}: foreign key to {$table} crosses a module boundary";
            }
        }
    }

    return $violations;
}

/**
 * @param  callable(string, string): list<string>  $check  (path, source) => violations
 * @return list<string>
 */
function archScan(string $directory, callable $check): array
{
    $violations = [];

    foreach (archPhpFiles($directory) as $file) {
        array_push($violations, ...$check($file, (string) file_get_contents($file)));
    }

    return $violations;
}

/**
 * Debug functions and env() called as plain functions (comments and strings ignored).
 *
 * @param  list<string>  $functions
 * @return list<string>
 */
function archCalledFunctions(string $source, array $functions): array
{
    $tokens = PhpToken::tokenize($source);
    $significant = array_values(array_filter(
        $tokens,
        fn (PhpToken $t): bool => ! $t->isIgnorable(),
    ));
    $found = [];

    foreach ($significant as $i => $token) {
        $name = ltrim($token->text, '\\');

        if (! $token->is([T_STRING, T_NAME_FULLY_QUALIFIED]) || ! in_array(strtolower($name), $functions, true)) {
            continue;
        }

        $next = $significant[$i + 1] ?? null;
        $previous = $significant[$i - 1] ?? null;

        if ($next === null || $next->text !== '(') {
            continue;
        }

        if ($previous !== null && in_array($previous->text, ['->', '?->', '::', 'function', 'const', 'new'], true)) {
            continue;
        }

        $found[] = $name;
    }

    return $found;
}

// ---------------------------------------------------------------------------
// Module-to-module rules
// ---------------------------------------------------------------------------

foreach (archModules() as $module) {
    $otherModules = array_values(array_diff(archModules(), [$module]));
    $forbiddenModules = array_values(array_diff($otherModules, archAllowedDependencies()[$module]));

    if ($forbiddenModules !== []) {
        arch("{$module} does not use modules outside its dependency list")
            ->expect("App\\Modules\\{$module}")
            ->not->toUse(array_map(fn (string $m): string => "App\\Modules\\{$m}", $forbiddenModules));
    }

    $internals = [];

    foreach ($otherModules as $other) {
        foreach (archInternalLayers() as $layer) {
            $internals[] = "App\\Modules\\{$other}\\{$layer}";
        }
    }

    arch("{$module} never uses another module's internals")
        ->expect("App\\Modules\\{$module}")
        ->not->toUse($internals);

    it("{$module} source only references other modules as the dependency table allows", function () use ($module): void {
        $violations = archScan(
            app_path("Modules/{$module}"),
            fn (string $path, string $source): array => archModuleViolations($module, $path, $source),
        );

        expect($violations)->toBe([]);
    });

    it("{$module} has no cross-module raw SQL, exists: rules or foreign keys", function () use ($module): void {
        $violations = archScan(
            app_path("Modules/{$module}"),
            fn (string $path, string $source): array => archDataViolations($module, $path, $source),
        );

        expect($violations)->toBe([]);
    });
}

arch('App\Shared does not use App\Modules')
    ->expect('App\Shared')
    ->not->toUse('App\Modules');

it('App\Shared source does not reference App\Modules', function (): void {
    $violations = archScan(
        app_path('Shared'),
        fn (string $path, string $source): array => archSharedViolations($path, $source),
    );

    expect($violations)->toBe([]);
});

// ---------------------------------------------------------------------------
// Code hygiene
// ---------------------------------------------------------------------------

arch('every class in app declares strict types')
    ->expect('App')
    ->toUseStrictTypes();

it('every file in app declares strict_types=1', function (): void {
    $missing = [];

    foreach (archPhpFiles(app_path()) as $file) {
        $code = archStripComments((string) file_get_contents($file));

        if (preg_match('/^<\?php\s+declare\(\s*strict_types\s*=\s*1\s*\)\s*;/', $code) !== 1) {
            $missing[] = $file;
        }
    }

    expect($missing)->toBe([]);
});

it('has no debug functions anywhere in app', function (): void {
    $found = [];

    foreach (archPhpFiles(app_path()) as $file) {
        foreach (archCalledFunctions((string) file_get_contents($file), ['dd', 'dump', 'var_dump', 'ray', 'print_r', 'var_export']) as $name) {
            $found[] = "{$file}: {$name}()";
        }
    }

    expect($found)->toBe([]);
});

it('calls env() only in config files', function (): void {
    $found = [];

    foreach (archPhpFiles(app_path()) as $file) {
        if (basename($file) === 'config.php' && preg_match('#/Modules/\w+/config\.php$#', $file) === 1) {
            continue;
        }

        if (archCalledFunctions((string) file_get_contents($file), ['env']) !== []) {
            $found[] = $file;
        }
    }

    expect($found)->toBe([]);
});

it('keeps database, routes and bootstrap code free of debug functions too', function (): void {
    $found = [];

    foreach ([database_path(), base_path('routes'), base_path('bootstrap')] as $directory) {
        foreach (archPhpFiles($directory) as $file) {
            if (str_contains($file, '/bootstrap/cache/')) {
                continue;
            }

            foreach (archCalledFunctions((string) file_get_contents($file), ['dd', 'dump', 'var_dump', 'ray']) as $name) {
                $found[] = "{$file}: {$name}()";
            }
        }
    }

    expect($found)->toBe([]);
});

// ---------------------------------------------------------------------------
// DTOs and actions
// ---------------------------------------------------------------------------

/**
 * @return list<class-string>
 */
function archClassesIn(string $layer): array
{
    $classes = [];

    foreach (archModules() as $module) {
        foreach (archPhpFiles(app_path("Modules/{$module}/{$layer}")) as $file) {
            $relative = Str::after($file, app_path().'/');
            /** @var class-string $class */
            $class = 'App\\'.str_replace('/', '\\', Str::beforeLast($relative, '.php'));
            $classes[] = $class;
        }
    }

    return $classes;
}

it('declares every Data class final readonly', function (): void {
    $offenders = [];

    foreach (archClassesIn('Data') as $class) {
        $reflection = new ReflectionClass($class);

        if (! $reflection->isFinal() || ! $reflection->isReadOnly()) {
            $offenders[] = $class;
        }
    }

    expect($offenders)->toBe([]);
});

it('keeps Action classes non-final with exactly one public method, handle()', function (): void {
    $offenders = [];

    foreach (archClassesIn('Actions') as $class) {
        $reflection = new ReflectionClass($class);

        $public = array_values(array_map(
            fn (ReflectionMethod $m): string => $m->getName(),
            array_filter(
                $reflection->getMethods(ReflectionMethod::IS_PUBLIC),
                fn (ReflectionMethod $m): bool => $m->getName() !== '__construct',
            ),
        ));

        if ($reflection->isFinal() || $public !== ['handle']) {
            $offenders[] = $class.' ['.implode(',', $public).']'.($reflection->isFinal() ? ' final' : '');
        }
    }

    expect($offenders)->toBe([]);
});

// ---------------------------------------------------------------------------
// The scanners themselves: a forbidden import must fail, an allowed one must not
// ---------------------------------------------------------------------------

it('flags a module importing another module\'s internals', function (): void {
    $source = "<?php\n\nnamespace App\\Modules\\Inventory\\Actions;\n\nuse App\\Modules\\Wallet\\Models\\X;\n";

    expect(archModuleViolations('Inventory', 'Inventory/Actions/A.php', $source))->not->toBe([]);
});

it('flags the same import written as a string, not only as a use statement', function (): void {
    $source = "<?php\n\n\$relation = 'App\\\\Modules\\\\Wallet\\\\Models\\\\X';\n";

    expect(archModuleViolations('Inventory', 'Inventory/Models/A.php', $source))->not->toBe([]);
});

it('flags a dependency outside the table even when the layer is public', function (): void {
    $source = "<?php\n\nuse App\\Modules\\Reservations\\Actions\\PlaceHold;\n";

    expect(archModuleViolations('Inventory', 'p.php', $source))->not->toBe([])
        ->and(archModuleViolations('Pricing', 'p.php', $source))->not->toBe([])
        ->and(archModuleViolations('Wallet', 'p.php', "<?php\nuse App\\Modules\\Pricing\\Data\\Quote;\n"))->not->toBe([]);
});

it('flags an allowed dependency used through an internal layer', function (): void {
    $source = "<?php\n\nuse App\\Modules\\Inventory\\Models\\Slot;\n";

    expect(archModuleViolations('Pricing', 'p.php', $source))->not->toBe([])
        ->and(archModuleViolations('Reservations', 'p.php', $source))->not->toBe([]);
});

it('accepts allowed dependencies through public layers and ignores comments', function (): void {
    $source = "<?php\n\nuse App\\Modules\\Inventory\\Actions\\GetSlot;\nuse App\\Modules\\Inventory\\Data\\SlotData;\nuse App\\Modules\\Inventory\\Events\\AvailabilitySearched;\n// use App\\Modules\\Wallet\\Models\\X;\n";

    expect(archModuleViolations('Pricing', 'p.php', $source))->toBe([])
        ->and(archModuleViolations('Reservations', 'p.php', "<?php\nuse App\\Modules\\Payments\\Contracts\\PaymentGateway;\nuse App\\Modules\\Wallet\\Actions\\PostLedgerTransaction;\n"))->toBe([]);
});

it('flags App\Shared importing App\Modules', function (): void {
    expect(archSharedViolations('Shared/X.php', "<?php\nuse App\\Modules\\Inventory\\Actions\\GetSlot;\n"))->not->toBe([])
        ->and(archSharedViolations('Shared/X.php', "<?php\nuse App\\Shared\\Money\\Money;\n"))->toBe([]);
});

it('flags cross-module raw SQL, exists rules and foreign keys', function (): void {
    expect(archDataViolations('Pricing', 'p.php', "<?php\nDB::select('select * from slots join holds on 1=1');"))->not->toBe([])
        ->and(archDataViolations('Pricing', 'p.php', "<?php\nDB::table('members')->get();"))->not->toBe([])
        ->and(archDataViolations('Wallet', 'p.php', "<?php\n\$r = ['hold_id' => 'exists:holds,id'];"))->not->toBe([])
        ->and(archDataViolations('Reservations', 'p.php', "<?php\n\$t->foreignId('slot_id')->constrained();"))->not->toBe([])
        ->and(archDataViolations('Reservations', 'p.php', "<?php\n\$t->foreignId('hold_id')->constrained('holds');"))->not->toBe([]);
});

it('accepts own-module tables, FKs to members and comments mentioning other tables', function (): void {
    expect(archDataViolations('Inventory', 'p.php', "<?php\nDB::table('slots')->update([]);\nDB::statement('UPDATE holds SET x = 1');"))->toBe([])
        ->and(archDataViolations('Reservations', 'p.php', "<?php\n\$t->foreignId('member_id')->constrained('members');"))->toBe([])
        ->and(archDataViolations('Inventory', 'p.php', "<?php\n// update members from the old table\n"))->toBe([]);
});

it('detects debug and env calls but not look-alikes', function (): void {
    expect(archCalledFunctions("<?php\ndd(\$x);", ['dd']))->toBe(['dd'])
        ->and(archCalledFunctions("<?php\n\\dump(\$x);", ['dump']))->toBe(['dump'])
        ->and(archCalledFunctions("<?php\n\$y = env('A');", ['env']))->toBe(['env'])
        ->and(archCalledFunctions("<?php\n\$o->dd(); Foo::dump(); \$s = 'dd(1)'; // dd(2)\nfunction ray() {}", ['dd', 'dump', 'ray']))->toBe([]);
});

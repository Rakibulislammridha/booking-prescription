<?php

declare(strict_types=1);

namespace App\Support\PhpStan;

use PhpParser\Node;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\GroupUse;
use PhpParser\Node\Stmt\Namespace_;
use PhpParser\Node\Stmt\Property;
use PhpParser\Node\Stmt\Use_;
use PhpParser\Node\UseItem;
use PhpParser\NodeFinder;
use PHPStan\Analyser\Scope;
use PHPStan\Node\FileNode;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Invariant I6 (BRIEF §8 "No prescription rendering path joins live to the `catalog` database", ARCHITECTURE §8.3).
 *
 * A prescription is a legal document: `prescriptions.snapshot` is frozen at issue time and is the ONLY render source,
 * which is what makes a 2026 prescription still print byte for byte in 2036 after the brand it names was renamed,
 * re-priced or deactivated in the shared catalog. This rule fails the build when anything under
 * `App\Domain\Prescription\Render` reaches for the catalog: a reference to an `App\Models\Catalog\*` class (import,
 * `new`, static call, `::class`, `instanceof`, parameter/return/property type) or the `catalog`/`catalog_admin`
 * connection by name (`DB::connection('catalog')`, `->connection('catalog')`, `Model::on('catalog')`,
 * `$connection = 'catalog'`, `config('database.connections.catalog…')`).
 *
 * Scope is deliberately the RENDER namespace only. `App\Domain\Prescription\Services\SnapshotBuilder` runs at ISSUE
 * time and legitimately reads the catalog to build the snapshot — the invariant is about rendering, not about writing.
 * Blade templates are not analysed by PHPStan (`paths: [app, database, routes, tests]`); they receive only the array
 * `PrescriptionRenderer::data()` builds from the snapshot DTO, so guarding the renderer classes guards the views too.
 *
 * Implemented on `FileNode` (one callback per analysed file, carrying every top-level statement) rather than on a
 * dozen expression node types, so a new syntax position cannot quietly escape the rule. Names are resolved from the
 * file's own `namespace`/`use` statements rather than from a parser attribute, so the rule does not lean on PHPStan
 * internals.
 *
 * @implements Rule<FileNode>
 */
final class NoCatalogModelsInRendering implements Rule
{
    public const IDENTIFIER = 'bp.catalogInRendering';

    /** Namespace whose files may not touch the catalog. */
    private const RENDER_NAMESPACE = 'App\\Domain\\Prescription\\Render';

    /** Class namespace that must never be referenced from there. */
    private const CATALOG_NAMESPACE = 'App\\Models\\Catalog';

    /** Connection names that reach the shared catalog database. */
    private const CATALOG_CONNECTIONS = ['catalog', 'catalog_admin'];

    /** Methods that select a connection by name. */
    private const CONNECTION_METHODS = ['connection', 'setconnection', 'on'];

    private const TIP = 'BRIEF §8 / ARCHITECTURE §8.3: the frozen PrescriptionSnapshot is the only render source, so a renamed or deactivated catalog row can never change an already issued prescription. Read the value off the snapshot instead.';

    public function getNodeType(): string
    {
        return FileNode::class;
    }

    /**
     * @param  FileNode  $node
     * @return list<IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        $statements = $node->getNodes();
        $namespace = $this->namespaceOf($statements);

        if ($namespace === null || ! $this->isRenderNamespace($namespace)) {
            return [];
        }

        $finder = new NodeFinder;

        $errors = [];
        $aliases = [];
        $importedNames = [];

        foreach ($this->imports($statements, $finder) as [$useItem, $imported]) {
            $importedNames[spl_object_id($useItem->name)] = true;

            if ($imported === null) {
                continue;   // a `use function` / `use const` import can never name a class
            }

            $aliases[strtolower($useItem->getAlias()->toString())] = $imported;

            if ($this->isCatalogClass($imported)) {
                $errors[] = $this->classError($imported, $useItem->getStartLine());
            }
        }

        /** @var list<Name> $names */
        $names = $finder->findInstanceOf($statements, Name::class);

        foreach ($names as $name) {
            if (isset($importedNames[spl_object_id($name)])) {
                continue;   // handled by the `use` pass above
            }

            $resolved = $this->resolve($name, $namespace, $aliases);

            if ($resolved !== null && $this->isCatalogClass($resolved)) {
                $errors[] = $this->classError($resolved, $name->getStartLine());
            }
        }

        foreach ($this->catalogConnectionLines($statements, $finder) as $line => $connection) {
            $errors[] = $this->connectionError($connection, $line);
        }

        return $errors;
    }

    /**
     * @param  array<Node>  $statements
     */
    private function namespaceOf(array $statements): ?string
    {
        foreach ($statements as $statement) {
            if ($statement instanceof Namespace_) {
                return $statement->name?->toString();
            }
        }

        return null;
    }

    private function isRenderNamespace(string $namespace): bool
    {
        return $namespace === self::RENDER_NAMESPACE || str_starts_with($namespace, self::RENDER_NAMESPACE.'\\');
    }

    private function isCatalogClass(string $class): bool
    {
        return str_starts_with(ltrim($class, '\\'), self::CATALOG_NAMESPACE.'\\');
    }

    /**
     * Every class import in the file as `[item, fully qualified name]`; the name is null for `use function`/`use const`,
     * which can never denote a class. `use A\{B, C\D}` group prefixes are expanded.
     *
     * @param  array<Node>  $statements
     * @return list<array{UseItem, string|null}>
     */
    private function imports(array $statements, NodeFinder $finder): array
    {
        $imports = [];

        /** @var list<Use_> $uses */
        $uses = $finder->findInstanceOf($statements, Use_::class);

        foreach ($uses as $use) {
            foreach ($use->uses as $item) {
                $imports[] = [$item, $this->isClassImport($use->type) ? $item->name->toString() : null];
            }
        }

        /** @var list<GroupUse> $groupUses */
        $groupUses = $finder->findInstanceOf($statements, GroupUse::class);

        foreach ($groupUses as $groupUse) {
            foreach ($groupUse->uses as $item) {
                $type = $item->type === Use_::TYPE_UNKNOWN ? $groupUse->type : $item->type;

                $imports[] = [$item, $this->isClassImport($type) ? $groupUse->prefix->toString().'\\'.$item->name->toString() : null];
            }
        }

        return $imports;
    }

    private function isClassImport(int $type): bool
    {
        return $type === Use_::TYPE_NORMAL || $type === Use_::TYPE_UNKNOWN;
    }

    /**
     * @param  array<string, string>  $aliases  lower-cased alias => fully qualified class
     */
    private function resolve(Name $name, string $namespace, array $aliases): ?string
    {
        if (in_array(strtolower($name->toString()), ['self', 'static', 'parent'], true)) {
            return null;
        }

        if ($name->isFullyQualified()) {
            return $name->toString();
        }

        if ($name->isRelative()) {
            return $namespace.'\\'.$name->toString();
        }

        $parts = $name->getParts();
        $first = strtolower($parts[0]);

        if (isset($aliases[$first])) {
            $parts[0] = $aliases[$first];

            return implode('\\', $parts);
        }

        return $namespace.'\\'.$name->toString();
    }

    /**
     * Lines that name a catalog connection, as line => connection name.
     *
     * @param  array<Node>  $statements
     * @return array<int, string>
     */
    private function catalogConnectionLines(array $statements, NodeFinder $finder): array
    {
        $found = [];

        /** @var list<MethodCall|StaticCall> $calls */
        $calls = $finder->find($statements, static fn (Node $n): bool => $n instanceof MethodCall || $n instanceof StaticCall);

        foreach ($calls as $call) {
            if (! $call->name instanceof Identifier || ! in_array(strtolower($call->name->toString()), self::CONNECTION_METHODS, true)) {
                continue;
            }

            $value = ($call->getArgs()[0] ?? null)?->value;

            if ($value instanceof String_ && in_array($value->value, self::CATALOG_CONNECTIONS, true)) {
                $found[$call->getStartLine()] = $value->value;
            }
        }

        /** @var list<FuncCall> $funcCalls */
        $funcCalls = $finder->findInstanceOf($statements, FuncCall::class);

        foreach ($funcCalls as $funcCall) {
            if (! $funcCall->name instanceof Name || ! in_array(strtolower($funcCall->name->toString()), ['config', 'env'], true)) {
                continue;
            }

            $value = ($funcCall->getArgs()[0] ?? null)?->value;

            if ($value instanceof String_ && str_starts_with($value->value, 'database.connections.catalog')) {
                $found[$funcCall->getStartLine()] = 'catalog';
            }
        }

        /** @var list<Property> $properties */
        $properties = $finder->findInstanceOf($statements, Property::class);

        foreach ($properties as $property) {
            foreach ($property->props as $prop) {
                $default = $prop->default;

                if ($prop->name->toString() === 'connection' && $default instanceof String_ && in_array($default->value, self::CATALOG_CONNECTIONS, true)) {
                    $found[$prop->getStartLine()] = $default->value;
                }
            }
        }

        return $found;
    }

    private function classError(string $class, int $line): IdentifierRuleError
    {
        return RuleErrorBuilder::message(sprintf('Prescription rendering must not reference the catalog: %s is used inside %s.', $class, self::RENDER_NAMESPACE))
            ->identifier(self::IDENTIFIER)
            ->tip(self::TIP)
            ->line($line)
            ->build();
    }

    private function connectionError(string $connection, int $line): IdentifierRuleError
    {
        return RuleErrorBuilder::message(sprintf("Prescription rendering must not use the '%s' database connection inside %s.", $connection, self::RENDER_NAMESPACE))
            ->identifier(self::IDENTIFIER)
            ->tip(self::TIP)
            ->line($line)
            ->build();
    }
}

<?php

declare(strict_types=1);

namespace App\Support\PhpStan;

use PhpParser\Node;
use PhpParser\Node\Expr\ArrayItem;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\AssignRef;
use PhpParser\Node\Expr\List_;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Param;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Catch_;
use PhpParser\Node\Stmt\Foreach_;
use PhpParser\Node\Stmt\Namespace_;
use PhpParser\NodeFinder;
use PHPStan\Analyser\Scope;
use PHPStan\Node\FileNode;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * ARCHITECTURE §5.1 / CATALOG.md §1.2 — "Query-builder writes on the `catalog` connection are forbidden."
 *
 * The `catalog` connection is the SELECT-only runtime role (BRIEF §3, CATALOG.md §1.3). Writes to the shared clinical
 * reference data go through `CatalogWriteContext::run()`, which opens a transaction on the separate `catalog_admin`
 * connection — that is what `catalog:migrate`, `catalog:seed`, `catalog:import` (`Import\Upserter`) and
 * `Actions\PromoteCustomBrand` use. A query-builder write addressed straight at `'catalog'` bypasses the write
 * context, bypasses `CatalogModel`'s read-only model events and leaves no `catalog_version_id`; at runtime the
 * SELECT-only grant turns it into a failure far from the code that caused it.
 *
 * Keyed on the literal connection NAME, so the sanctioned path needs no exemption: anything reached through
 * `'catalog_admin'` is simply never a match, wherever it lives. The rule walks the fluent chain back from a write call
 * to whatever produced the builder, so `DB::connection('catalog')->table('brands')->where(…)->update([…])` is caught
 * at the `update()`, and it follows aliasing (`$catalog = DB::connection('catalog'); $catalog->table(…)->…`) because
 * that shape already exists in the codebase. A variable that is also assigned something else, bound by a `foreach`,
 * destructured, taken as a parameter or caught as an exception is dropped from the alias set, and a non-literal
 * connection name (`DB::connection($name)`) is left alone — the rule under-reports rather than inventing an error.
 *
 * Scope: application code only — a file whose namespace starts with `Tests\` is skipped. That is not a convenience
 * carve-out. `Tests\Feature\Catalog\CatalogModelBaseGuardTest` proves the runtime net by *performing* exactly this
 * write and asserting `CatalogIsReadOnly`; an adversarial test has to be able to write the thing it forbids, and no
 * static check can tell that call apart from a real one without heuristics. Suppressing it with an ignore comment
 * would hide the rule's own regression test, so the narrower scope is stated here instead.
 *
 * Out of scope on purpose, to match exactly what the docs claim: raw connection SQL (`->statement()`,
 * `->unprepared()`, `->affectingStatement()`) is not a query-builder write. It stays covered at runtime by the
 * SELECT-only grant, as `CatalogModelBaseGuardTest::test_the_catalog_connection_refuses_raw_writes_outside_the_write_context`
 * asserts.
 *
 * @implements Rule<FileNode>
 */
final class NoCatalogQueryBuilderWrites implements Rule
{
    public const IDENTIFIER = 'bp.catalogQueryBuilderWrite';

    /** The SELECT-only runtime connection. `catalog_admin` is the sanctioned write role and is never matched. */
    private const READ_ONLY_CONNECTION = 'catalog';

    /** Query-builder (and connection) methods that write. Lower-cased. */
    private const WRITE_METHODS = [
        'insert', 'insertgetid', 'insertorignore', 'insertusing', 'insertorignoreusing',
        'update', 'updatefrom', 'updateorinsert', 'upsert',
        'delete', 'truncate',
        'increment', 'decrement', 'incrementeach', 'decrementeach',
    ];

    private const TIP = 'CATALOG.md §1.3: write through CatalogWriteContext::run(), which opens a transaction on the catalog_admin connection (catalog:migrate / catalog:seed / catalog:import / PromoteCustomBrand). The catalog connection is granted SELECT only.';

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

        if (str_starts_with($this->namespaceOf($statements), 'Tests\\')) {
            return [];
        }

        $finder = new NodeFinder;
        $aliases = $this->aliases($statements, $finder);
        $errors = [];

        /** @var list<MethodCall> $calls */
        $calls = $finder->findInstanceOf($statements, MethodCall::class);

        foreach ($calls as $call) {
            if (! $call->name instanceof Identifier || ! in_array($call->name->toLowerString(), self::WRITE_METHODS, true)) {
                continue;
            }

            if (! $this->rootIsCatalog($call, $aliases)) {
                continue;
            }

            $errors[] = RuleErrorBuilder::message(sprintf(
                "The '%s' connection is read-only: %s() on a query builder built from it is a write that bypasses CatalogWriteContext.",
                self::READ_ONLY_CONNECTION,
                $call->name->toString(),
            ))
                ->identifier(self::IDENTIFIER)
                ->tip(self::TIP)
                ->line($call->getStartLine())
                ->build();
        }

        return $errors;
    }

    /**
     * @param  array<Node>  $statements
     */
    private function namespaceOf(array $statements): string
    {
        foreach ($statements as $statement) {
            if ($statement instanceof Namespace_) {
                return $statement->name?->toString() ?? '';
            }
        }

        return '';
    }

    /**
     * Variable names that unambiguously hold the read-only catalog connection.
     *
     * Collected across the whole file rather than per function: a name can only survive if every binding of it in the
     * file is `DB::connection('catalog')`, so a same-named variable in another function is either bound there (and
     * therefore dropped) or undefined, which is not valid code.
     *
     * @param  array<Node>  $statements
     * @return array<string, true>
     */
    private function aliases(array $statements, NodeFinder $finder): array
    {
        $candidates = [];
        $bound = [];

        /** @var list<Assign> $assignments */
        $assignments = $finder->findInstanceOf($statements, Assign::class);

        foreach ($assignments as $assignment) {
            if ($assignment->var instanceof Variable && is_string($assignment->var->name)) {
                if ($this->isCatalogConnection($assignment->expr)) {
                    $candidates[$assignment->var->name] = true;
                } else {
                    $bound[$assignment->var->name] = true;
                }

                continue;
            }

            $this->markDestructured($assignment->var, $bound);
        }

        foreach ($this->otherBindings($statements, $finder) as $name) {
            $bound[$name] = true;
        }

        return array_diff_key($candidates, $bound);
    }

    /**
     * Every other way a name can be bound to something that is not `DB::connection('catalog')`.
     *
     * @param  array<Node>  $statements
     * @return list<string>
     */
    private function otherBindings(array $statements, NodeFinder $finder): array
    {
        $names = [];

        /** @var list<Param> $params */
        $params = $finder->findInstanceOf($statements, Param::class);

        foreach ($params as $param) {
            if ($param->var instanceof Variable && is_string($param->var->name)) {
                $names[] = $param->var->name;
            }
        }

        /** @var list<Foreach_> $loops */
        $loops = $finder->findInstanceOf($statements, Foreach_::class);

        foreach ($loops as $loop) {
            foreach ([$loop->valueVar, $loop->keyVar] as $variable) {
                if ($variable instanceof Variable && is_string($variable->name)) {
                    $names[] = $variable->name;
                }
            }
        }

        /** @var list<Catch_> $catches */
        $catches = $finder->findInstanceOf($statements, Catch_::class);

        foreach ($catches as $catch) {
            if ($catch->var instanceof Variable && is_string($catch->var->name)) {
                $names[] = $catch->var->name;
            }
        }

        /** @var list<AssignRef> $references */
        $references = $finder->findInstanceOf($statements, AssignRef::class);

        foreach ($references as $reference) {
            if ($reference->var instanceof Variable && is_string($reference->var->name)) {
                $names[] = $reference->var->name;
            }
        }

        return $names;
    }

    /**
     * `[$a, $b] = …` / `list($a, $b) = …` bind their targets to something the rule cannot follow.
     *
     * @param  array<string, true>  $bound
     */
    private function markDestructured(Node $target, array &$bound): void
    {
        if (! $target instanceof List_ && ! $target instanceof Node\Expr\Array_) {
            return;
        }

        foreach ($target->items as $item) {
            if (! $item instanceof ArrayItem) {
                continue;
            }

            if ($item->value instanceof Variable && is_string($item->value->name)) {
                $bound[$item->value->name] = true;

                continue;
            }

            $this->markDestructured($item->value, $bound);
        }
    }

    /**
     * Walks the fluent chain back from a call to whatever produced the builder.
     *
     * @param  array<string, true>  $aliases
     */
    private function rootIsCatalog(MethodCall $call, array $aliases): bool
    {
        $receiver = $call->var;

        while ($receiver instanceof MethodCall) {
            if ($this->isCatalogConnection($receiver)) {
                return true;
            }

            $receiver = $receiver->var;
        }

        if ($receiver instanceof Variable && is_string($receiver->name)) {
            return isset($aliases[$receiver->name]);
        }

        return $this->isCatalogConnection($receiver);
    }

    /**
     * `DB::connection('catalog')`, `$this->db->connection('catalog')` — the literal read-only connection only.
     */
    private function isCatalogConnection(Node $node): bool
    {
        if (! $node instanceof MethodCall && ! $node instanceof StaticCall) {
            return false;
        }

        if (! $node->name instanceof Identifier || $node->name->toLowerString() !== 'connection') {
            return false;
        }

        $value = ($node->getArgs()[0] ?? null)?->value;

        return $value instanceof String_ && $value->value === self::READ_ONLY_CONNECTION;
    }
}

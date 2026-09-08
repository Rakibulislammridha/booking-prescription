<?php

declare(strict_types=1);

namespace App\Support\PhpStan;

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use PhpParser\Node;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Namespace_;
use PhpParser\Node\Stmt\Property;
use PhpParser\NodeFinder;
use PHPStan\Analyser\Scope;
use PHPStan\Parser\Parser;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\Type;
use Throwable;

/**
 * ARCHITECTURE §8.2 — an **ENC** column is `text` holding an `encrypted`-cast payload. Laravel's encrypter uses a fresh
 * IV per value, so two rows holding the same plaintext hold different ciphertext: `where('<enc column>', $value)` can
 * never match and the query silently returns nothing. That is a correctness bug (a lookup that always misses), not a
 * style preference, which is why it is a rule rather than a convention.
 *
 * Flags `where`/`orWhere`/`whereIn`/`orWhereIn`/`whereNot`/… whose first argument is a constant string naming a column
 * the receiver's model casts `encrypted`, `encrypted:array|json|object|collection`, or one of Laravel's `AsEncrypted*`
 * cast classes. `whereNull()`/`whereNotNull()` are deliberately NOT flagged: NULL survives the cast, so those work.
 *
 * The model is resolved from the call receiver through PHPStan's type engine — a `Model` subclass (`Patient::where()`,
 * `$patient->where()`), the `TModel` of an `Illuminate\Database\Eloquent\Builder` (`Patient::query()->where()`, a
 * `Builder<Patient>` typed variable) or the `TRelatedModel` of a `Relation` (`$doctor->patients()->where()`). When the
 * receiver is a bare `Illuminate\Database\Query\Builder` (`DB::table('patients')`) there is no model and therefore no
 * cast list, so the call is left alone.
 *
 * Cast lists are read by **statically parsing the model's own source**, not by runtime reflection. Runtime reflection
 * was the first choice — it would get inheritance and trait merging for free — but Laravel only merges
 * `protected function casts()` into `$casts` from `initializeHasAttributes()`, i.e. from the constructor, so a model
 * built with `newInstanceWithoutConstructor()` reports an empty cast list, while really constructing a model inside the
 * analyser boots it (global scopes, container, tenancy). PHPStan's own API rules forbid `new ReflectionClass` in an
 * extension for the same reason (`phpstanApi.runtimeReflection`). Parsing is exact for this codebase, where casts are
 * literal arrays (CONVENTIONS §4), costs one cached parse per class, and fails open: an entry whose key or value is
 * not a literal is skipped, so the rule under-reports rather than inventing an error. The `protected $casts` property
 * style is read as well, and the whole ancestor chain is merged (a subclass overriding a cast wins).
 *
 * The injected parser must be `@currentPhpVersionRichParser` (see `phpstan.neon`): `@defaultAnalysisParser` routes any
 * file outside `parameters.paths` through `CleaningParser`, which strips method bodies — and `casts()` is a body.
 *
 * @implements Rule<Node\Expr\CallLike>
 */
final class NoWhereOnEncryptedCasts implements Rule
{
    public const IDENTIFIER = 'bp.whereOnEncryptedCast';

    /** Builder methods whose first argument is a column name compared in SQL. Lower-cased. */
    private const WHERE_METHODS = [
        'where', 'orwhere', 'firstwhere',
        'wherein', 'orwherein', 'wherenotin', 'orwherenotin',
        'wherenot', 'orwherenot',
        'wherelike', 'orwherelike', 'wherenotlike', 'orwherenotlike',
        'wherebetween', 'orwherebetween', 'wherenotbetween', 'orwherenotbetween',
    ];

    /** @var array<string, array<string, string>> */
    private array $castCache = [];

    /** @var array<string, array<string, string>> */
    private array $declaredCache = [];

    public function __construct(
        private readonly ReflectionProvider $reflectionProvider,
        private readonly Parser $parser,
    ) {}

    public function getNodeType(): string
    {
        return Node\Expr\CallLike::class;
    }

    /**
     * @return list<IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        if (! $node instanceof MethodCall && ! $node instanceof StaticCall) {
            return [];
        }

        if (! $node->name instanceof Identifier || ! in_array(strtolower($node->name->toString()), self::WHERE_METHODS, true)) {
            return [];
        }

        $argument = $node->getArgs()[0] ?? null;

        if ($argument === null || $argument->name !== null || $argument->unpack) {
            return [];
        }

        $columns = $scope->getType($argument->value)->getConstantStrings();

        if ($columns === []) {
            return [];
        }

        $model = $this->modelClass($node, $scope);

        if ($model === null) {
            return [];
        }

        $casts = $this->castsOf($model);
        $errors = [];

        foreach ($columns as $column) {
            $cast = $casts[$column->getValue()] ?? null;

            if ($cast === null || ! $this->isEncryptedCast($cast)) {
                continue;
            }

            $errors[] = RuleErrorBuilder::message(sprintf(
                "Column '%s' of %s is cast '%s': %s() on it can never match, because every row is encrypted under its own IV, so the query silently returns nothing.",
                $column->getValue(),
                $model,
                $cast,
                $node->name->toString(),
            ))
                ->identifier(self::IDENTIFIER)
                ->tip('ARCHITECTURE §8.2: ENC columns are never indexed and never in a WHERE. Filter on a plain column (or a deterministic hash column) instead, or load the rows and compare the decrypted attribute in PHP.')
                ->build();
        }

        return $errors;
    }

    private function modelClass(MethodCall|StaticCall $node, Scope $scope): ?string
    {
        if ($node instanceof StaticCall) {
            return $node->class instanceof Name ? $this->modelFromType($scope->resolveTypeByName($node->class)) : null;
        }

        return $this->modelFromType($scope->getType($node->var));
    }

    private function modelFromType(Type $type): ?string
    {
        foreach ($type->getObjectClassNames() as $className) {
            if ($this->isModel($className)) {
                return $className;
            }

            $related = match (true) {
                $this->isA($className, EloquentBuilder::class) => $type->getTemplateType(EloquentBuilder::class, 'TModel'),
                $this->isA($className, Relation::class) => $type->getTemplateType(Relation::class, 'TRelatedModel'),
                default => null,
            };

            if ($related === null) {
                continue;
            }

            foreach ($related->getObjectClassNames() as $relatedClassName) {
                if ($this->isModel($relatedClassName)) {
                    return $relatedClassName;
                }
            }
        }

        return null;
    }

    private function isModel(string $className): bool
    {
        return $className !== Model::class && $this->isA($className, Model::class);
    }

    private function isA(string $className, string $ancestor): bool
    {
        if ($className === $ancestor) {
            return true;
        }

        if (! $this->reflectionProvider->hasClass($className) || ! $this->reflectionProvider->hasClass($ancestor)) {
            return false;
        }

        return $this->reflectionProvider->getClass($className)->isSubclassOfClass($this->reflectionProvider->getClass($ancestor));
    }

    /**
     * Every cast the model ends up with, ancestors first so that a subclass override wins.
     *
     * @return array<string, string>
     */
    private function castsOf(string $model): array
    {
        if (isset($this->castCache[$model])) {
            return $this->castCache[$model];
        }

        $casts = [];
        $class = $this->reflectionProvider->hasClass($model) ? $this->reflectionProvider->getClass($model) : null;
        $chain = [];

        while ($class !== null) {
            $chain[] = $class;
            $class = $class->getParentClass();
        }

        foreach (array_reverse($chain) as $ancestor) {
            $casts = array_merge($casts, $this->castsDeclaredIn($ancestor->getName(), $ancestor->getFileName()));
        }

        return $this->castCache[$model] = $casts;
    }

    /**
     * The literal `$casts` property and `casts()` return values written in one class' own source file.
     *
     * @return array<string, string>
     */
    private function castsDeclaredIn(string $className, ?string $fileName): array
    {
        if ($fileName === null) {
            return [];
        }

        if (isset($this->declaredCache[$className])) {
            return $this->declaredCache[$className];
        }

        return $this->declaredCache[$className] = $this->parseCasts($className, $fileName);
    }

    /**
     * @return array<string, string>
     */
    private function parseCasts(string $className, string $fileName): array
    {
        try {
            $statements = $this->parser->parseFile($fileName);
        } catch (Throwable) {
            return [];
        }

        $classNode = $this->findClass($statements, $className);

        if ($classNode === null) {
            return [];
        }

        $casts = [];
        $finder = new NodeFinder;

        foreach ($classNode->stmts as $statement) {
            if ($statement instanceof Property) {
                foreach ($statement->props as $prop) {
                    if ($prop->name->toString() === 'casts' && $prop->default instanceof Array_) {
                        $casts = array_merge($casts, $this->literalPairs($prop->default));
                    }
                }
            }

            if ($statement instanceof ClassMethod && $statement->name->toLowerString() === 'casts') {
                /** @var list<Array_> $arrays */
                $arrays = $finder->findInstanceOf($statement->stmts ?? [], Array_::class);

                foreach ($arrays as $array) {
                    $casts = array_merge($casts, $this->literalPairs($array));
                }
            }
        }

        return $casts;
    }

    /**
     * @param  array<Node>  $statements
     */
    private function findClass(array $statements, string $className): ?Class_
    {
        $shortName = str_contains($className, '\\') ? substr($className, strrpos($className, '\\') + 1) : $className;
        $namespace = str_contains($className, '\\') ? substr($className, 0, strrpos($className, '\\')) : '';

        /** @var list<Class_> $classes */
        $classes = (new NodeFinder)->findInstanceOf($statements, Class_::class);

        foreach ($classes as $class) {
            if ($class->name?->toString() !== $shortName) {
                continue;
            }

            if ($this->namespaceOf($statements) === $namespace) {
                return $class;
            }
        }

        return null;
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
     * `'column' => 'cast'` pairs written literally. A `Foo::class` value keeps its written name so that
     * `AsEncryptedCollection::class` is still recognisable; anything computed is skipped.
     *
     * @return array<string, string>
     */
    private function literalPairs(Array_ $array): array
    {
        $pairs = [];

        foreach ($array->items as $item) {
            if (! $item->key instanceof String_) {
                continue;
            }

            if ($item->value instanceof String_) {
                $pairs[$item->key->value] = $item->value->value;

                continue;
            }

            if ($item->value instanceof ClassConstFetch && $item->value->class instanceof Name && $item->value->name instanceof Identifier && $item->value->name->toLowerString() === 'class') {
                $pairs[$item->key->value] = $item->value->class->toString();
            }
        }

        return $pairs;
    }

    private function isEncryptedCast(string $cast): bool
    {
        $normalised = strtolower(trim($cast));
        $shortName = str_contains($normalised, '\\') ? substr($normalised, strrpos($normalised, '\\') + 1) : $normalised;

        return $normalised === 'encrypted'
            || str_starts_with($normalised, 'encrypted:')
            || str_starts_with($shortName, 'asencrypted');
    }
}

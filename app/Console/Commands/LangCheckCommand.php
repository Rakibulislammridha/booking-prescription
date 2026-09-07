<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Symfony\Component\Finder\Finder;

/**
 * php artisan lang:check — CONVENTIONS §7.5: resources/lang/{en,bn}.json parse, have identical key sets, keys are
 * sorted WITHIN each prefix block (`auth.*`, `patients.*`, … — blocks themselves may appear in any order, since
 * modules append their own block), no value is empty, and every translation literal used in code exists in both:
 * PHP `__('k')` / `trans('k')` / `@lang('k')` under app/, routes/, database/, resources/views and client `t('k')`
 * under resources/js/. Dynamic keys (template literals, concatenation) and comment lines are skipped.
 * Exit code 1 on any finding, so CI can gate on it.
 */
final class LangCheckCommand extends Command
{
    protected $signature = 'lang:check {--no-code : Only compare the two JSON files}';

    protected $description = 'Verify en/bn translation files parse, have identical keys, and cover every key used in code';

    /** @var array<int, string> */
    private const PHP_DIRS = ['app', 'routes', 'database', 'resources/views'];

    /** @var array<int, string> */
    private const JS_DIRS = ['resources/js'];

    public function handle(): int
    {
        $failures = 0;
        $files = [];

        foreach (['en', 'bn'] as $locale) {
            $path = resource_path("lang/{$locale}.json");
            $decoded = json_decode((string) File::get($path), true, 512);

            if (! is_array($decoded)) {
                $this->components->error("{$locale}.json does not parse: ".json_last_error_msg());

                return self::FAILURE;
            }

            $files[$locale] = $decoded;
        }

        $en = array_keys($files['en']);
        $bn = array_keys($files['bn']);

        foreach (['en' => array_diff($en, $bn), 'bn' => array_diff($bn, $en)] as $only => $keys) {
            foreach ($keys as $key) {
                $this->components->error("[{$key}] exists only in {$only}.json");
                $failures++;
            }
        }

        foreach (['en', 'bn'] as $locale) {
            foreach ($files[$locale] as $key => $value) {
                if (! is_string($value) || trim($value) === '') {
                    $this->components->error("[{$key}] in {$locale}.json is empty");
                    $failures++;
                }
            }

            foreach (self::unsortedWithinPrefix(array_keys($files[$locale])) as [$key, $previous]) {
                $this->components->error("[{$key}] in {$locale}.json is out of order inside its prefix block (after [{$previous}])");
                $failures++;
            }
        }

        if (! $this->option('no-code')) {
            $known = array_flip($en);

            foreach ($this->usedKeys() as $key => $where) {
                if (! isset($known[$key])) {
                    $this->components->error("[{$key}] used in {$where} is missing from resources/lang/*.json");
                    $failures++;
                }
            }
        }

        if ($failures === 0) {
            $this->components->info(sprintf('en.json and bn.json agree on %d keys; every literal key used in code exists.', count($en)));

            return self::SUCCESS;
        }

        $this->components->error("{$failures} translation problem(s).");

        return self::FAILURE;
    }

    /**
     * Keys must be sorted within their first-segment block; blocks may be in any order.
     *
     * @param  array<int, string>  $keys
     * @return array<int, array{0: string, 1: string}> [key, previous key of the same prefix] pairs that break the order
     */
    public static function unsortedWithinPrefix(array $keys): array
    {
        $last = [];
        $broken = [];

        foreach ($keys as $key) {
            $prefix = explode('.', $key, 2)[0];

            if (isset($last[$prefix]) && strcmp($last[$prefix], $key) > 0) {
                $broken[] = [$key, $last[$prefix]];
            }

            $last[$prefix] = $key;
        }

        return $broken;
    }

    /** @return array<string, string> key => first file:line that uses it */
    private function usedKeys(): array
    {
        $used = [];
        $php = '/(?:__|trans|@lang|trans_choice)\(\s*[\'"]([a-z0-9_]+(?:\.[a-z0-9_-]+)+)[\'"]/i';
        $js = '/(?<![\w.$])t\(\s*[\'"]([a-z0-9_]+(?:\.[a-z0-9_-]+)+)[\'"]/';

        foreach ([[self::PHP_DIRS, ['*.php'], $php], [self::JS_DIRS, ['*.ts', '*.tsx'], $js]] as [$dirs, $names, $pattern]) {
            $existing = array_values(array_filter(array_map(fn (string $d) => base_path($d), $dirs), 'is_dir'));

            if ($existing === []) {
                continue;
            }

            $finder = Finder::create()->files()->in($existing)->name($names)->notPath('/__tests__/')->notPath('/node_modules/');

            foreach ($finder as $file) {
                foreach (explode("\n", $file->getContents()) as $lineNo => $line) {
                    $line = self::stripComments($line);

                    if (preg_match_all($pattern, $line, $matches) > 0) {
                        foreach ($matches[1] as $key) {
                            $used[$key] ??= $file->getRelativePathname().':'.($lineNo + 1);
                        }
                    }
                }
            }
        }

        ksort($used);

        return $used;
    }

    /** Drops `// …` tails and whole `*`/`#` comment lines so documentation examples are not treated as usages. */
    private static function stripComments(string $line): string
    {
        $trimmed = ltrim($line);

        if (str_starts_with($trimmed, '*') || str_starts_with($trimmed, '/*') || str_starts_with($trimmed, '#') || str_starts_with($trimmed, '//')) {
            return '';
        }

        return (string) preg_replace('#\s//(?![^\'"]*[\'"][^\'"]*$).*$#', '', $line);
    }
}

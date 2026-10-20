<?php

declare(strict_types=1);

namespace Flezha\Tests;

/**
 * Every public concept spelled as `test/fixtures/sdk_public_names.txt` spells it.
 *
 * A PHP cell is a class name, optionally followed by `::method` and then by
 * `(parameter)` for a parameter the method takes, or by `::$property`. A cell starting with `!`
 * names something that must not be public: absent, not public, or marked
 * `@internal`, so a re-exported internal fails here as a renamed concept does.
 */
final class PublicNamesTest extends RepositoryTestCase
{
    private const COLUMN = 3; // concept|python|ruby|php|node|elixir|java|rust
    private const CELL = '/^(?<class>[\w\\\\]+)(?:::(?<method>\$?\w+))?(?:\((?<parameter>\w+)\))?$/';

    public function testEveryConceptHasItsOnePublicSpelling(): void
    {
        $wrong = [];
        foreach ($this->cells() as [$concept, $path]) {
            $name = ltrim($path, '!');
            $hidden = $name !== $path;
            if (self::isPublic($name) === $hidden) {
                $wrong[] = "{$concept}: `{$name}` is " . ($hidden ? 'public' : 'missing');
            }
        }

        self::assertSame([], $wrong);
    }

    /** @return list<array{string, string}> */
    private function cells(): array
    {
        $cells = [];
        foreach (file($this->repositoryFile('test/fixtures/sdk_public_names.txt'), FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            if (trim($line) === '' || str_starts_with($line, '#')) {
                continue;
            }
            $row = explode('|', $line);
            foreach (preg_split('/\s+/', $row[self::COLUMN], -1, PREG_SPLIT_NO_EMPTY) ?: [] as $path) {
                if ($path !== '-') {
                    $cells[] = [$row[0], $path];
                }
            }
        }

        return $cells;
    }

    private static function isPublic(string $path): bool
    {
        preg_match(self::CELL, $path, $cell) || self::fail("unreadable cell {$path}");
        if (!class_exists($cell['class']) && !interface_exists($cell['class'])) {
            return false;
        }
        $class = new \ReflectionClass($cell['class']);
        if (empty($cell['method'])) {
            return !self::internal($class->getDocComment());
        }
        if (str_starts_with($cell['method'], '$')) {
            $name = substr($cell['method'], 1);

            return $class->hasProperty($name) && $class->getProperty($name)->isPublic();
        }
        if (!$class->hasMethod($cell['method'])) {
            return false;
        }
        $method = $class->getMethod($cell['method']);
        if (!$method->isPublic() || self::internal($method->getDocComment())) {
            return false;
        }

        return empty($cell['parameter']) || in_array(
            $cell['parameter'],
            array_map(static fn (\ReflectionParameter $p): string => $p->getName(), $method->getParameters()),
            true,
        );
    }

    private static function internal(string|false $doc): bool
    {
        return $doc !== false && str_contains($doc, '@internal');
    }
}

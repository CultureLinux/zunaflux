<?php

declare(strict_types=1);

namespace App\Tests\Unit\Translation;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Keeps the French source strings used outside Twig in sync with the translation catalogues. */
final class SourceTranslationKeysTest extends TestCase
{
    private const ROOT = __DIR__.'/../../..';

    /** @return iterable<string, array{string}> */
    public static function locales(): iterable
    {
        yield 'en' => ['en'];
        yield 'de' => ['de'];
    }

    #[DataProvider('locales')]
    public function testEveryClientSideKeyIsTranslated(string $locale): void
    {
        $missing = array_values(array_filter(self::clientKeys(), static fn (string $key): bool => !array_key_exists($key, self::catalogue($locale))));

        self::assertSame([], $missing, 'JavaScript keys missing from messages.'.$locale.'.json');
    }

    public function testEveryClientSideKeyIsExposedToTheBrowser(): void
    {
        $template = (string) file_get_contents(self::ROOT.'/templates/_js_messages.html.twig');
        $missing = array_values(array_filter(self::clientKeys(), static fn (string $key): bool => !str_contains($template, "'".$key."'")));

        self::assertSame([], $missing, 'Keys used by t(\'…\') but absent from templates/_js_messages.html.twig');
    }

    #[DataProvider('locales')]
    public function testEveryFlashMessageLiteralIsTranslated(string $locale): void
    {
        $missing = [];
        foreach (self::phpFiles() as $file) {
            preg_match_all('/addFlash\(\s*[\'"][a-z]+[\'"]\s*,\s*([\'"])((?:(?!\1).)+)\1\s*\)/su', (string) file_get_contents($file), $matches);
            foreach ($matches[2] as $message) {
                if (!array_key_exists($message, self::catalogue($locale))) {
                    $missing[$message] = basename($file);
                }
            }
        }

        self::assertSame([], $missing, 'Flash messages missing from messages.'.$locale.'.json');
    }

    /** @return list<string> */
    private static function clientKeys(): array
    {
        $keys = [];
        $files = [...(glob(self::ROOT.'/assets/*.js') ?: []), ...(glob(self::ROOT.'/assets/*/*.js') ?: []), ...(glob(self::ROOT.'/public/*.js') ?: [])];
        foreach ($files as $file) {
            preg_match_all('/(?<![\w.$])t\(\'((?:[^\'\\\\]|\\\\.)*)\'/u', (string) file_get_contents($file), $matches);
            array_push($keys, ...$matches[1]);
        }

        return array_values(array_unique($keys));
    }

    /** @return array<string, string> */
    private static function catalogue(string $locale): array
    {
        return json_decode((string) file_get_contents(self::ROOT.'/translations/messages.'.$locale.'.json'), true, 4, \JSON_THROW_ON_ERROR);
    }

    /** @return iterable<string> */
    private static function phpFiles(): iterable
    {
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::ROOT.'/src', \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file instanceof \SplFileInfo && 'php' === $file->getExtension()) {
                yield $file->getPathname();
            }
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Unit\Translation;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TemplateTranslationKeysTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function locales(): iterable
    {
        yield 'en' => ['en'];
        yield 'de' => ['de'];
    }

    #[DataProvider('locales')]
    public function testEveryLiteralKeyUsedInTemplatesHasATranslation(string $locale): void
    {
        $catalogue = json_decode((string) file_get_contents(__DIR__.'/../../../translations/messages.'.$locale.'.json'), true, 4, \JSON_THROW_ON_ERROR);
        $missing = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(__DIR__.'/../../../templates', \FilesystemIterator::SKIP_DOTS)) as $file) {
            if (!$file instanceof \SplFileInfo || 'twig' !== $file->getExtension()) {
                continue;
            }
            preg_match_all('/(?<![\w.])([\'"])((?:(?!\1).)+?)\1\|trans\b/u', (string) file_get_contents($file->getPathname()), $matches);
            foreach ($matches[2] as $key) {
                $key = stripslashes($key);
                if (!array_key_exists($key, $catalogue)) {
                    $missing[$key] = $file->getBasename();
                }
            }
        }

        self::assertSame([], $missing, 'Keys missing from messages.'.$locale.'.json');
    }
}

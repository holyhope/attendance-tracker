<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

class LangTest extends TestCase
{
    /** @return array<string, array{string}> */
    public static function langProvider(): array
    {
        $dir   = __DIR__ . '/../lang/';
        $files = glob($dir . '*.php') ?: [];
        $cases = [];
        foreach ($files as $file) {
            $code = basename($file, '.php');
            if ($code !== 'fr') {
                $cases[$code] = [$code];
            }
        }
        return $cases;
    }

    /** @dataProvider langProvider */
    public function testAllKeysPresent(string $lang): void
    {
        $ref   = require __DIR__ . '/../lang/fr.php';
        $trans = require __DIR__ . '/../lang/' . $lang . '.php';

        $missing = array_diff_key($ref, $trans);
        $extra   = array_diff_key($trans, $ref);

        $this->assertEmpty(
            $missing,
            "lang/{$lang}.php is missing keys: " . implode(', ', array_keys($missing))
        );
        $this->assertEmpty(
            $extra,
            "lang/{$lang}.php has unexpected keys: " . implode(', ', array_keys($extra))
        );
    }

    /** @dataProvider langProvider */
    public function testNoEmptyValues(string $lang): void
    {
        $trans = require __DIR__ . '/../lang/' . $lang . '.php';
        $empty = array_keys(array_filter($trans, fn($v) => $v === '' || $v === null));

        $this->assertEmpty(
            $empty,
            "lang/{$lang}.php has empty values for keys: " . implode(', ', $empty)
        );
    }
}

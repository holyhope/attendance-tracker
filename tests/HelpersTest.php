<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

class HelpersTest extends TestCase
{
    public function testUuid4Format(): void
    {
        $uuid = uuid4();
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $uuid,
            'uuid4() must produce a valid RFC 4122 v4 UUID'
        );
    }

    public function testUuid4Uniqueness(): void
    {
        $ids = array_map(fn($_) => uuid4(), range(1, 100));
        $this->assertCount(100, array_unique($ids), 'uuid4() must not produce duplicates');
    }

    /** @dataProvider safeUrlProvider */
    public function testSafeUrlPattern(string $url, bool $valid): void
    {
        // Same regex used in public/index.php and public/admin/index.php
        $accepted = $url && preg_match('#^(https?://|/)#', $url);
        $this->assertSame($valid, (bool) $accepted, "URL: {$url}");
    }

    /** @return array<string, array{string, bool}> */
    public static function safeUrlProvider(): array
    {
        return [
            'https URL'          => ['https://example.com/page', true],
            'http URL'           => ['http://example.com/page', true],
            'absolute path'      => ['/confidentialite.html', true],
            'root path'          => ['/', true],
            'javascript scheme'  => ['javascript:alert(1)', false],
            'data URI'           => ['data:text/html,<h1>x</h1>', false],
            'relative path'      => ['relative/path', false],
            'empty string'       => ['', false],
            'ftp URL'            => ['ftp://host/file', false],
        ];
    }
}

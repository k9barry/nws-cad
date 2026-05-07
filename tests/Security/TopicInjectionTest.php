<?php

declare(strict_types=1);

namespace NwsCad\Tests\Security;

use NwsCad\Notifications\TopicSanitizer;
use PHPUnit\Framework\TestCase;

/**
 * @covers \NwsCad\Notifications\TopicSanitizer
 */
class TopicInjectionTest extends TestCase
{
    /**
     * @dataProvider injectionVectors
     */
    public function testKnownAttackVectorsAreNeutralized(string $input, ?string $expected): void
    {
        $this->assertSame($expected, TopicSanitizer::clean($input));
    }

    /** @return array<string,array{0:string,1:?string}> */
    public static function injectionVectors(): array
    {
        return [
            'path traversal'      => ['../etc/passwd', 'etc_passwd'],
            'query string'        => ['Fire?token=abc', 'Fire_token_abc'],
            'CRLF injection'      => ["Fire\r\nX-Header: evil", 'Fire_X-Header_evil'],
            'null byte'           => ["A\0B", 'A_B'],
            'leading dot dot'     => ['..', null],
            'whitespace only'     => ['   ', null],
            'pure punctuation'    => ['???', null],
            'unicode-only'        => ['日本語', null],
            'mixed unicode'       => ['Engine日1', 'Engine_1'],
        ];
    }
}

<?php

declare(strict_types=1);

namespace NwsCad\Tests\Unit;

use NwsCad\Import\InvalidXmlException;
use NwsCad\Import\XmlValidator;
use PHPUnit\Framework\TestCase;
use SimpleXMLElement;

/**
 * @covers \NwsCad\Import\XmlValidator
 * @uses \NwsCad\Import\InvalidXmlException
 */
class XmlValidatorTest extends TestCase
{
    private const NS = 'http://www.newworldsystems.com/Aegis/CAD/Peripheral/CallExport/2011/02';

    private function xml(string $body, string $root = 'CallExport'): SimpleXMLElement
    {
        return new SimpleXMLElement(
            "<{$root} xmlns=\"" . self::NS . "\">{$body}</{$root}>"
        );
    }

    public function testValidDocumentPasses(): void
    {
        $this->expectNotToPerformAssertions();
        (new XmlValidator())->validate(
            $this->xml('<CallId>123</CallId><CallNumber>2026-1</CallNumber>')
        );
    }

    public function testWrongRootIsRejected(): void
    {
        $this->expectException(InvalidXmlException::class);
        $this->expectExceptionMessage('Unexpected root element <SomethingElse>');
        (new XmlValidator())->validate(
            $this->xml('<CallId>1</CallId><CallNumber>x</CallNumber>', 'SomethingElse')
        );
    }

    public function testMissingCallIdIsRejected(): void
    {
        $this->expectException(InvalidXmlException::class);
        $this->expectExceptionMessage('CallId');
        (new XmlValidator())->validate($this->xml('<CallNumber>2026-1</CallNumber>'));
    }

    public function testMissingCallNumberIsRejected(): void
    {
        $this->expectException(InvalidXmlException::class);
        $this->expectExceptionMessage('CallNumber');
        (new XmlValidator())->validate($this->xml('<CallId>123</CallId>'));
    }

    public function testMissingBothFieldsListsBoth(): void
    {
        try {
            (new XmlValidator())->validate($this->xml('<NatureOfCall>x</NatureOfCall>'));
            $this->fail('expected InvalidXmlException');
        } catch (InvalidXmlException $e) {
            $this->assertStringContainsString('CallId', $e->getMessage());
            $this->assertStringContainsString('CallNumber', $e->getMessage());
        }
    }

    public function testEmptyRequiredFieldIsRejected(): void
    {
        $this->expectException(InvalidXmlException::class);
        (new XmlValidator())->validate(
            $this->xml('<CallId></CallId><CallNumber>2026-1</CallNumber>')
        );
    }
}

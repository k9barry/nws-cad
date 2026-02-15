<?php

declare(strict_types=1);

namespace NwsCad\Tests\Security;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * Security tests for XSS (Cross-Site Scripting) prevention
 * Tests that output is properly escaped to prevent XSS attacks
 */
#[CoversNothing]
class XssTest extends TestCase
{
    public function testHtmlSpecialCharsEscapesScriptTags(): void
    {
        $maliciousInput = '<script>alert("XSS")</script>';
        $escaped = htmlspecialchars($maliciousInput, ENT_QUOTES, 'UTF-8');
        
        $this->assertStringNotContainsString('<script>', $escaped);
        $this->assertStringNotContainsString('</script>', $escaped);
        $this->assertStringContainsString('&lt;script&gt;', $escaped);
    }

    public function testHtmlSpecialCharsEscapesEventHandlers(): void
    {
        $inputs = [
            '<img src=x onerror="alert(1)">',
            '<div onload="alert(1)">',
            '<body onload="alert(1)">',
            '<input onfocus="alert(1)">',
        ];
        
        foreach ($inputs as $maliciousInput) {
            $escaped = htmlspecialchars($maliciousInput, ENT_QUOTES, 'UTF-8');
            
            // Angle brackets are escaped, so tags cannot be parsed as HTML
            $this->assertStringNotContainsString('<', $escaped);
            $this->assertStringNotContainsString('>', $escaped);
            // Quotes are escaped, so attribute values cannot break out
            $this->assertStringNotContainsString('"', $escaped);
        }
    }

    public function testHtmlSpecialCharsEscapesQuotes(): void
    {
        $singleQuote = "test'value";
        $doubleQuote = 'test"value';
        
        $escapedSingle = htmlspecialchars($singleQuote, ENT_QUOTES, 'UTF-8');
        $escapedDouble = htmlspecialchars($doubleQuote, ENT_QUOTES, 'UTF-8');
        
        $this->assertStringContainsString('&#039;', $escapedSingle);
        $this->assertStringContainsString('&quot;', $escapedDouble);
    }

    public function testJsonEncodeEscapesJavaScript(): void
    {
        $data = [
            'script' => '<script>alert("XSS")</script>',
            'event' => 'onclick="alert(1)"',
            'quote' => "test'value",
        ];
        
        $json = json_encode($data, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
        
        $this->assertStringNotContainsString('<script>', $json);
        // Quotes are escaped, so event handler attributes cannot execute
        $this->assertStringNotContainsString('onclick="', $json);
        $this->assertStringContainsString('\u003C', $json); // Escaped <
    }

    public function testUrlEncodingPreventUrlBasedXss(): void
    {
        $maliciousUrl = 'javascript:alert("XSS")';
        $encoded = urlencode($maliciousUrl);
        
        $this->assertStringNotContainsString('javascript:', $encoded);
        $this->assertStringContainsString('javascript%3A', $encoded);
    }

    public function testDataAttributesAreEscaped(): void
    {
        $maliciousData = '" onclick="alert(1)"';
        $escaped = htmlspecialchars($maliciousData, ENT_QUOTES, 'UTF-8');
        
        // When used in HTML attributes like: <div data-value="...">
        // Quotes are escaped so the attribute value cannot break out
        $this->assertStringNotContainsString('"', $escaped);
        $this->assertStringContainsString('&quot;', $escaped);
    }

    public function testHtmlPurifierPatterns(): void
    {
        $dangerousInputs = [
            '<iframe src="javascript:alert(1)"></iframe>',
            '<object data="javascript:alert(1)">',
            '<embed src="javascript:alert(1)">',
            '<svg onload="alert(1)">',
            '<math><mi xlink:href="javascript:alert(1)">click</mi></math>',
        ];
        
        foreach ($dangerousInputs as $input) {
            $escaped = htmlspecialchars($input, ENT_QUOTES, 'UTF-8');
            
            // Should not contain actual HTML tags
            $this->assertStringNotContainsString('<iframe', $escaped);
            $this->assertStringNotContainsString('<object', $escaped);
            $this->assertStringNotContainsString('<embed', $escaped);
            $this->assertStringNotContainsString('<svg', $escaped);
        }
    }

    public function testContextualEscapingForHtmlContext(): void
    {
        $input = '<b>Bold</b> & "quoted"';
        $escaped = htmlspecialchars($input, ENT_QUOTES, 'UTF-8');
        
        $this->assertEquals('&lt;b&gt;Bold&lt;/b&gt; &amp; &quot;quoted&quot;', $escaped);
    }

    public function testContextualEscapingForJsContext(): void
    {
        $input = "'; alert('XSS'); //";
        $escaped = json_encode($input, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
        
        // Single quotes are escaped so JS string cannot break out
        $this->assertStringNotContainsString("';", $escaped);
        $this->assertStringNotContainsString("'XSS'", $escaped);
    }

    public function testCssSanitization(): void
    {
        // CSS injection attempts
        $dangerousStyles = [
            'expression(alert(1))',
            'url(javascript:alert(1))',
            'behavior:url(xss.htc)',
            '@import "javascript:alert(1)"',
        ];
        
        foreach ($dangerousStyles as $style) {
            // Simple sanitization: remove dangerous patterns
            $sanitized = preg_replace('/expression|javascript:|behavior:|@import/i', '', $style);
            
            $this->assertStringNotContainsStringIgnoringCase('expression', $sanitized);
            $this->assertStringNotContainsStringIgnoringCase('javascript:', $sanitized);
        }
    }

    public function testPreventDomBasedXss(): void
    {
        // Test that user input cannot be directly inserted into DOM
        $userInput = '<img src=x onerror=alert(1)>';
        
        // Simulate safe insertion
        $safeData = [
            'value' => htmlspecialchars($userInput, ENT_QUOTES, 'UTF-8')
        ];
        $json = json_encode($safeData);
        
        // Angle brackets are escaped so HTML tags cannot be parsed
        $this->assertStringNotContainsString('<img', $json);
        $this->assertStringNotContainsString('<', $safeData['value']);
    }

    public function testPreventStoredXss(): void
    {
        // Simulate storing user input
        $userComment = '<script>alert("Stored XSS")</script>';
        
        // Before storing, sanitize
        $sanitized = htmlspecialchars($userComment, ENT_QUOTES, 'UTF-8');
        
        // When displaying, it should be safe
        $this->assertStringNotContainsString('<script>', $sanitized);
        
        // Double encoding should not occur
        $doubleSanitized = htmlspecialchars($sanitized, ENT_QUOTES, 'UTF-8');
        $this->assertNotEquals($sanitized, $doubleSanitized);
    }

    public function testPreventReflectedXss(): void
    {
        // Simulate URL parameter
        $urlParam = '<script>alert(1)</script>';
        
        // Should be escaped before displaying
        $escaped = htmlspecialchars($urlParam, ENT_QUOTES, 'UTF-8');
        
        $this->assertStringNotContainsString('<script>', $escaped);
    }

    public function testHtmlEntitiesCompleteEscaping(): void
    {
        $input = '<>&"\'';
        $escaped = htmlentities($input, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        
        // ENT_HTML5 uses &apos; for single quotes
        $this->assertEquals('&lt;&gt;&amp;&quot;&apos;', $escaped);
    }

    public function testStripTagsRemovesHtml(): void
    {
        $input = '<p>Paragraph</p><script>alert(1)</script>';
        $stripped = strip_tags($input);
        
        $this->assertEquals('Paragraphalert(1)', $stripped);
        $this->assertStringNotContainsString('<script>', $stripped);
        $this->assertStringNotContainsString('<p>', $stripped);
    }

    public function testStripTagsAllowsSafeTags(): void
    {
        $input = '<p>Safe paragraph</p><script>alert(1)</script>';
        $stripped = strip_tags($input, '<p>');
        
        $this->assertStringContainsString('<p>', $stripped);
        $this->assertStringNotContainsString('<script>', $stripped);
    }
}

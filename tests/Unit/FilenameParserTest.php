<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use NwsCad\FilenameParser;

/**
 * Tests for FilenameParser
 */
class FilenameParserTest extends TestCase
{
    public function testParseValidFilename(): void
    {
        $result = FilenameParser::parse('591_2026012705492672.xml');
        
        $this->assertIsArray($result);
        $this->assertEquals('591', $result['call_number']);
        $this->assertEquals('2026', $result['year']);
        $this->assertEquals('01', $result['month']);
        $this->assertEquals('27', $result['day']);
        $this->assertEquals('05', $result['hour']);
        $this->assertEquals('49', $result['minute']);
        $this->assertEquals('26', $result['second']);
        $this->assertEquals('72', $result['suffix']);
        $this->assertEquals('2026-01-27 05:49:26.72', $result['timestamp']);
        $this->assertEquals(2026012705492672, $result['timestamp_int']);
    }
    
    public function testParseWithoutExtension(): void
    {
        $result = FilenameParser::parse('232_2026012609353768');
        
        $this->assertIsArray($result);
        $this->assertEquals('232', $result['call_number']);
        $this->assertEquals('2026-01-26 09:35:37.68', $result['timestamp']);
    }
    
    public function testParseWithPath(): void
    {
        $result = FilenameParser::parse('/path/to/232_2026012609353768.xml');
        
        $this->assertIsArray($result);
        $this->assertEquals('232', $result['call_number']);
    }
    
    public function testParseInvalidFilename(): void
    {
        $this->assertNull(FilenameParser::parse('invalid.xml'));
        $this->assertNull(FilenameParser::parse('123_abc.xml'));
        $this->assertNull(FilenameParser::parse(''));
    }
    
    public function testIsValid(): void
    {
        $this->assertTrue(FilenameParser::isValid('591_2026012705492672.xml'));
        $this->assertTrue(FilenameParser::isValid('232_2026012609353768.xml'));
        $this->assertFalse(FilenameParser::isValid('invalid.xml'));
        $this->assertFalse(FilenameParser::isValid(''));
    }
    
    public function testGetCallNumber(): void
    {
        $this->assertEquals('591', FilenameParser::getCallNumber('591_2026012705492672.xml'));
        $this->assertEquals('232', FilenameParser::getCallNumber('232_2026012609353768.xml'));
        $this->assertNull(FilenameParser::getCallNumber('invalid.xml'));
    }
    
    public function testGetTimestampInt(): void
    {
        $this->assertEquals(2026012705492672, FilenameParser::getTimestampInt('591_2026012705492672.xml'));
        $this->assertEquals(2026012609353768, FilenameParser::getTimestampInt('232_2026012609353768.xml'));
        $this->assertNull(FilenameParser::getTimestampInt('invalid.xml'));
    }
    
    public function testCompare(): void
    {
        // Test earlier vs later
        $this->assertEquals(
            -1,
            FilenameParser::compare('232_2026012609353768.xml', '232_2026012609595563.xml')
        );
        
        // Test later vs earlier
        $this->assertEquals(
            1,
            FilenameParser::compare('232_2026012609595563.xml', '232_2026012609353768.xml')
        );
        
        // Test equal
        $this->assertEquals(
            0,
            FilenameParser::compare('232_2026012609353768.xml', '232_2026012609353768.xml')
        );
        
        // Test invalid
        $this->assertNull(FilenameParser::compare('invalid.xml', '232_2026012609353768.xml'));
    }
    
    public function testGroupByCallNumber(): void
    {
        $files = [
            '232_2026012609353768.xml',
            '232_2026012609595563.xml',
            '591_2026012705492672.xml',
            '240_2026012609521444.xml',
            '240_2026012610123190.xml',
        ];
        
        $grouped = FilenameParser::groupByCallNumber($files);
        
        $this->assertCount(3, $grouped);
        $this->assertArrayHasKey('232', $grouped);
        $this->assertArrayHasKey('591', $grouped);
        $this->assertArrayHasKey('240', $grouped);
        $this->assertCount(2, $grouped['232']);
        $this->assertCount(1, $grouped['591']);
        $this->assertCount(2, $grouped['240']);
    }
    
    public function testGetLatestFiles(): void
    {
        $files = [
            '232_2026012609353768.xml',
            '232_2026012609595563.xml', // Latest for 232
            '232_2026012609504429.xml',
            '591_2026012705492672.xml',  // Only one for 591
            '240_2026012609521444.xml',
            '240_2026012610123190.xml',  // Latest for 240
        ];
        
        $latest = FilenameParser::getLatestFiles($files);
        
        $this->assertCount(3, $latest);
        $this->assertContains('232_2026012609595563.xml', $latest);
        $this->assertContains('591_2026012705492672.xml', $latest);
        $this->assertContains('240_2026012610123190.xml', $latest);
    }
    
    public function testGetFilesToSkip(): void
    {
        $files = [
            '232_2026012609353768.xml',  // Skip
            '232_2026012609595563.xml',  // Keep - latest for 232
            '232_2026012609504429.xml',  // Skip
            '591_2026012705492672.xml',  // Keep - only one for 591
            '240_2026012609521444.xml',  // Skip
            '240_2026012610123190.xml',  // Keep - latest for 240
        ];
        
        $toSkip = FilenameParser::getFilesToSkip($files);
        
        $this->assertCount(3, $toSkip);
        $this->assertContains('232_2026012609353768.xml', $toSkip);
        $this->assertContains('232_2026012609504429.xml', $toSkip);
        $this->assertContains('240_2026012609521444.xml', $toSkip);
    }
    
    public function testRealSampleFiles(): void
    {
        // Test with actual filenames from the samples folder
        $files = [
            '232_2026012609353768.xml',
            '232_2026012609354268.xml',
            '232_2026012609362276.xml',
            '232_2026012609362778.xml',
            '232_2026012609365284.xml',
            '232_2026012609370789.xml',
            '232_2026012609381304.xml',
            '232_2026012609433872.xml',
            '232_2026012609435881.xml',
            '232_2026012609504429.xml',
            '232_2026012609510431.xml',
            '232_2026012609552001.xml',
            '232_2026012609573028.xml',
            '232_2026012609583049.xml',
            '232_2026012609594560.xml',
            '232_2026012609595563.xml',
            '232_2026012610123693.xml',
            '232_2026012610192755.xml',
            '232_2026012611061506.xml',  // Should be latest
        ];
        
        $latest = FilenameParser::getLatestFiles($files);
        
        // Should only return one file for call 232
        $this->assertCount(1, $latest);
        $this->assertContains('232_2026012611061506.xml', $latest);
        
        // Should skip 18 files
        $toSkip = FilenameParser::getFilesToSkip($files);
        $this->assertCount(18, $toSkip);
    }
    
    public function testGetUnparseableFilenames(): void
    {
        $files = [
            '232_2026012609353768.xml',
            'invalid.xml',
            '591_2026012705492672.xml',
            'bad_filename.xml',
            '240_2026012609521444.xml',
        ];
        
        $unparseable = FilenameParser::getUnparseableFilenames($files);
        
        $this->assertCount(2, $unparseable);
        $this->assertContains('invalid.xml', $unparseable);
        $this->assertContains('bad_filename.xml', $unparseable);
    }
    
    public function testGetLatestFilesWithUnparseableFiles(): void
    {
        $files = [
            '232_2026012609353768.xml',
            'invalid.xml',
            '232_2026012609595563.xml',  // Latest for 232
            'bad_filename.xml',
            '591_2026012705492672.xml',  // Only one for 591
        ];
        
        $latest = FilenameParser::getLatestFiles($files);
        
        // Should only return latest parseable files
        $this->assertCount(2, $latest);
        $this->assertContains('232_2026012609595563.xml', $latest);
        $this->assertContains('591_2026012705492672.xml', $latest);
        $this->assertNotContains('invalid.xml', $latest);
        $this->assertNotContains('bad_filename.xml', $latest);
    }
    
    public function testGetFilesToSkipWithUnparseableFiles(): void
    {
        $files = [
            '232_2026012609353768.xml',  // Skip - older
            'invalid.xml',                // Skip - unparseable
            '232_2026012609595563.xml',  // Keep - latest for 232
            'bad_filename.xml',           // Skip - unparseable
            '591_2026012705492672.xml',  // Keep - only one for 591
        ];
        
        $toSkip = FilenameParser::getFilesToSkip($files);
        
        // Should skip older versions AND unparseable files
        $this->assertCount(3, $toSkip);
        $this->assertContains('232_2026012609353768.xml', $toSkip);
        $this->assertContains('invalid.xml', $toSkip);
        $this->assertContains('bad_filename.xml', $toSkip);
    }
    
    public function testFilenameWithTilde(): void
    {
        // Test that the pattern now accepts filenames with tilde metadata
        $result = FilenameParser::parse('261_2022120307162437~20241007-075033.xml');
        
        $this->assertIsArray($result);
        $this->assertEquals('261', $result['call_number']);
        $this->assertEquals('2022', $result['year']);
        $this->assertEquals('12', $result['month']);
        $this->assertEquals('03', $result['day']);
        $this->assertEquals('07', $result['hour']);
        $this->assertEquals('16', $result['minute']);
        $this->assertEquals('24', $result['second']);
        $this->assertEquals('37', $result['suffix']);
        
        // Verify timestamp fields are generated correctly (tilde metadata should be ignored)
        $this->assertEquals('2022-12-03 07:16:24.37', $result['timestamp']);
        $this->assertEquals(2022120307162437, $result['timestamp_int']);
    }
}

<?php

declare(strict_types=1);

namespace NwsCad;

/**
 * Filename Parser Utility
 * Parses NWS CAD XML filenames to extract call metadata
 *
 * Filename format: CallNumber_YYYYMMDDHHMMSSsuffix.xml
 * Example: 591_2026012705492672.xml
 *   - 591 = CallNumber
 *   - 2026 = Year
 *   - 01 = Month
 *   - 27 = Day
 *   - 05 = Hour
 *   - 49 = Minute
 *   - 26 = Second
 *   - 72 = Microsecond suffix
 *
 * Note: Files containing tildes (~) or other non-standard formats will be rejected
 *
 * @package NwsCad
 * @version 1.0.0
 */
class FilenameParser
{
    /**
     * Parse a CAD XML filename
     *
     * @param string $filename The filename to parse (with or without .xml extension)
     * @return array|null Array with parsed data or null if parsing fails
     *                    Returns: [
     *                      'call_number' => string,
     *                      'year' => string,
     *                      'month' => string,
     *                      'day' => string,
     *                      'hour' => string,
     *                      'minute' => string,
     *                      'second' => string,
     *                      'suffix' => string,
     *                      'timestamp' => string (YYYY-MM-DD HH:MM:SS.suffix),
     *                      'timestamp_int' => int (for comparison)
     *                    ]
     */
    public static function parse(string $filename): ?array
    {
        // Remove path and ensure we only have the filename
        $filename = basename($filename);
        
        // Remove .xml extension if present
        $filename = preg_replace('/\.xml$/i', '', $filename);
        
        // Pattern: CallNumber_YYYYMMDDHHMMSSsuffix
        // Reject files with tildes or other non-standard formats
        $pattern = '/^(\d+)_(\d{4})(\d{2})(\d{2})(\d{2})(\d{2})(\d{2})(\d+)$/';
        
        if (!preg_match($pattern, $filename, $matches)) {
            return null;
        }
        
        $callNumber = $matches[1];
        $year = $matches[2];
        $month = $matches[3];
        $day = $matches[4];
        $hour = $matches[5];
        $minute = $matches[6];
        $second = $matches[7];
        $suffix = $matches[8];
        
        // Validate date/time components
        $monthInt = (int)$month;
        $dayInt = (int)$day;
        $hourInt = (int)$hour;
        $minuteInt = (int)$minute;
        $secondInt = (int)$second;
        
        // Basic validation (allow some flexibility for edge cases)
        if ($monthInt < 1 || $monthInt > 12) {
            return null; // Invalid month
        }
        if ($dayInt < 1 || $dayInt > 31) {
            return null; // Invalid day
        }
        if ($hourInt > 23) {
            return null; // Invalid hour
        }
        if ($minuteInt > 59) {
            return null; // Invalid minute
        }
        if ($secondInt > 59) {
            return null; // Invalid second
        }
        
        // Create timestamp string
        $timestamp = sprintf(
            '%s-%s-%s %s:%s:%s.%s',
            $year,
            $month,
            $day,
            $hour,
            $minute,
            $second,
            $suffix
        );
        
        // Build numeric timestamp string (YYYYMMDDHHMMSSsuffix) for comparison use
        $timestampNumericString = $year . $month . $day . $hour . $minute . $second . $suffix;
        
        // Ensure the numeric timestamp fits within PHP's integer range before casting
        $phpIntMaxString = (string)PHP_INT_MAX;
        $timestampLength = strlen($timestampNumericString);
        $phpIntMaxLength = strlen($phpIntMaxString);
        
        if (
            $timestampLength > $phpIntMaxLength ||
            ($timestampLength === $phpIntMaxLength && $timestampNumericString > $phpIntMaxString)
        ) {
            // Out of range for a safe integer representation
            return null;
        }
        
        // Create integer for easy comparison (YYYYMMDDHHMMSSsuffix)
        $timestampInt = (int)$timestampNumericString;
        
        return [
            'call_number' => $callNumber,
            'year' => $year,
            'month' => $month,
            'day' => $day,
            'hour' => $hour,
            'minute' => $minute,
            'second' => $second,
            'suffix' => $suffix,
            'timestamp' => $timestamp,
            'timestamp_int' => $timestampInt
        ];
    }
    
    /**
     * Check if a filename is valid CAD XML format
     *
     * @param string $filename The filename to check
     * @return bool True if valid format
     */
    public static function isValid(string $filename): bool
    {
        return self::parse($filename) !== null;
    }
    
    /**
     * Extract call number from filename
     *
     * @param string $filename The filename
     * @return string|null The call number or null if parsing fails
     */
    public static function getCallNumber(string $filename): ?string
    {
        $parsed = self::parse($filename);
        return $parsed ? $parsed['call_number'] : null;
    }
    
    /**
     * Get timestamp integer for comparison
     *
     * @param string $filename The filename
     * @return int|null The timestamp as integer or null if parsing fails
     */
    public static function getTimestampInt(string $filename): ?int
    {
        $parsed = self::parse($filename);
        return $parsed ? $parsed['timestamp_int'] : null;
    }
    
    /**
     * Compare two filenames by timestamp
     *
     * @param string $filename1 First filename
     * @param string $filename2 Second filename
     * @return int|null -1 if file1 < file2, 0 if equal, 1 if file1 > file2, null if parsing fails
     */
    public static function compare(string $filename1, string $filename2): ?int
    {
        $ts1 = self::getTimestampInt($filename1);
        $ts2 = self::getTimestampInt($filename2);
        
        if ($ts1 === null || $ts2 === null) {
            return null;
        }
        
        if ($ts1 < $ts2) {
            return -1;
        } elseif ($ts1 > $ts2) {
            return 1;
        }
        return 0;
    }
    
    /**
     * Group files by call number
     *
     * @param array<string> $filenames Array of filenames
     * @return array<string, array<string>> Array grouped by call number
     */
    public static function groupByCallNumber(array $filenames): array
    {
        $grouped = [];
        
        foreach ($filenames as $filename) {
            $callNumber = self::getCallNumber($filename);
            if ($callNumber !== null) {
                if (!isset($grouped[$callNumber])) {
                    $grouped[$callNumber] = [];
                }
                $grouped[$callNumber][] = $filename;
            }
        }
        
        return $grouped;
    }
    
    /**
     * Get the latest file for each call number from a list
     *
     * @param array<string> $filenames Array of filenames
     * @return array<string> Array of latest filenames (one per call number)
     */
    public static function getLatestFiles(array $filenames): array
    {
        $grouped = self::groupByCallNumber($filenames);
        $latest = [];
        
        foreach ($grouped as $callNumber => $files) {
            if (count($files) === 1) {
                $latest[] = $files[0];
            } else {
                // Sort by timestamp (descending) and take the first
                usort($files, function ($a, $b) {
                    $result = self::compare($a, $b);
                    return $result === null ? 0 : -$result; // Reverse for descending
                });
                $latest[] = $files[0];
            }
        }
        
        return $latest;
    }
    
    /**
     * Get files that should be skipped (older versions)
     *
     * @param array<string> $filenames Array of filenames
     * @return array<string> Array of filenames to skip
     */
    public static function getFilesToSkip(array $filenames): array
    {
        $latestFiles = self::getLatestFiles($filenames);
        $latestSet = array_flip($latestFiles);
        
        return array_filter($filenames, function ($filename) use ($latestSet) {
            return !isset($latestSet[$filename]);
        });
    }
    
    /**
     * Get filenames that do not match the expected CAD XML naming pattern.
     *
     * This allows callers to explicitly handle legacy or incorrectly named files
     * that would otherwise be silently ignored by groupByCallNumber() and
     * considered for skipping by getFilesToSkip().
     *
     * @param array<string> $filenames Array of filenames
     * @return array<string> Array of filenames that could not be parsed
     */
    public static function getUnparseableFilenames(array $filenames): array
    {
        $unparseable = [];
        
        foreach ($filenames as $filename) {
            if (self::getCallNumber($filename) === null) {
                $unparseable[] = $filename;
            }
        }
        
        return $unparseable;
    }
}

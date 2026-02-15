<?php

declare(strict_types=1);

namespace NwsCad\Api;

use NwsCad\Database;
use InvalidArgumentException;

/**
 * Database Helper
 * 
 * Provides database-agnostic SQL functions for MySQL and PostgreSQL.
 * 
 * SECURITY NOTE: This class accepts column/table names and constructs SQL fragments.
 * These methods are intended for INTERNAL use only with hardcoded column names.
 * NEVER pass user input directly to these methods.
 * 
 * @package NwsCad\Api
 */
class DbHelper
{
    /**
     * Valid SQL identifier pattern (alphanumeric, underscores, dots for table.column)
     */
    private const IDENTIFIER_PATTERN = '/^[a-zA-Z_][a-zA-Z0-9_]*(\.[a-zA-Z_][a-zA-Z0-9_]*)?$/';
    
    /**
     * Validate that a string is a safe SQL identifier
     * 
     * @param string $identifier The identifier to validate
     * @param string $name Human-readable name for error messages
     * @throws InvalidArgumentException If identifier is invalid
     */
    private static function validateIdentifier(string $identifier, string $name = 'identifier'): void
    {
        if (!preg_match(self::IDENTIFIER_PATTERN, $identifier)) {
            throw new InvalidArgumentException(
                sprintf('Invalid SQL %s: "%s". Only alphanumeric characters, underscores, and single dots allowed.', $name, $identifier)
            );
        }
    }
    
    /**
     * Escape a separator string for safe use in SQL
     * 
     * @param string $separator The separator to escape
     * @return string Escaped separator
     */
    private static function escapeSeparator(string $separator): string
    {
        return str_replace("'", "''", $separator);
    }

    /**
     * Get GROUP_CONCAT (MySQL) or STRING_AGG (PostgreSQL) based on database type
     * 
     * @param string $column Column name (must be valid SQL identifier)
     * @param string $separator Separator string (default: ',')
     * @param bool $distinct Whether to use DISTINCT
     * @return string SQL expression
     * @throws InvalidArgumentException If column name is invalid
     */
    public static function groupConcat(string $column, string $separator = ',', bool $distinct = false): string
    {
        self::validateIdentifier($column, 'column');
        $escapedSeparator = self::escapeSeparator($separator);
        $dbType = Database::getDbType();
        
        if ($dbType === 'pgsql') {
            $distinctStr = $distinct ? 'DISTINCT ' : '';
            return "STRING_AGG({$distinctStr}{$column}, '{$escapedSeparator}')";
        }
        
        // MySQL
        $distinctStr = $distinct ? 'DISTINCT ' : '';
        return "GROUP_CONCAT({$distinctStr}{$column} SEPARATOR '{$escapedSeparator}')";
    }

    /**
     * Get HOUR function based on database type
     * 
     * @param string $column Column name containing timestamp
     * @return string SQL expression for hour extraction
     * @throws InvalidArgumentException If column name is invalid
     */
    public static function hour(string $column): string
    {
        self::validateIdentifier($column, 'column');
        $dbType = Database::getDbType();
        
        if ($dbType === 'pgsql') {
            return "EXTRACT(HOUR FROM {$column})";
        }
        
        // MySQL
        return "HOUR({$column})";
    }

    /**
     * Get DAY OF WEEK function based on database type
     * 
     * Returns 1-7 where 1=Sunday (MySQL convention)
     * 
     * @param string $column Column name containing timestamp
     * @return string SQL expression for day of week
     * @throws InvalidArgumentException If column name is invalid
     */
    public static function dayOfWeek(string $column): string
    {
        self::validateIdentifier($column, 'column');
        $dbType = Database::getDbType();
        
        if ($dbType === 'pgsql') {
            // PostgreSQL returns 0-6 (Sunday=0), MySQL returns 1-7 (Sunday=1)
            return "EXTRACT(DOW FROM {$column}) + 1";
        }
        
        // MySQL
        return "DAYOFWEEK({$column})";
    }

    /**
     * Get DATE function based on database type
     * 
     * @param string $column Column name containing timestamp
     * @return string SQL expression for date extraction
     * @throws InvalidArgumentException If column name is invalid
     */
    public static function date(string $column): string
    {
        self::validateIdentifier($column, 'column');
        $dbType = Database::getDbType();
        
        if ($dbType === 'pgsql') {
            return "DATE({$column})";
        }
        
        // MySQL
        return "DATE({$column})";
    }

    /**
     * Get CONCAT function for names based on database type
     * 
     * Properly handles null/empty middle names without double spaces.
     * 
     * @param string $first Column name for first name
     * @param string $middle Column name for middle name
     * @param string $last Column name for last name
     * @return string SQL expression for concatenated name
     * @throws InvalidArgumentException If any column name is invalid
     */
    public static function concatName(string $first, string $middle, string $last): string
    {
        self::validateIdentifier($first, 'first name column');
        self::validateIdentifier($middle, 'middle name column');
        self::validateIdentifier($last, 'last name column');
        
        $dbType = Database::getDbType();
        
        if ($dbType === 'pgsql') {
            return "TRIM(CONCAT({$first}, ' ', COALESCE({$middle} || ' ', ''), {$last}))";
        }
        
        // MySQL
        return "TRIM(CONCAT({$first}, ' ', IF({$middle} IS NOT NULL AND {$middle} != '', CONCAT({$middle}, ' '), ''), {$last}))";
    }

    /**
     * Get TIMESTAMPDIFF function based on database type
     * 
     * @param string $unit Time unit: SECOND, MINUTE, HOUR, or DAY
     * @param string $fromColumn Column name for start timestamp
     * @param string $toColumn Column name for end timestamp
     * @return string SQL expression for timestamp difference
     * @throws InvalidArgumentException If column names are invalid or unit is not allowed
     */
    public static function timestampDiff(string $unit, string $fromColumn, string $toColumn): string
    {
        self::validateIdentifier($fromColumn, 'from column');
        self::validateIdentifier($toColumn, 'to column');
        
        $allowedUnits = ['SECOND', 'MINUTE', 'HOUR', 'DAY'];
        $unit = strtoupper($unit);
        if (!in_array($unit, $allowedUnits, true)) {
            throw new InvalidArgumentException(
                sprintf('Invalid time unit: "%s". Allowed: %s', $unit, implode(', ', $allowedUnits))
            );
        }
        
        $dbType = Database::getDbType();
        
        if ($dbType === 'pgsql') {
            // PostgreSQL uses EXTRACT(EPOCH FROM ...) for seconds
            $extraction = "EXTRACT(EPOCH FROM ({$toColumn} - {$fromColumn}))";
            
            switch ($unit) {
                case 'SECOND':
                    return $extraction;
                case 'MINUTE':
                    return "({$extraction} / 60)";
                case 'HOUR':
                    return "({$extraction} / 3600)";
                case 'DAY':
                    return "({$extraction} / 86400)";
                default:
                    return $extraction;
            }
        }
        
        // MySQL
        return "TIMESTAMPDIFF({$unit}, {$fromColumn}, {$toColumn})";
    }

    /**
     * Get boolean value representation based on database type
     * 
     * @param bool $value Boolean value to represent
     * @return string SQL boolean literal
     */
    public static function boolValue(bool $value): string
    {
        $dbType = Database::getDbType();
        
        if ($dbType === 'pgsql') {
            return $value ? 'TRUE' : 'FALSE';
        }
        
        // MySQL
        return $value ? '1' : '0';
    }

    /**
     * Get Haversine distance formula for coordinate-based search
     * 
     * Returns distance in kilometers between the point specified by parameters
     * and the coordinates stored in the specified columns.
     * 
     * @param string $latColumn Column name containing latitude
     * @param string $lngColumn Column name containing longitude
     * @param string $latParam PDO parameter name for search latitude (default: :lat)
     * @param string $lngParam PDO parameter name for search longitude (default: :lng)
     * @return string SQL expression for Haversine distance
     * @throws InvalidArgumentException If column names or parameter names are invalid
     */
    public static function haversineDistance(string $latColumn, string $lngColumn, string $latParam = ':lat', string $lngParam = ':lng'): string
    {
        self::validateIdentifier($latColumn, 'latitude column');
        self::validateIdentifier($lngColumn, 'longitude column');
        
        // Validate parameter names (must start with : and be alphanumeric)
        if (!preg_match('/^:[a-zA-Z_][a-zA-Z0-9_]*$/', $latParam)) {
            throw new InvalidArgumentException(
                sprintf('Invalid latitude parameter name: "%s". Must be :name format.', $latParam)
            );
        }
        if (!preg_match('/^:[a-zA-Z_][a-zA-Z0-9_]*$/', $lngParam)) {
            throw new InvalidArgumentException(
                sprintf('Invalid longitude parameter name: "%s". Must be :name format.', $lngParam)
            );
        }
        
        $earthRadiusKm = 6371;
        
        return sprintf(
            "(%d * acos(
                cos(radians(%s)) * 
                cos(radians(%s)) * 
                cos(radians(%s) - radians(%s)) + 
                sin(radians(%s)) * 
                sin(radians(%s))
            ))",
            $earthRadiusKm,
            $latParam,
            $latColumn,
            $lngColumn,
            $lngParam,
            $latParam,
            $latColumn
        );
    }
}

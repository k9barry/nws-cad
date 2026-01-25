<?php

namespace NwsCad\Api;

use NwsCad\Database;

/**
 * Database Helper
 * Provides database-agnostic SQL functions
 */
class DbHelper
{
    /**
     * Get GROUP_CONCAT or STRING_AGG based on database type
     */
    public static function groupConcat(string $column, string $separator = ',', bool $distinct = false): string
    {
        $dbType = Database::getDbType();
        
        if ($dbType === 'pgsql') {
            $distinctStr = $distinct ? 'DISTINCT ' : '';
            return "STRING_AGG({$distinctStr}{$column}, '{$separator}')";
        }
        
        // MySQL
        $distinctStr = $distinct ? 'DISTINCT ' : '';
        return "GROUP_CONCAT({$distinctStr}{$column} SEPARATOR '{$separator}')";
    }

    /**
     * Get HOUR function based on database type
     */
    public static function hour(string $column): string
    {
        $dbType = Database::getDbType();
        
        if ($dbType === 'pgsql') {
            return "EXTRACT(HOUR FROM {$column})";
        }
        
        // MySQL
        return "HOUR({$column})";
    }

    /**
     * Get DAY OF WEEK function based on database type
     */
    public static function dayOfWeek(string $column): string
    {
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
     */
    public static function date(string $column): string
    {
        $dbType = Database::getDbType();
        
        if ($dbType === 'pgsql') {
            return "DATE({$column})";
        }
        
        // MySQL
        return "DATE({$column})";
    }

    /**
     * Get CONCAT function for names based on database type
     */
    public static function concatName(string $first, string $middle, string $last): string
    {
        $dbType = Database::getDbType();
        
        if ($dbType === 'pgsql') {
            return "CONCAT({$first}, ' ', COALESCE({$middle}, ''), ' ', {$last})";
        }
        
        // MySQL
        return "CONCAT({$first}, ' ', IFNULL({$middle}, ''), ' ', {$last})";
    }

    /**
     * Get TIMESTAMPDIFF function based on database type
     * 
     * @param string $unit - SECOND, MINUTE, HOUR, DAY
     */
    public static function timestampDiff(string $unit, string $fromColumn, string $toColumn): string
    {
        $dbType = Database::getDbType();
        
        if ($dbType === 'pgsql') {
            // PostgreSQL uses EXTRACT(EPOCH FROM ...) for seconds
            $extraction = "EXTRACT(EPOCH FROM ({$toColumn} - {$fromColumn}))";
            
            switch (strtoupper($unit)) {
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
}

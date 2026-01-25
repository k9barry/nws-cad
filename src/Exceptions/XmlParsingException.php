<?php

declare(strict_types=1);

namespace NwsCad\Exceptions;

use Exception;

/**
 * Exception for XML parsing errors
 * 
 * Thrown when XML file parsing fails or
 * XML structure is invalid.
 */
class XmlParsingException extends Exception
{
    /**
     * Create a new XmlParsingException for file read errors
     *
     * @param string $filename The XML filename
     * @param string $reason The reason for failure
     * @return self
     */
    public static function fileReadError(string $filename, string $reason): self
    {
        return new self("Failed to read XML file '$filename': $reason");
    }

    /**
     * Create a new XmlParsingException for invalid XML structure
     *
     * @param string $reason The reason for invalid structure
     * @return self
     */
    public static function invalidStructure(string $reason): self
    {
        return new self("Invalid XML structure: $reason");
    }

    /**
     * Create a new XmlParsingException for missing required elements
     *
     * @param string $element The missing element name
     * @return self
     */
    public static function missingElement(string $element): self
    {
        return new self("Required XML element is missing: $element");
    }

    /**
     * Create a new XmlParsingException for libxml errors
     *
     * @param array $errors Array of libxml errors
     * @return self
     */
    public static function libxmlErrors(array $errors): self
    {
        $messages = array_map(function($error) {
            return trim($error->message);
        }, $errors);
        
        $errorString = implode('; ', $messages);
        return new self("XML parsing errors: $errorString");
    }
}

<?php

declare(strict_types=1);

namespace NwsCad\Import;

use RuntimeException;

/**
 * Thrown by {@see XmlValidator} when a well-formed document is not a usable
 * Aegis CAD CallExport (wrong root, or missing a field the schema requires).
 *
 * Extends RuntimeException so AegisXmlParser::processFile()'s existing
 * `catch (Exception)` handles it — the file is marked failed and processFile()
 * returns false, exactly as an insert-time NOT NULL violation did before.
 */
final class InvalidXmlException extends RuntimeException
{
}

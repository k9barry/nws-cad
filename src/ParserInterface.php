<?php

declare(strict_types=1);

namespace NwsCad;

/**
 * Contract for the XML ingest step that {@see FileWatcher} drives.
 *
 * Extracted so FileWatcher can be unit-tested with a fake parser (no database,
 * no real XML) via the watcher's optional constructor seams. Production wiring
 * is unchanged: {@see AegisXmlParser} is the only implementation and remains
 * the default when no parser is injected.
 */
interface ParserInterface
{
    /**
     * Process a single XML file.
     *
     * @param string $filePath Absolute path to the file to ingest.
     * @return bool True on successful ingest, false on a handled failure.
     */
    public function processFile(string $filePath): bool;
}

<?php

declare(strict_types=1);

namespace NwsCad\Import;

use Exception;
use Psr\Log\LoggerInterface;
use SimpleXMLElement;

/**
 * Reads an Aegis CAD XML file into a SimpleXMLElement, applying the importer's
 * pre-parse guards: the XML_MAX_BYTES size cap, BOM stripping (UTF-8/UTF-16),
 * DOCTYPE (XXE) rejection, and the LIBXML_NOCDATA|LIBXML_NONET parse flags.
 *
 * Extracted verbatim from AegisXmlParser::loadXmlFile()/stripBOM() (#49) so the
 * file-loading concern is isolated and directly testable. Returns false (never
 * throws) on any failure, matching the original contract.
 */
final class XmlLoader
{
    public function __construct(private LoggerInterface $logger)
    {
    }

    public function load(string $filePath): SimpleXMLElement|false
    {
        try {
            $this->logger->debug("Checking if file exists: {$filePath}");
            if (!file_exists($filePath)) {
                $this->logger->error("File does not exist: {$filePath}");
                return false;
            }

            // DoS guard: reject oversized files before reading them into memory.
            // Configurable via XML_MAX_BYTES (default 10 MB). An oversized file
            // is rejected here so the caller moves it to failed/.
            $maxBytes = (int) (getenv('XML_MAX_BYTES') ?: 10 * 1024 * 1024);
            $fileSize = filesize($filePath);
            // Reject when the size is unknown (filesize() failed / non-regular
            // file) as well as when it is over the cap — never fall through to an
            // unbounded read.
            if ($fileSize === false || $fileSize > $maxBytes) {
                $this->logger->error(
                    "Rejecting file per XML_MAX_BYTES ({$maxBytes}): {$filePath} size=" .
                    ($fileSize === false ? 'unknown' : $fileSize)
                );
                return false;
            }

            // Read file content and strip BOM if present
            $this->logger->debug("Reading file contents...");
            $content = file_get_contents($filePath);
            if ($content === false) {
                $this->logger->error("Failed to read file: {$filePath}");
                return false;
            }

            // Backstop: enforce the cap on the bytes actually read, in case the
            // file grew between the filesize() check and the read.
            if (strlen($content) > $maxBytes) {
                $this->logger->error(
                    "File content exceeds XML_MAX_BYTES ({$maxBytes}): {$filePath} read " . strlen($content) . " bytes"
                );
                return false;
            }
            $this->logger->debug("File size: " . strlen($content) . " bytes");

            // Remove UTF-8 BOM (EF BB BF) if present
            // Many NWS CAD XML exports include BOM which can cause parsing issues
            $this->logger->debug("Checking for and stripping BOM...");
            $content = $this->stripBOM($content);

            // XXE / entity-expansion guard: reject any document that declares a
            // DOCTYPE. libxml2 >= 2.9 disables external entity loading by default
            // and LIBXML_NOENT is never set, but a DOCTYPE is never legitimate in
            // an Aegis CAD export, so refuse it outright rather than relying on
            // parser defaults. (LIBXML_NODDTD does not exist in PHP.)
            if (preg_match('/<!DOCTYPE/i', $content) === 1) {
                $this->logger->error("Rejected XML containing a DOCTYPE declaration: {$filePath}");
                return false;
            }

            // XXE Protection: In PHP 8.0+, external entity loading is disabled by default
            // LIBXML_NONET prevents network access during XML parsing
            $this->logger->debug("Parsing XML with XXE protection (LIBXML_NONET)...");
            libxml_use_internal_errors(true);
            $xml = simplexml_load_string($content, 'SimpleXMLElement', LIBXML_NOCDATA | LIBXML_NONET);

            if ($xml === false) {
                $errors = libxml_get_errors();
                $errorMessages = array_map(fn($error) => trim($error->message), $errors);
                libxml_clear_errors();

                $this->logger->error("Failed to load XML: " . implode(', ', $errorMessages));
                return false;
            }

            $this->logger->debug("XML parsed successfully");
            libxml_clear_errors();
            return $xml;

        } catch (Exception $e) {
            $this->logger->error("Exception loading XML file: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Strip BOM (Byte Order Mark) from content.
     * Handles UTF-8, UTF-16 BE, and UTF-16 LE BOMs.
     */
    private function stripBOM(string $content): string
    {
        // Check UTF-16 BOMs first (2 bytes) before UTF-8 (3 bytes)
        // UTF-16 BE BOM is FE FF
        if (strlen($content) >= 2 && substr($content, 0, 2) === "\xFE\xFF") {
            $this->logger->debug("Stripped UTF-16 BE BOM from XML file");
            return substr($content, 2);
        }

        // UTF-16 LE BOM is FF FE
        if (strlen($content) >= 2 && substr($content, 0, 2) === "\xFF\xFE") {
            $this->logger->debug("Stripped UTF-16 LE BOM from XML file");
            return substr($content, 2);
        }

        // UTF-8 BOM is EF BB BF (3 bytes)
        if (strlen($content) >= 3 && substr($content, 0, 3) === "\xEF\xBB\xBF") {
            $this->logger->debug("Stripped UTF-8 BOM from XML file");
            return substr($content, 3);
        }

        return $content;
    }
}

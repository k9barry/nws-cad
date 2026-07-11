<?php

declare(strict_types=1);

namespace NwsCad\Import;

use SimpleXMLElement;

/**
 * Pre-transaction validation of a loaded CallExport document (#49).
 *
 * The importer previously had no validation layer — a well-formed document with
 * the wrong root or missing key fields silently reached the insert step and
 * failed there (or wrote NULLs). This validator rejects those documents up
 * front, before any transaction is opened, with a clear {@see InvalidXmlException}.
 *
 * Scope is intentionally behavior-preserving: it requires only what already
 * caused an ingest to fail — a `CallExport` root and non-empty `CallId` /
 * `CallNumber` (both NOT NULL in the schema with no parser-side fallback).
 * `CreateDateTime` is NOT required here because insertCall() defaults a missing
 * value to "now"; requiring it would reject input the importer currently
 * accepts. Tightening the required set is a deliberate behavior change for a
 * later PR, not this refactor.
 */
final class XmlValidator
{
    private const ROOT_ELEMENT = 'CallExport';

    /** Fields whose absence already fails the insert (schema NOT NULL, no fallback). */
    private const REQUIRED_FIELDS = ['CallId', 'CallNumber'];

    /**
     * @throws InvalidXmlException if the document is not a usable CallExport.
     */
    public function validate(SimpleXMLElement $xml): void
    {
        $root = $xml->getName();
        if ($root !== self::ROOT_ELEMENT) {
            throw new InvalidXmlException(
                "Unexpected root element <{$root}>, expected <" . self::ROOT_ELEMENT . '>'
            );
        }

        $missing = [];
        foreach (self::REQUIRED_FIELDS as $field) {
            if ((string) $xml->{$field} === '') {
                $missing[] = $field;
            }
        }

        if ($missing !== []) {
            throw new InvalidXmlException('Missing required field(s): ' . implode(', ', $missing));
        }
    }
}

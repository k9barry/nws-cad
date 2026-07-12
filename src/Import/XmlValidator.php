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
 * caused an ingest to fail — a recognized call root (`Call`, as emitted by the
 * real Aegis feed, or the legacy `CallExport`) and non-empty `CallId` /
 * `CallNumber` (both NOT NULL in the schema with no parser-side fallback).
 * `CreateDateTime` is NOT required here because insertCall() defaults a missing
 * value to "now"; requiring it would reject input the importer currently
 * accepts. Tightening the required set is a deliberate behavior change for a
 * later PR, not this refactor.
 */
final class XmlValidator
{
    /**
     * Accepted document root elements. Real Aegis CAD exports use `<Call>` (the
     * element that carries CallId/CallNumber/AgencyContexts/AssignedUnits/etc.
     * directly). `<CallExport>` is accepted too for backward compatibility with
     * older/synthetic documents. The importer reads every field as a direct
     * child of whichever root is present, so both shapes map identically.
     *
     * @var string[]
     */
    private const ROOT_ELEMENTS = ['Call', 'CallExport'];

    /** Fields whose absence already fails the insert (schema NOT NULL, no fallback). */
    private const REQUIRED_FIELDS = ['CallId', 'CallNumber'];

    /**
     * @throws InvalidXmlException if the document is not a usable Aegis call export.
     */
    public function validate(SimpleXMLElement $xml): void
    {
        $root = $xml->getName();
        if (!in_array($root, self::ROOT_ELEMENTS, true)) {
            throw new InvalidXmlException(
                "Unexpected root element <{$root}>, expected one of <"
                . implode('>, <', self::ROOT_ELEMENTS) . '>'
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

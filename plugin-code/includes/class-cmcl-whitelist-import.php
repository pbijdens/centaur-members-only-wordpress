<?php
if (!defined('ABSPATH')) exit;

/**
 * Turns an uploaded whitelist file into a flat list of email addresses,
 * regardless of how it's formatted.
 *
 * Deliberately lenient: rather than expecting one address per line, it
 * scans for anything email-shaped wherever it appears — several addresses
 * on one line separated by commas/spaces/whatever, addresses inside HTML
 * table cells, or scattered across an Excel sheet. The file's real content
 * decides how it's read, not its extension: a genuine Office Open XML
 * (.xlsx) container is detected by its ZIP signature and its first sheet is
 * parsed properly; everything else (plain text, CSV, legacy binary .xls, or
 * — a common mistake — an HTML table someone saved with an .xls/.xlsx
 * extension) is scanned as raw text, which works for all of those cases
 * without needing to tell them apart.
 */
class CMCL_Whitelist_Import {

    // Deliberately loose (not a full RFC 5322 validator) — this only needs
    // to spot candidate substrings; is_email() does the real validation.
    const EMAIL_SCAN_PATTERN = '/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/';

    /**
     * @param string $file_path Path to the uploaded file on disk.
     * @return string[]|WP_Error Unique, normalized, validated email addresses.
     */
    public static function extract_emails_from_upload($file_path) {
        $raw = file_get_contents($file_path);
        if ($raw === false) {
            return new WP_Error('cmcl_read_error', 'Kon het bestand niet lezen.');
        }

        // Office Open XML (.xlsx) files are ZIP containers — this is the
        // only format that needs structure-aware parsing rather than a
        // plain text scan (a raw regex over compressed ZIP bytes won't find
        // anything).
        if (substr($raw, 0, 4) === "PK\x03\x04") {
            $text = self::extract_text_from_xlsx($file_path);
            if (is_wp_error($text)) {
                return $text;
            }
            return self::extract_emails_from_text($text);
        }

        return self::extract_emails_from_text($raw);
    }

    /**
     * @return string[] Unique, normalized, validated email addresses found
     *                   anywhere in $text.
     */
    public static function extract_emails_from_text($text) {
        if (!is_string($text) || $text === '') return [];

        if (!preg_match_all(self::EMAIL_SCAN_PATTERN, $text, $matches)) {
            return [];
        }

        $emails = [];
        foreach ($matches[0] as $candidate) {
            // Trailing punctuation swept up from surrounding prose/markup
            // (e.g. "mail ons op alice@example.com.").
            $candidate = rtrim($candidate, '.,;:');
            $email = CMCL_Whitelist::normalize_email($candidate);
            if ($email && is_email($email)) {
                $emails[$email] = true;
            }
        }
        return array_keys($emails);
    }

    /**
     * Concatenated, tag-stripped text of the shared-strings table and the
     * first worksheet (per workbook.xml's sheet order, not necessarily
     * "sheet1.xml" on disk) of an .xlsx file. Cell/row structure is
     * deliberately not preserved — extract_emails_from_text() only needs a
     * blob of text to scan.
     */
    private static function extract_text_from_xlsx($file_path) {
        if (!class_exists('ZipArchive')) {
            return new WP_Error('cmcl_zip_unavailable', 'Excel-bestanden (.xlsx) lezen vereist de PHP zip-extensie, die niet beschikbaar is op deze server.');
        }

        $zip = new ZipArchive();
        if ($zip->open($file_path) !== true) {
            return new WP_Error('cmcl_zip_error', 'Kon het Excel-bestand niet openen.');
        }

        $workbook_xml = self::zip_read($zip, 'xl/workbook.xml');
        $rels_xml     = self::zip_read($zip, 'xl/_rels/workbook.xml.rels');
        $shared_xml   = self::zip_read($zip, 'xl/sharedStrings.xml');

        $sheet_target = self::resolve_first_sheet_target($workbook_xml, $rels_xml);
        $sheet_path = $sheet_target ? ('xl/' . ltrim($sheet_target, '/')) : 'xl/worksheets/sheet1.xml';
        $sheet_xml = self::zip_read($zip, $sheet_path);

        $zip->close();

        if ($sheet_xml === null) {
            return new WP_Error('cmcl_zip_error', 'Kon het eerste werkblad niet vinden in het Excel-bestand.');
        }

        return self::strip_tags_to_text((string) $shared_xml) . ' ' . self::strip_tags_to_text($sheet_xml);
    }

    private static function zip_read(ZipArchive $zip, $name) {
        $contents = $zip->getFromName($name);
        return $contents === false ? null : $contents;
    }

    private static function strip_tags_to_text($xml) {
        if (!$xml) return '';
        return preg_replace('/<[^>]+>/', ' ', $xml);
    }

    /**
     * Resolves the ZIP entry for the first sheet listed in workbook.xml's
     * <sheets> order via its relationship id in workbook.xml.rels. Returns
     * null (falling back to the "sheet1.xml" default) if the workbook uses
     * a layout this doesn't recognize — real-world exports overwhelmingly
     * do put the first sheet at that path anyway.
     */
    private static function resolve_first_sheet_target($workbook_xml, $rels_xml) {
        if (!$workbook_xml || !$rels_xml) return null;

        if (!preg_match('/<sheets>(.*?)<\/sheets>/s', $workbook_xml, $sheets_block)) {
            return null;
        }
        if (!preg_match('/<sheet\b[^>]*\/>/s', $sheets_block[1], $first_sheet)) {
            return null;
        }
        if (!preg_match('/r:id="([^"]+)"/', $first_sheet[0], $rid_match)) {
            return null;
        }
        $rid = $rid_match[1];

        if (!preg_match('/<Relationship\b[^>]*\bId="' . preg_quote($rid, '/') . '"[^>]*\bTarget="([^"]+)"/s', $rels_xml, $target_match)) {
            return null;
        }
        return $target_match[1];
    }
}

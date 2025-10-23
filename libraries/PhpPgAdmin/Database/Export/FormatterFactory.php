<?php

namespace PhpPgAdmin\Database\Export;

/**
 * Factory for creating output formatter instances
 */
class FormatterFactory
{
    /**
     * Supported output formats
     */
    private const SUPPORTED_FORMATS = [
        'sql' => SqlFormatter::class,
        'csv' => CsvFormatter::class,
        'tab' => TabFormatter::class,
        'html' => HtmlFormatter::class,
        'xml' => XmlFormatter::class,
        'json' => JsonFormatter::class,
    ];

    /**
     * Create an output formatter instance for the specified format
     *
     * @param string $format The output format name (sql, copy, csv, tab, html, xml, json)
     * @return OutputFormatter The formatter instance
     * @throws \InvalidArgumentException If format is not supported
     */
    public static function create(string $format): OutputFormatter
    {
        $format = strtolower(trim($format));

        if (!isset(self::SUPPORTED_FORMATS[$format])) {
            throw new \InvalidArgumentException(
                "Unsupported output format: {$format}. Supported formats: " . implode(', ', array_keys(self::SUPPORTED_FORMATS))
            );
        }

        $className = self::SUPPORTED_FORMATS[$format];
        return new $className();
    }

    /**
     * Check if a format is supported
     */
    public static function isSupported(string $format): bool
    {
        return isset(self::SUPPORTED_FORMATS[strtolower(trim($format))]);
    }

    /**
     * Get list of all supported formats
     */
    public static function getSupportedFormats(): array
    {
        return array_keys(self::SUPPORTED_FORMATS);
    }

    /**
     * Get supported formats for a specific subject type
     * Restricts complex formats (CSV, XML, HTML, JSON) to table/view data exports only
     */
    public static function getSupportedFormatsForSubject(string $subject, string $exportType = 'structureanddata'): array
    {
        // All subjects can export as SQL or COPY
        $formats = ['sql', 'copy'];

        // CSV, TAB, HTML, XML, JSON only for table/view data-inclusive exports
        if (in_array($subject, ['table', 'view']) && in_array($exportType, ['dataonly', 'structureanddata'])) {
            $formats = array_merge($formats, ['csv', 'tab', 'html', 'xml', 'json']);
        }

        return $formats;
    }
}

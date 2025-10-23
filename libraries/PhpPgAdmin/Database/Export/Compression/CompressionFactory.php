<?php
namespace PhpPgAdmin\Database\Export\Compression;

class CompressionFactory
{
    /**
     * Create a compression strategy for given type.
     * Supported types: 'download'|'plain'|'gzipped'|'gzip'|'bzip2'|'bz2'|'zip'
     * @param string $type
     * @return CompressionStrategy|null
     */
    public static function create(string $type): ?CompressionStrategy
    {
        $t = strtolower(trim($type));
        switch ($t) {
            case 'download':
            case 'plain':
                return new PlainStrategy();
            case 'gzipped':
            case 'gzip':
                return new GzipStrategy();
            case 'bzip2':
            case 'bz2':
                return new Bzip2Strategy();
            case 'zip':
                return new ZipStrategy();
            default:
                return null;
        }
    }

    /**
     * Returns support flags for compression formats based on available PHP extensions.
     *
     * @return array{gzip:bool,zip:bool,bzip2:bool}
     */
    public static function capabilities(): array
    {
        static $caps = null;
        if ($caps !== null) {
            return $caps;
        }

        $caps = [
            'gzip' => function_exists('gzopen'),
            'zip' => class_exists('ZipArchive'),
            'bzip2' => function_exists('bzopen'),
        ];

        return $caps;
    }

    /**
     * Checks if given compression type is supported.
     *
     * @param string $type
     * @return bool
     */
    public static function isSupported(string $type): bool
    {
        if ($type === 'plain' || $type === 'download') {
            return true;
        }

        $caps = self::capabilities();
        return isset($caps[$type]) && (bool) $caps[$type];
    }
}

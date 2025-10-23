<?php

namespace PhpPgAdmin\Database\Import\Data;

class RowToCopyConverter
{
    private $nullPatterns;
    private $byteaEncoding;

    /**
     * @param array $nullPatterns strings that should be treated as NULL
     * @param string $byteaEncoding "hex" | "base64" | "escape"
     */
    public function __construct(array $nullPatterns, string $byteaEncoding = 'hex')
    {
        $this->nullPatterns = array_combine(
            $nullPatterns,
            array_fill(0, count($nullPatterns), true)
        );

        $this->byteaEncoding = $byteaEncoding;
    }

    /**
     * @param array $rowValues Numeric or assoc row values
     * @param array $mapping   Target column names in order
     * @param array $meta      Table meta (array of ['name','type','is_bytea','is_json'])
     * @param bool  $assoc     Whether $rowValues is associative by column name
     */
    public function toCopyLine(array $rowValues, array $mapping, array $meta, bool $assoc): string
    {
        $lineParts = [];

        foreach ($mapping as $idx => $colName) {

            // 1) Get value
            $value = $assoc
                ? ($rowValues[$colName] ?? null)
                : ($rowValues[$idx] ?? null);

            // 2) NULL?
            if ($value === null) {
                $lineParts[] = '\\N';
                continue;
            }
            if (is_string($value) && isset($this->nullPatterns[$value])) {
                $lineParts[] = '\\N';
                continue;
            }

            // 3) Bytea?
            if (!empty($meta[$idx]['is_bytea'])) {
                $lineParts[] = $this->encodeBytea((string) $value);
                continue;
            }

            // 4) JSON?
            if (!empty($meta[$idx]['is_json'])) {
                // JSON values must be escaped as text
                $jsonText = is_string($value)
                    ? $value
                    : json_encode($value, JSON_UNESCAPED_UNICODE);

                $lineParts[] = $this->escapeCopyText($jsonText);
                continue;
            }

            // 5) Normal text value
            $lineParts[] = $this->escapeCopyText((string) $value);
        }

        return implode("\t", $lineParts) . "\n";
    }

    /**
     * Bytea dekodieren und in PostgreSQL COPY-octal-Format umwandeln
     */
    private function encodeBytea(string $value): string
    {
        switch ($this->byteaEncoding) {

            case 'base64':
                $bin = base64_decode($value, true);
                if ($bin === false) {
                    // Fallback: treat as literal text
                    return $this->escapeCopyText($value);
                }
                return bytea_to_octal($bin);

            case 'octal':
                // Already in octal format
                return $value;
            case 'escape':
                // PostgreSQL escape format → unescape
                $bin = pg_unescape_bytea($value);
                return bytea_to_octal($bin);

            case 'hex':
            default:
                // Normalize hex prefix: \xDEADBEEF, 0xDEADBEEF, \XDEADBEEF, 0XDEADBEEF
                if (
                    (strlen($value) > 2) &&
                    ($value[0] === '\\' && ($value[1] === 'x' || $value[1] === 'X')) ||
                    ($value[0] === '0' && ($value[1] === 'x' || $value[1] === 'X'))
                ) {
                    $value = substr($value, 2);
                }

                $bin = @hex2bin($value);
                if ($bin === false) {
                    // Fallback: treat as literal text
                    return $this->escapeCopyText($value);
                }

                return bytea_to_octal($bin);
        }
    }

    /**
     * COPY-Text escapen
     */
    private function escapeCopyText(string $value): string
    {
        // Standard COPY escaping
        $escaped = addcslashes($value, "\0\\\n\r\t");

        // Fix für oktale Sequenzen
        return preg_replace('/\\\\([0-7]{3})/', '\\\\\\1', $escaped);
    }
}

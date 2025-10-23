<?php
namespace PhpPgAdmin\Database\Export\Compression;

/**
 * Minimal in-process ZIP streamer using raw DEFLATE via deflate_init/deflate_add.
 * Exposes a stream wrapper protocol 'phppgadminzip://' where the path is an id.
 * Formatter code writes to fopen('phppgadminzip://<id>', 'w') and data is streamed
 * directly to php://output as a ZIP file without buffering the whole dump.
 */
class ZipStreamer
{
    protected $out; // output resource (php://output)
    protected $bytesWritten = 0;
    protected $entries = [];

    // current file state
    protected $current = null;

    public function __construct($out)
    {
        $this->out = $out;
        $this->bytesWritten = 0;
    }

    protected function writeRaw(string $data): void
    {
        $written = fwrite($this->out, $data);
        if ($written === false) {
            throw new \RuntimeException('Failed to write to output stream');
        }
        $this->bytesWritten += $written;
        @fflush($this->out);
        if (function_exists('ob_flush')) {
            @ob_flush();
        }
        if (function_exists('flush')) {
            @flush();
        }
    }

    protected static function dosTimeDate(int $timestamp = null): array
    {
        if ($timestamp === null) {
            $timestamp = time();
        }
        $d = getdate($timestamp);
        $dosTime = ($d['seconds'] >> 1) | ($d['minutes'] << 5) | ($d['hours'] << 11);
        $dosDate = ($d['mday']) | ($d['mon'] << 5) | (($d['year'] - 1980) << 9);
        return [$dosTime & 0xffff, $dosDate & 0xffff];
    }

    protected static function packLE64(int $n): string
    {
        $low = $n & 0xffffffff;
        $high = ($n >> 32) & 0xffffffff;
        return pack('V', $low) . pack('V', $high);
    }

    public function startFile(string $name): void
    {
        if ($this->current !== null) {
            throw new \LogicException('A file is already open');
        }

        $localHeaderOffset = $this->bytesWritten;

        $gpFlag = 0x08;
        $compMethod = 8; // deflate
        list($modTime, $modDate) = self::dosTimeDate();

        $fname = $name;
        $fnameLen = strlen($fname);

        $local = '';
        $local .= pack('V', 0x04034b50);
        $local .= pack('v', 20); // version needed
        $local .= pack('v', $gpFlag);
        $local .= pack('v', $compMethod);
        $local .= pack('v', $modTime);
        $local .= pack('v', $modDate);
        $local .= pack('V', 0); // crc32 unknown
        $local .= pack('V', 0); // comp size unknown
        $local .= pack('V', 0); // uncomp size unknown
        $local .= pack('v', $fnameLen);
        $local .= pack('v', 0); // extra len
        $local .= $fname;

        $this->writeRaw($local);

        $this->current = (object) [
            'name' => $fname,
            'localHeaderOffset' => $localHeaderOffset,
            'crcCtx' => hash_init('crc32b'),
            'uncompressedSize' => 0,
            'compressedSize' => 0,
            'deflateCtx' => deflate_init(
                defined('ZLIB_ENCODING_RAW') ? ZLIB_ENCODING_RAW : -15,
                ['level' => -1]
            ),
        ];
    }

    public function writeData(string $data): void
    {
        if ($this->current === null) {
            throw new \LogicException('No open file to write to');
        }

        hash_update($this->current->crcCtx, $data);
        $this->current->uncompressedSize += strlen($data);

        $deflated = deflate_add($this->current->deflateCtx, $data, ZLIB_NO_FLUSH);
        if ($deflated !== '') {
            $this->writeRaw($deflated);
            $this->current->compressedSize += strlen($deflated);
        }
    }

    public function finishFile(): void
    {
        if ($this->current === null) {
            return;
        }

        $tail = deflate_add($this->current->deflateCtx, '', ZLIB_FINISH);
        if ($tail !== '') {
            $this->writeRaw($tail);
            $this->current->compressedSize += strlen($tail);
        }

        $crcHex = hash_final($this->current->crcCtx);
        $crc32 = (int) sprintf('%u', hexdec($crcHex));

        $desc = '';
        $desc .= pack('V', 0x08074b50);
        $desc .= pack('V', $crc32);
        if ($this->current->compressedSize > 0xFFFFFFFF || $this->current->uncompressedSize > 0xFFFFFFFF) {
            $desc .= self::packLE64($this->current->compressedSize);
            $desc .= self::packLE64($this->current->uncompressedSize);
        } else {
            $desc .= pack('V', $this->current->compressedSize);
            $desc .= pack('V', $this->current->uncompressedSize);
        }
        $this->writeRaw($desc);

        $this->entries[] = [
            'name' => $this->current->name,
            'localHeaderOffset' => $this->current->localHeaderOffset,
            'crc32' => $crc32,
            'compressedSize' => $this->current->compressedSize,
            'uncompressedSize' => $this->current->uncompressedSize,
        ];

        $this->current = null;
    }

    public function finish(): void
    {
        $centralStart = $this->bytesWritten;
        $entries = count($this->entries);

        $useZip64 = false;
        foreach ($this->entries as $ee) {
            if ($ee['compressedSize'] > 0xFFFFFFFF || $ee['uncompressedSize'] > 0xFFFFFFFF || $ee['localHeaderOffset'] > 0xFFFFFFFF) {
                $useZip64 = true;
                break;
            }
        }

        foreach ($this->entries as $e) {
            $fname = $e['name'];
            $fnameLen = strlen($fname);
            list($modTime, $modDate) = self::dosTimeDate();

            $cent = '';
            $cent .= pack('V', 0x02014b50);
            $cent .= pack('v', $useZip64 ? 45 : 20);
            $cent .= pack('v', $useZip64 ? 45 : 20);
            $cent .= pack('v', 0x08);
            $cent .= pack('v', 8);
            $cent .= pack('v', $modTime);
            $cent .= pack('v', $modDate);
            $cent .= pack('V', $e['crc32']);

            if ($e['compressedSize'] > 0xFFFFFFFF || $e['uncompressedSize'] > 0xFFFFFFFF) {
                $cent .= pack('V', 0xFFFFFFFF);
                $cent .= pack('V', 0xFFFFFFFF);
            } else {
                $cent .= pack('V', $e['compressedSize']);
                $cent .= pack('V', $e['uncompressedSize']);
            }

            $cent .= pack('v', $fnameLen);

            $extra = '';
            $zip64data = '';
            if ($e['uncompressedSize'] > 0xFFFFFFFF) {
                $zip64data .= self::packLE64($e['uncompressedSize']);
            }
            if ($e['compressedSize'] > 0xFFFFFFFF) {
                $zip64data .= self::packLE64($e['compressedSize']);
            }
            if ($e['localHeaderOffset'] > 0xFFFFFFFF) {
                $zip64data .= self::packLE64($e['localHeaderOffset']);
            }
            if ($zip64data !== '') {
                $extra .= pack('v', 0x0001);
                $extra .= pack('v', strlen($zip64data));
                $extra .= $zip64data;
            }

            $cent .= pack('v', strlen($extra));
            $cent .= pack('v', 0);
            $cent .= pack('v', 0);
            $cent .= pack('v', 0);
            $cent .= pack('V', 0);

            if ($e['localHeaderOffset'] > 0xFFFFFFFF) {
                $cent .= pack('V', 0xFFFFFFFF);
            } else {
                $cent .= pack('V', $e['localHeaderOffset']);
            }

            $cent .= $fname;
            $cent .= $extra;

            $this->writeRaw($cent);
        }

        $centralEnd = $this->bytesWritten;
        $centralSize = $centralEnd - $centralStart;
        $centralOffset = $centralStart;

        if ($useZip64 || $centralSize > 0xFFFFFFFF || $centralOffset > 0xFFFFFFFF || $entries > 0xFFFF) {
            $eocd64 = '';
            $eocd64 .= pack('V', 0x06064b50);
            $eocd64 .= self::packLE64(44);
            $eocd64 .= pack('v', 45);
            $eocd64 .= pack('v', 45);
            $eocd64 .= pack('V', 0);
            $eocd64 .= pack('V', 0);
            $eocd64 .= self::packLE64($entries);
            $eocd64 .= self::packLE64($entries);
            $eocd64 .= self::packLE64($centralSize);
            $eocd64 .= self::packLE64($centralOffset);
            $this->writeRaw($eocd64);

            $locator = '';
            $locator .= pack('V', 0x07064b50);
            $locator .= pack('V', 0);
            $locator .= self::packLE64($centralEnd);
            $locator .= pack('V', 1);
            $this->writeRaw($locator);

            $eocd = '';
            $eocd .= pack('V', 0x06054b50);
            $eocd .= pack('v', 0xFFFF);
            $eocd .= pack('v', 0xFFFF);
            $eocd .= pack('v', 0xFFFF);
            $eocd .= pack('v', 0xFFFF);
            $eocd .= pack('V', 0xFFFFFFFF);
            $eocd .= pack('V', 0xFFFFFFFF);
            $eocd .= pack('v', 0);

            $this->writeRaw($eocd);
        } else {
            $eocd = '';
            $eocd .= pack('V', 0x06054b50);
            $eocd .= pack('v', 0);
            $eocd .= pack('v', 0);
            $eocd .= pack('v', $entries);
            $eocd .= pack('v', $entries);
            $eocd .= pack('V', $centralSize);
            $eocd .= pack('V', $centralOffset);
            $eocd .= pack('v', 0);

            $this->writeRaw($eocd);
        }

        if (function_exists('fflush')) {
            @fflush($this->out);
        }
        if (function_exists('ob_flush')) {
            @ob_flush();
        }
        if (function_exists('flush')) {
            @flush();
        }
    }
}

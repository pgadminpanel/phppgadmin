<?php
namespace PhpPgAdmin\Database\Export\Compression;

use Ossrock\FflatePhp\FflatePhp;

class GzipStrategy implements CompressionStrategy
{
    public function begin(string $filename): array
    {
        if (!extension_loaded('zlib')) {
            throw new \RuntimeException('Gzip compression requires zlib extension');
        }

        // Clear buffers
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        ini_set('output_buffering', 'Off');
        ini_set('zlib.output_compression', 'Off');

        header('Content-Type: application/gzip');
        header("Content-Disposition: attachment; filename={$filename}.gz");

        $stream = fopen('php://output', 'wb');
        if ($stream === false) {
            throw new \RuntimeException('Could not open php://output');
        }

        $filter = stream_filter_append($stream, 'zlib.deflate', STREAM_FILTER_WRITE, ['window' => 31]);
        if ($filter === false) {
            fclose($stream);
            throw new \RuntimeException('Could not attach gzip filter');
        }

        return ['stream' => $stream, 'filter' => $filter];
    }

    public function finish(array $handle): void
    {
        if (isset($handle['stream']) && is_resource($handle['stream'])) {
            fclose($handle['stream']);
        }
    }
}

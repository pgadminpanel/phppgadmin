<?php
namespace PhpPgAdmin\Database\Export\Compression;

class Bzip2Strategy implements CompressionStrategy
{
    public function begin(string $filename): array
    {
        if (!extension_loaded('bz2')) {
            throw new \RuntimeException('Bzip2 compression requires bz2 extension');
        }

        // Clear buffers
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        ini_set('output_buffering', 'Off');
        ini_set('zlib.output_compression', 'Off');

        header('Content-Type: application/x-bzip2');
        header("Content-Disposition: attachment; filename={$filename}.bz2");

        $stream = fopen('php://output', 'wb');
        if ($stream === false) {
            throw new \RuntimeException('Could not open php://output');
        }

        // Todo use maximum compression level ('blocks' => 9)?
        $param = array('blocks' => 5, 'work' => 0);
        $filter = stream_filter_append($stream, 'bzip2.compress', STREAM_FILTER_WRITE, $param);
        if ($filter === false) {
            fclose($stream);
            throw new \RuntimeException('Could not attach bzip2 filter');
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

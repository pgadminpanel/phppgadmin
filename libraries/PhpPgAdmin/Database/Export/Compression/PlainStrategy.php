<?php
namespace PhpPgAdmin\Database\Export\Compression;

class PlainStrategy implements CompressionStrategy
{
    public function begin(string $filename): array
    {
        // Clear buffers
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        header('Content-Type: application/octet-stream');
        header("Content-Disposition: attachment; filename={$filename}");

        $stream = fopen('php://output', 'wb');

        return ['stream' => $stream];
    }

    public function finish(array $handle): void
    {
        if (isset($handle['stream']) && is_resource($handle['stream'])) {
            fclose($handle['stream']);
        }
    }
}

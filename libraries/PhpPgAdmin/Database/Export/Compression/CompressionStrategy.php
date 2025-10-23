<?php
namespace PhpPgAdmin\Database\Export\Compression;

interface CompressionStrategy
{
    /**
     * Begin compression pipeline for given base filename.
     * Returns array with 'stream' (for dumper to write to) and optional metadata.
     * @param string $filename
     * @return array ['stream' => resource, ...metadata]
     */
    public function begin(string $filename): array;

    /**
     * Finish and cleanup the pipeline handle returned by begin().
     * @param array $handle
     * @return void
     */
    public function finish(array $handle): void;
}

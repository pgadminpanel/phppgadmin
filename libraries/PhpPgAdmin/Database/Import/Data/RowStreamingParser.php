<?php

namespace PhpPgAdmin\Database\Import\Data;

interface RowStreamingParser
{
    /**
     * @param string $chunk    Combined remainder + new chunk bytes (decoded)
     * @param array  $state    Parser-specific state stored between chunks
     *
     * @return array{rows: array<int,array>, remainder: string, header: array|null}
     */
    public function parse(string $chunk, array &$state): array;

    public function isAssociative(): bool;
}
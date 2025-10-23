<?php

namespace PhpPgAdmin\Database\Actions;

use PhpPgAdmin\Core\AppContext;
use PhpPgAdmin\Database\Postgres;

abstract class ActionsBase extends AppContext
{
    /**
     * @var Postgres
     */
    protected $connection;

    public function __construct(?Postgres $connection = null)
    {
        $this->connection = $connection ?? $this->postgres();
    }
}

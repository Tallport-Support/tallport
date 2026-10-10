<?php

namespace Tests\Feature;

/**
 * SearchTest on MariaDB, production's database, whatever database the suite runs on.
 */
class SearchMariaDBTest extends SearchTest
{
    use \Tests\Concerns\UsesMariaDB;
}

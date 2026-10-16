<?php

declare(strict_types=1);

namespace Tests;

use App\Shared\Support\Transactions;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // D17: RefreshDatabase has opened its wrapper transaction by now; that level means "no transaction".
        Transactions::$baseLevel = DB::transactionLevel();
    }

    protected function tearDown(): void
    {
        Transactions::$baseLevel = 0;

        parent::tearDown();
    }
}

<?php

namespace Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Tests\Concerns\RefreshSharedPostgresDatabase;

abstract class TestCase extends BaseTestCase
{
    use RefreshSharedPostgresDatabase;

    protected function setUpTraits()
    {
        $originalTraits = $this->traitsUsedByTest;
        $uses = $originalTraits;

        if (isset($uses[RefreshDatabase::class])) {
            $this->refreshSharedPostgresDatabase();
            unset($uses[RefreshDatabase::class]);
        }

        $this->traitsUsedByTest = $uses;

        try {
            return parent::setUpTraits();
        } finally {
            $this->traitsUsedByTest = $originalTraits;
        }
    }
}

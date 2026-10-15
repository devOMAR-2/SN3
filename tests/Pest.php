<?php

use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| Feature tests boot the Laravel application. Tests that touch the database
| must opt in to RefreshDatabase explicitly and run against PostgreSQL.
|
*/

pest()->extend(TestCase::class)->in('Feature');

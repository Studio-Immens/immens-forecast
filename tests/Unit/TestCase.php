<?php
/**
 * Base test case with Brain Monkey set up.
 *
 * @package ImmensForecast\Tests\Unit
 */

namespace ImmensForecast\Tests\Unit;

use Brain\Monkey;
use PHPUnit\Framework\TestCase as PhpUnitTestCase;

/**
 * Brain Monkey base test case.
 */
abstract class TestCase extends PhpUnitTestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}
}

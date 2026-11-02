<?php
/**
 * Configurable source double for unit tests.
 *
 * @package ImmensForecast\Tests\Unit\Sources
 */

namespace ImmensForecast\Tests\Unit\Sources;

use ImmensForecast\Sources\SourceInterface;

/**
 * Stub source.
 */
final class StubSource implements SourceInterface {

	/**
	 * Source id.
	 *
	 * @var string
	 */
	private $id;

	/**
	 * Availability flag.
	 *
	 * @var bool
	 */
	private $available;

	/**
	 * Value returned by get_series().
	 *
	 * @var mixed
	 */
	private $series;

	/**
	 * Number of get_series() calls.
	 *
	 * @var int
	 */
	public $calls = 0;

	/**
	 * Last args passed to get_series().
	 *
	 * @var array
	 */
	public $last_args = array();

	/**
	 * Constructor.
	 *
	 * @param string $id        Source id.
	 * @param bool   $available Availability.
	 * @param mixed  $series    get_series() return value.
	 */
	public function __construct( string $id = 'stub', bool $available = true, $series = array() ) {
		$this->id        = $id;
		$this->available = $available;
		$this->series    = $series;
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_id(): string {
		return $this->id;
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_label(): string {
		return 'Stub';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_description(): string {
		return 'Stub source';
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_available(): bool {
		return $this->available;
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_metrics(): array {
		return array(
			'revenue' => array(
				'label'         => 'Revenue',
				'granularities' => array( 'day', 'week' ),
			),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array $args Args.
	 * @return array|\WP_Error
	 */
	public function get_series( array $args ) {
		++$this->calls;
		$this->last_args = $args;
		return $this->series;
	}
}

<?php
/**
 * WooCommerce order doubles for WooSource tests.
 *
 * @package ImmensForecast\Tests\Unit\Sources
 */

namespace ImmensForecast\Tests\Unit\Sources;

/**
 * Date object mimicking WC_DateTime->date().
 */
final class FakeWooDate {

	/**
	 * Stored date-time.
	 *
	 * @var string
	 */
	private $datetime;

	/**
	 * Constructor.
	 *
	 * @param string $datetime Date-time string.
	 */
	public function __construct( string $datetime ) {
		$this->datetime = $datetime;
	}

	/**
	 * Format the stored date.
	 *
	 * @param string $format Date format.
	 * @return string
	 */
	public function date( $format ): string {
		return gmdate( $format, (int) strtotime( $this->datetime ) );
	}
}

/**
 * Line item exposing get_quantity().
 */
final class FakeWooItem {

	/**
	 * Quantity.
	 *
	 * @var int
	 */
	private $quantity;

	/**
	 * Constructor.
	 *
	 * @param int $quantity Quantity.
	 */
	public function __construct( int $quantity ) {
		$this->quantity = $quantity;
	}

	/**
	 * Quantity.
	 *
	 * @return int
	 */
	public function get_quantity(): int {
		return $this->quantity;
	}
}

/**
 * Order/refund object exposing the WC_Order methods WooSource uses.
 */
final class FakeWooOrder {

	/**
	 * Created date-time.
	 *
	 * @var string
	 */
	private $date;

	/**
	 * Order total.
	 *
	 * @var float
	 */
	private $total;

	/**
	 * Total tax.
	 *
	 * @var float
	 */
	private $tax;

	/**
	 * Line item quantities.
	 *
	 * @var int[]
	 */
	private $quantities;

	/**
	 * Constructor.
	 *
	 * @param string $date       Created date-time.
	 * @param float  $total      Order total.
	 * @param float  $tax        Total tax.
	 * @param int[]  $quantities Line item quantities.
	 */
	public function __construct( string $date, float $total, float $tax, array $quantities = array( 1 ) ) {
		$this->date       = $date;
		$this->total      = $total;
		$this->tax        = $tax;
		$this->quantities = $quantities;
	}

	/**
	 * Creation date (site timezone).
	 *
	 * @return FakeWooDate
	 */
	public function get_date_created(): FakeWooDate {
		return new FakeWooDate( $this->date );
	}

	/**
	 * Order total.
	 *
	 * @return float
	 */
	public function get_total(): float {
		return $this->total;
	}

	/**
	 * Total tax.
	 *
	 * @return float
	 */
	public function get_total_tax(): float {
		return $this->tax;
	}

	/**
	 * Line items.
	 *
	 * @return FakeWooItem[]
	 */
	public function get_items(): array {
		$items = array();
		foreach ( $this->quantities as $quantity ) {
			$items[] = new FakeWooItem( (int) $quantity );
		}
		return $items;
	}
}

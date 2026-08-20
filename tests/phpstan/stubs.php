<?php
/**
 * PHPStan stubs.
 *
 * Narrows PHPDoc that WordPress core leaves as a bare `array` where the keys
 * are in fact known. Only the types are read from here — the declarations
 * themselves still come from the WordPress stubs.
 *
 * @package PublicCollaboration
 */

declare(strict_types = 1);

abstract class WP_REST_Controller {
	/**
	 * Cached results of get_item_schema.
	 *
	 * A JSON Schema object: keyed by keyword, as what is handed to
	 * add_additional_fields_schema() and returned from get_item_schema() has
	 * to be for the REST server to read it at all.
	 *
	 * @var array<string, mixed>
	 */
	protected $schema;

	/**
	 * Adds the schema from additional fields to a schema array.
	 *
	 * Additional fields are registered under their own names, so what comes
	 * back is keyed by string exactly as what went in was.
	 *
	 * @param array<string, mixed> $schema Schema array.
	 * @return array<string, mixed> Modified schema array.
	 */
	protected function add_additional_fields_schema( $schema ) {
	}
}

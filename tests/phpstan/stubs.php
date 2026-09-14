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

/**
 * Retrieves a URL within the plugins or mu-plugins directory.
 *
 * Core documents the return as a bare `string`, while wp_register_script()
 * accepts nothing but a non-empty one as `$src`. A path is appended to the
 * plugins URL after a slash, so with one given the result cannot be empty;
 * without one, core's `string` stands.
 *
 * @param string $path   Optional. Extra path appended to the end of the URL, including
 *                       the relative directory if $plugin is supplied. Default empty.
 * @param string $plugin Optional. A full path to a file inside a plugin or mu-plugin.
 *                       The URL will be relative to its directory. Default empty.
 *                       Typically this is done by passing `__FILE__` as the argument.
 * @return string Plugins URL link with optional paths appended.
 *
 * @phpstan-return ($path is non-empty-string ? non-empty-string : string)
 */
function plugins_url( $path = '', $plugin = '' ) {
}

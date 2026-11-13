<?php
/**
 * Cleaners and small generators shared by every integration.
 *
 * @package GetPageSpeedCacheConfiguration
 */

namespace GetPageSpeedCacheConfiguration;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Turns untrusted option values into safe Nginx configuration fragments.
 */
final class Nginx_Syntax {

	/**
	 * Keep only valid URI query parameter names.
	 *
	 * @param mixed $values Candidate names.
	 * @return array
	 */
	public static function parameter_names( $values ) {
		$clean  = array();
		$values = is_array( $values ) ? $values : array();

		foreach ( $values as $value ) {
			$value = is_scalar( $value ) ? trim( (string) $value ) : '';
			if ( preg_match( '/^[A-Za-z0-9_.~-]+$/D', $value ) ) {
				$clean[] = $value;
			}
		}

		return array_values( array_unique( $clean ) );
	}

	/**
	 * Keep regex fragments that are safe inside an Nginx quoted regex alternation.
	 *
	 * A fragment must not be able to end the quoted string or the directive, and
	 * must be a complete pattern on its own: an unbalanced or dangling fragment
	 * would either stop Nginx from loading the file or swallow its neighbors.
	 *
	 * @param mixed $values Candidate fragments.
	 * @return array
	 */
	public static function regex_fragments( $values ) {
		$clean  = array();
		$values = is_array( $values ) ? $values : array();

		foreach ( $values as $value ) {
			$value = is_scalar( $value ) ? trim( (string) $value ) : '';
			if ( '' === $value || preg_match( '/[\x00-\x1f\x7f";{}]/', $value ) ) {
				continue;
			}

			// Nginx reads a doubled backslash in a quoted string as one, so validate what it will compile.
			$compiled = str_replace( '\\\\', '\\', $value );
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- an invalid pattern is the expected rejection path.
			if ( false !== @preg_match( "\x01" . $compiled . "\x01", '' ) ) {
				$clean[] = $value;
			}
		}

		return array_values( array_unique( $clean ) );
	}

	/**
	 * Strip control and quote characters from a quoted Nginx value.
	 *
	 * @param string $value Candidate value.
	 * @return string
	 */
	public static function value( $value ) {
		return trim( str_replace( array( "\r", "\n", '"' ), '', (string) $value ) );
	}

	/**
	 * Validate an Nginx expiration duration.
	 *
	 * @param mixed $value Candidate duration.
	 * @return string
	 */
	public static function expiration( $value ) {
		$value = is_scalar( $value ) ? trim( (string) $value ) : '';
		return preg_match( '/^(?:-1|max|off|[0-9]+[smhdwMy])$/D', $value ) ? $value : '90d';
	}

	/**
	 * Generate statements that copy $args and remove non-content-changing parameters.
	 *
	 * @param string $variable   Nginx variable name without the dollar sign.
	 * @param array  $parameters Clean parameter names.
	 * @return string
	 */
	public static function strip_query_parameters( $variable, $parameters ) {
		$result = 'set $' . $variable . " \$args;\n";

		foreach ( $parameters as $parameter ) {
			$result .= 'if ($' . $variable . ' ~ (.*)(?:&|^)' . preg_quote( $parameter, '/' ) . '=[^&]*(.*)) { ';
			$result .= 'set $' . $variable . ' $1$2; }' . "\n";
		}

		$result .= "\n# Normalize separators left after removing tracking parameters.\n";
		$result .= 'if ($' . $variable . ' ~ ^&(.*)) { set $' . $variable . ' $1; }' . "\n";
		$result .= 'if ($' . $variable . ' ~ (.*)&$) { set $' . $variable . ' $1; }' . "\n";
		$result .= 'if ($' . $variable . ' ~ (.*)&&+(.*)) { set $' . $variable . ' $1&$2; }';

		return $result;
	}
}

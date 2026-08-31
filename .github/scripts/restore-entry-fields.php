<?php
/**
 * One-off recovery: restore directory entry fields from a pre-damage export.
 *
 * On 2026-08-31 the sync's update path merged the current entry with its new values using
 * array_merge(). PHP casts integer-like field IDs ('1', '3', '5') to integer keys and
 * array_merge renumbers integer keys from zero, so every value was written into a different
 * field: the blob URL landed in field 1, the description in field 4, the install method in
 * field 5, the Ecosystem JSON in field 6. All 57 entries were affected.
 *
 * This script writes the pre-damage values back, entry by entry, from a JSON map exported
 * before the damage. It is deliberately separate from the sync, and both it and its data file
 * should be deleted once recovery is confirmed.
 *
 * Usage:
 *   php .github/scripts/restore-entry-fields.php [--dry-run]
 *
 * Environment: the same GF_WEBHOOK_URL, GF_CONSUMER_KEY and GF_CONSUMER_SECRET the sync uses.
 *
 * Exit codes: 0 = every entry restored, 1 = at least one failed, 2 = unusable environment.
 */

declare( strict_types=1 );

require_once __DIR__ . '/sync-directory.php';

const RESTORE_DATA = __DIR__ . '/restore-entry-fields.json';

exit( restore_main( $argv ) );

/**
 * Restores every entry in the data file.
 *
 * @param array $argv CLI arguments.
 *
 * @return int Process exit code.
 */
function restore_main( array $argv ): int {
	$dry_run = in_array( '--dry-run', $argv, true );

	$config = read_config();
	if ( is_string( $config ) ) {
		fwrite( STDERR, $config . "\n" );

		return 2;
	}

	$raw = file_get_contents( RESTORE_DATA );
	if ( ! is_string( $raw ) ) {
		fwrite( STDERR, "The restore data file could not be read.\n" );

		return 2;
	}

	$entries = json_decode( $raw, true );
	if ( ! is_array( $entries ) || [] === $entries ) {
		fwrite( STDERR, "The restore data file is not a usable JSON object.\n" );

		return 2;
	}

	$restored = 0;
	$failed   = [];

	foreach ( $entries as $entry_id => $values ) {
		if ( ! ctype_digit( (string) $entry_id ) || ! is_array( $values ) ) {
			$failed[ (string) $entry_id ] = 'Malformed record in the restore data.';
			continue;
		}

		$result = restore_one( $config, (int) $entry_id, $values, $dry_run );
		if ( is_string( $result ) ) {
			$failed[ (string) $entry_id ] = $result;
			printf( "FAIL     %s\n           %s\n", $entry_id, $result );
			continue;
		}

		++$restored;
		printf( "RESTORED %s  %s\n", $entry_id, mb_substr( (string) ( $values['1'] ?? '' ), 0, 60 ) );
	}

	printf( "\n%d entr(ies), %d restored, %d failed.\n", count( $entries ), $restored, count( $failed ) );

	return [] === $failed ? 0 : 1;
}

/**
 * Reads one entry, overlays the saved field values and writes it back.
 *
 * @param array $config   Config.
 * @param int   $entry_id Entry ID.
 * @param array $values   Field values keyed by field ID.
 * @param bool  $dry_run  Whether to skip the write.
 *
 * @return true|string True, or an error message.
 */
function restore_one( array $config, int $entry_id, array $values, bool $dry_run ) {
	$url     = sprintf( '%s/entries/%d', $config['api_base'], $entry_id );
	$current = api_request( $config, 'GET', $url );
	if ( is_string( $current ) ) {
		return 'The entry could not be read: ' . $current;
	}

	// Key by key, never array_merge: that is the bug being recovered from here.
	$merged = $current;
	foreach ( $values as $field_id => $value ) {
		if ( ! ctype_digit( (string) $field_id ) ) {
			continue;
		}
		$merged[ (string) $field_id ] = $value;
	}

	if ( $dry_run ) {
		printf( "DRY-RUN  %d: would restore fields %s\n", $entry_id, implode( ', ', array_keys( $values ) ) );

		return true;
	}

	$response = api_request( $config, 'PUT', $url, $merged );

	return is_string( $response ) ? 'The write failed: ' . $response : true;
}

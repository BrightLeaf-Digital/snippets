<?php
/**
 * Code Snippets Directory sync.
 *
 * Reads a git name-status change list on stdin and upserts the matching entries in the
 * Code Snippets Directory (BLD Gravity Form 2). One entry per published snippet file:
 * a file the directory has not seen is created, one it has is updated in place, and a
 * renamed file updates the entry the old filename owned rather than creating a second one.
 *
 * Field ownership:
 *   1  Snippet Title       - the filename without its extension
 *   3  Snippet Description - HTML rendered from the header docblock
 *   4  GitHub Raw URL      - branch-tracking raw URL
 *   5  GitHub Blob URL     - commit-pinned blob URL
 *   7  Install With        - the @install tag, or the extension's default
 *   9  Ecosystem           - Tagify JSON built from the docblock's ecosystem flags
 *   6  Screenshots         - never touched; uploaded by hand
 *   10 Directory URL       - never touched; filled by the downstream directory process
 *
 * A file whose docblock carries @nopublish is skipped entirely: it stays in the repository
 * without a directory entry, and the sync will not recreate one that was removed by hand.
 *
 * An updated snippet also posts its commit message to the subscriber notification form, so
 * subscribers are told what changed. A commit message containing [skip notify] suppresses it.
 *
 * Usage:
 *   git diff -M --name-status --diff-filter=AMR "$BEFORE".."$SHA" \
 *     | php .github/scripts/sync-directory.php
 *
 * Environment:
 *   GF_WEBHOOK_URL       the form's submissions endpoint; the API base and form ID are
 *                        derived from it
 *   GF_CONSUMER_KEY      Gravity Forms REST API v2 key
 *   GF_CONSUMER_SECRET   Gravity Forms REST API v2 secret
 *   GITHUB_REPO          owner/name
 *   GITHUB_BRANCH        branch for the raw URL
 *   GITHUB_SHA           commit for the blob URL
 *   GF_UPDATE_WEBHOOK_URL  optional; the subscriber notification form's submissions endpoint.
 *                        Unset means no notifications are sent.
 *   SYNC_RANGE           optional; the pushed revision range, used to find the commit message
 *                        that actually touched each file. Unset means no notifications.
 *   GF_SKIP_NOTIFY       when 1, sync entries but send no notifications
 *   GF_DRY_RUN           when 1, print what would be sent and make no write calls
 *
 * Exit codes: 0 = every file synced, 1 = at least one file failed, 2 = the environment or
 * arguments were unusable. Every file is attempted; one failure never skips the rest.
 */

declare( strict_types=1 );

require_once __DIR__ . '/parse-snippet-doc.php';

/**
 * Field IDs this script writes.
 */
const FIELD_TITLE       = '1';
const FIELD_DESCRIPTION = '3';
const FIELD_RAW_URL     = '4';
const FIELD_BLOB_URL    = '5';
const FIELD_INSTALL     = '7';
const FIELD_ECOSYSTEM   = '9';

/**
 * Fallback tag colour, matching the Entry Tags add-on's own fallback.
 */
const TAG_FALLBACK_COLOR = '#808080';

/**
 * Tag text colours, matching EntryTagField::LIGHT_TEXT_COLOR and DARK_TEXT_COLOR.
 */
const TAG_LIGHT_TEXT_COLOR = '#fff';
const TAG_DARK_TEXT_COLOR  = '#101517';

// Only run as a command, so the functions below can be exercised in isolation.
if ( isset( $argv[0] ) && realpath( $argv[0] ) === realpath( __FILE__ ) ) {
	exit( sync_main() );
}

/**
 * Runs the sync.
 *
 * @return int Process exit code.
 */
function sync_main(): int {
	$config = read_config();
	if ( is_string( $config ) ) {
		fwrite( STDERR, $config . "\n" );

		return 2;
	}

	$changes = read_changes( (string) file_get_contents( 'php://stdin' ) );
	if ( [] === $changes ) {
		echo "No published snippet files changed.\n";

		return 0;
	}

	$choices = fetch_tag_choices( $config );
	if ( is_string( $choices ) ) {
		fwrite( STDERR, $choices . "\n" );

		return 2;
	}

	$failures = [];
	$warnings = [];
	$skipped  = 0;

	foreach ( $changes as $change ) {
		$label = $change['path'];

		$result = sync_one( $change, $config, $choices );
		if ( is_string( $result ) ) {
			$failures[ $label ] = $result;
			printf( "FAIL  %s\n        %s\n", $label, str_replace( "\n", "\n        ", $result ) );
			continue;
		}

		if ( 'SKIP' === $result['action'] ) {
			++$skipped;
			printf( "SKIP  %s (@nopublish)\n", $label );
			continue;
		}

		printf( "%s  %s (entry %s)\n", $result['action'], $label, $result['entry_id'] );

		// Subscribers are told what changed in an existing snippet, never about a new one:
		// a brand-new entry has no subscribers yet, and the old create workflow never sent one.
		if ( 'UPDATE' !== $result['action'] ) {
			continue;
		}

		$notified = notify_subscribers( $config, $result['title'], $change['path'] );
		if ( is_string( $notified ) ) {
			$warnings[ $label ] = $notified;
			printf( "WARN  %s\n        %s\n", $label, $notified );
			continue;
		}

		if ( true === $notified ) {
			printf( "NOTIFY  %s\n", $label );
		}
	}

	printf(
		"\n%d file(s), %d synced, %d skipped, %d failed, %d notification warning(s).\n",
		count( $changes ),
		count( $changes ) - count( $failures ) - $skipped,
		$skipped,
		count( $failures ),
		count( $warnings )
	);

	if ( [] !== $failures ) {
		fwrite( STDERR, "\nFailed files:\n" );
		foreach ( $failures as $path => $reason ) {
			fwrite( STDERR, sprintf( "  %s: %s\n", $path, str_replace( "\n", '; ', $reason ) ) );
		}
	}

	if ( [] !== $warnings ) {
		// The entry itself is correct, so this is not a sync failure - but a change nobody was
		// told about should not pass silently either.
		fwrite( STDERR, "\nSynced, but the update notification did not send:\n" );
		foreach ( $warnings as $path => $reason ) {
			fwrite( STDERR, sprintf( "  %s: %s\n", $path, $reason ) );
		}
	}

	return [] === $failures && [] === $warnings ? 0 : 1;
}

/**
 * Tells the subscriber notification form what changed in a snippet.
 *
 * The message is the commit message of the commit that actually touched this file inside the
 * pushed range - not the push's tip commit, which in a multi-commit push is whatever landed
 * last and usually describes a different file entirely.
 *
 * @param array  $config Config.
 * @param string $title  Entry title.
 * @param string $path   Repository-relative path.
 *
 * @return bool|string True when sent, false when deliberately skipped, or an error message.
 */
function notify_subscribers( array $config, string $title, string $path ) {
	if ( '' === $config['update_webhook'] ) {
		return false;
	}

	if ( $config['skip_notify'] ) {
		printf( "SKIP-NOTIFY  %s (GF_SKIP_NOTIFY is set)\n", $path );

		return false;
	}

	if ( '' === $config['range'] ) {
		// A manual dispatch is a retry of the sync, not news about a change.
		return false;
	}

	$message = commit_message_for( $config['range'], $path );
	if ( '' === $message ) {
		return 'No commit message was found for this file in the pushed range.';
	}

	$marker = suppression_marker( $message );
	if ( null !== $marker ) {
		printf( "SKIP-NOTIFY  %s (%s in the commit message)\n", $path, $marker );

		return false;
	}

	if ( $config['dry_run'] ) {
		printf( "DRY-RUN notify: %s | %s\n", $title, str_replace( "\n", ' ', $message ) );

		return true;
	}

	$response = api_request(
		$config,
		'POST',
		$config['update_webhook'],
		[
			'input_1' => $title,
			'input_3' => $message,
		],
		false
	);

	return is_string( $response ) ? 'The notification submission failed: ' . $response : true;
}

/**
 * Returns the suppression marker found in a commit message, or null when there is none.
 *
 * Lets a sweep that rewrites many files - a formatting or docblock pass - avoid sending one
 * notification per file for a change that carries no news for subscribers.
 *
 * @param string $message Commit message.
 *
 * @return string|null
 */
function suppression_marker( string $message ): ?string {
	foreach ( [ '[skip notify]', '[no notify]' ] as $marker ) {
		if ( false !== stripos( $message, $marker ) ) {
			return $marker;
		}
	}

	return null;
}

/**
 * Returns the message of the last commit in the range that touched the given path.
 *
 * @param string $range Git revision range, e.g. "base..head".
 * @param string $path  Repository-relative path.
 *
 * @return string Commit message, or an empty string when none was found.
 */
function commit_message_for( string $range, string $path ): string {
	$command = sprintf(
		'git log -1 --format=%%B %s -- %s 2>/dev/null',
		escapeshellarg( $range ),
		escapeshellarg( $path )
	);

	return trim( (string) shell_exec( $command ) );
}

/**
 * Reads and validates the environment.
 *
 * @return array|string The config, or an error message.
 */
function read_config() {
	$webhook = (string) getenv( 'GF_WEBHOOK_URL' );
	if ( '' === $webhook ) {
		return 'GF_WEBHOOK_URL is not set.';
	}

	// The submissions endpoint carries both the API base and the form ID.
	if ( 1 !== preg_match( '#^(https://.+/gf/v2)/forms/(\d+)/submissions/?$#', $webhook, $matches ) ) {
		return 'GF_WEBHOOK_URL must be an https Gravity Forms v2 submissions endpoint, '
			. 'e.g. https://example.com/wp-json/gf/v2/forms/2/submissions';
	}

	$config = [
		'api_base'    => $matches[1],
		'form_id'     => $matches[2],
		'submissions' => rtrim( $webhook, '/' ),
		'key'         => (string) getenv( 'GF_CONSUMER_KEY' ),
		'secret'      => (string) getenv( 'GF_CONSUMER_SECRET' ),
		'repo'        => (string) getenv( 'GITHUB_REPO' ),
		'branch'      => (string) getenv( 'GITHUB_BRANCH' ),
		'sha'         => (string) getenv( 'GITHUB_SHA' ),
		'dry_run'     => '1' === (string) getenv( 'GF_DRY_RUN' ),
		// Optional: without an update webhook and a range the sync sends no notifications.
		'update_webhook' => (string) getenv( 'GF_UPDATE_WEBHOOK_URL' ),
		'range'          => (string) getenv( 'SYNC_RANGE' ),
		'skip_notify'    => '1' === (string) getenv( 'GF_SKIP_NOTIFY' ),
	];

	if ( '' !== $config['update_webhook'] && ! str_starts_with( $config['update_webhook'], 'https://' ) ) {
		return 'GF_UPDATE_WEBHOOK_URL must be an https URL.';
	}

	foreach ( [ 'key', 'secret', 'repo', 'branch', 'sha' ] as $required ) {
		if ( '' === $config[ $required ] ) {
			return sprintf( 'Missing required environment value for "%s".', $required );
		}
	}

	return $config;
}

/**
 * Parses git name-status lines into change records.
 *
 * Accepts A, M and R lines and keeps only publishable snippet paths, matching the same
 * filter the parser's own dry run uses: .php or .js, no dotfiles, nothing under .github.
 *
 * @param string $input Raw stdin.
 *
 * @return array<int, array{status: string, path: string, previous: string|null}>
 */
function read_changes( string $input ): array {
	$changes = [];
	$seen    = [];
	$lines   = 0;

	foreach ( preg_split( '/\R/', $input ) ?: [] as $line ) {
		if ( '' === trim( $line ) ) {
			continue;
		}

		++$lines;
		$parts  = explode( "\t", $line );
		$status = strtoupper( substr( trim( (string) array_shift( $parts ) ), 0, 1 ) );

		if ( ! in_array( $status, [ 'A', 'M', 'R' ], true ) || [] === $parts ) {
			continue;
		}

		// A rename line carries the old path first, then the new one.
		$previous = 'R' === $status && count( $parts ) > 1 ? unquote_git_path( (string) array_shift( $parts ) ) : null;
		$path     = unquote_git_path( (string) array_shift( $parts ) );

		if ( ! is_publishable_path( $path ) || isset( $seen[ $path ] ) ) {
			continue;
		}

		$seen[ $path ] = true;
		$changes[]     = [
			'status'   => $status,
			'path'     => $path,
			'previous' => null !== $previous && is_publishable_path( $previous ) ? $previous : null,
		];
	}

	// Surfacing the count makes a silently dropped path visible against the change list the
	// workflow prints. A quoted filename went missing here once without a word.
	printf( "%d change line(s), %d publishable snippet(s).\n", $lines, count( $changes ) );

	return $changes;
}

/**
 * Decodes a path as git writes it in --name-status output.
 *
 * Git wraps a path in double quotes and C-escapes it whenever it contains a quote, a backslash
 * or a control character. core.quotepath=false only suppresses that for non-ASCII bytes, so a
 * filename such as Create "user role" merge tag.php still arrives quoted and escaped.
 *
 * @param string $path Raw path field.
 *
 * @return string
 */
function unquote_git_path( string $path ): string {
	$path = trim( $path );

	if ( strlen( $path ) < 2 || ! str_starts_with( $path, '"' ) || ! str_ends_with( $path, '"' ) ) {
		return $path;
	}

	$body   = substr( $path, 1, -1 );
	$out    = '';
	$length = strlen( $body );

	for ( $i = 0; $i < $length; $i++ ) {
		if ( '\\' !== $body[ $i ] || $i + 1 >= $length ) {
			$out .= $body[ $i ];
			continue;
		}

		$next = $body[ ++$i ];

		// An octal escape carries one raw byte of a multi-byte character.
		if ( ctype_digit( $next ) && $i + 2 < $length ) {
			$octal = $next . $body[ $i + 1 ] . $body[ $i + 2 ];
			if ( 1 === preg_match( '/^[0-7]{3}$/', $octal ) ) {
				$out .= chr( (int) octdec( $octal ) );
				$i   += 2;
				continue;
			}
		}

		$out .= [
			'n'  => "\n",
			't'  => "\t",
			'r'  => "\r",
			'"'  => '"',
			'\\' => '\\',
		][ $next ] ?? $next;
	}

	return $out;
}

/**
 * Returns true when the path is a publishable snippet.
 *
 * @param string $path Repository-relative path.
 *
 * @return bool
 */
function is_publishable_path( string $path ): bool {
	if ( '' === $path || str_starts_with( $path, '/' ) ) {
		return false;
	}

	foreach ( explode( '/', $path ) as $segment ) {
		if ( '' === $segment || str_starts_with( $segment, '.' ) || '..' === $segment ) {
			return false;
		}
	}

	$extension = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );

	return 'php' === $extension || 'js' === $extension;
}

/**
 * Fetches the Ecosystem field's configured choices, including their colours.
 *
 * Read at run time rather than hard-coded so a colour changed in the form editor is picked
 * up on the next sync instead of drifting out of step with the live field.
 *
 * @param array $config Config.
 *
 * @return array|string Choice records keyed by value, or an error message.
 */
function fetch_tag_choices( array $config ) {
	$response = api_request( $config, 'GET', sprintf( '%s/forms/%s', $config['api_base'], $config['form_id'] ) );
	if ( is_string( $response ) ) {
		return 'The form could not be read: ' . $response;
	}

	$fields = is_array( $response['fields'] ?? null ) ? $response['fields'] : [];
	foreach ( $fields as $field ) {
		if ( (string) ( $field['id'] ?? '' ) !== FIELD_ECOSYSTEM ) {
			continue;
		}

		$choices = [];
		foreach ( is_array( $field['choices'] ?? null ) ? $field['choices'] : [] as $choice ) {
			$value = (string) ( $choice['value'] ?? '' );
			if ( '' === $value ) {
				continue;
			}

			$choices[ $value ] = [
				'label'    => (string) ( $choice['text'] ?? $value ),
				'value'    => $value,
				'color'    => (string) ( $choice['color'] ?? '' ),
				'selected' => (bool) ( $choice['isSelected'] ?? false ),
			];
		}

		if ( [] === $choices ) {
			return sprintf( 'Field %s has no configured choices.', FIELD_ECOSYSTEM );
		}

		return $choices;
	}

	return sprintf( 'Field %s was not found on form %s.', FIELD_ECOSYSTEM, $config['form_id'] );
}

/**
 * Syncs one changed file.
 *
 * @param array $change  Change record.
 * @param array $config  Config.
 * @param array $choices Ecosystem choice records.
 *
 * @return array{action: string, entry_id: string}|string Result, or an error message.
 */
function sync_one( array $change, array $config, array $choices ) {
	$parsed = parse_snippet( $change['path'] );
	if ( [] !== $parsed['errors'] ) {
		return implode( "\n", $parsed['errors'] );
	}

	$data = $parsed['data'];

	if ( false === ( $data['publish'] ?? true ) ) {
		return [
			'action'   => 'SKIP',
			'entry_id' => '-',
			'title'    => title_from_path( $change['path'] ),
		];
	}

	$title  = title_from_path( $change['path'] );
	$values = [
		FIELD_TITLE       => $title,
		FIELD_DESCRIPTION => $data['description'],
		FIELD_RAW_URL     => raw_url( $config, $change['path'] ),
		FIELD_BLOB_URL    => blob_url( $config, $change['path'] ),
		FIELD_INSTALL     => $data['install_with'],
	];

	$encoded = encode_entry_tags( $data['ecosystems'], $choices );
	if ( is_string( $encoded ) ) {
		return $encoded;
	}

	$tags = $encoded['json'];

	// A rename keeps the entry the old filename owned.
	$lookup   = null !== $change['previous'] ? title_from_path( $change['previous'] ) : $title;
	$entry_id = find_entry_id( $config, $lookup );
	if ( is_string( $entry_id ) ) {
		return $entry_id;
	}

	// Fall back to the new title when a rename's old entry cannot be found, so the file is
	// matched rather than duplicated if the rename was done in two steps.
	if ( null === $entry_id && $lookup !== $title ) {
		$entry_id = find_entry_id( $config, $title );
		if ( is_string( $entry_id ) ) {
			return $entry_id;
		}
	}

	if ( null === $entry_id ) {
		$created = create_entry( $config, $values );
		if ( is_string( $created ) ) {
			return $created;
		}

		$entry_id = $created;
		$action   = 'CREATE';
		// The submission cannot carry the Ecosystem value, so it is written in the update
		// below: the Entry Tags field stores Tagify JSON, and form submission processing
		// would flatten it into a plain value list that renders as nothing.
		$values = [ FIELD_ECOSYSTEM => $tags ];
	} else {
		$action                    = 'UPDATE';
		$values[ FIELD_ECOSYSTEM ] = $tags;
	}

	$updated = update_entry( $config, $entry_id, $values );
	if ( is_string( $updated ) ) {
		return $updated;
	}

	return [
		'action'   => $action,
		'entry_id' => (string) $entry_id,
		'title'    => $title,
	];
}

/**
 * Returns the entry title for a path: the filename without its extension.
 *
 * @param string $path Repository-relative path.
 *
 * @return string
 */
function title_from_path( string $path ): string {
	$name = basename( $path );
	$dot  = strrpos( $name, '.' );

	return false === $dot ? $name : substr( $name, 0, $dot );
}

/**
 * Builds the branch-tracking raw URL.
 *
 * @param array  $config Config.
 * @param string $path   Repository-relative path.
 *
 * @return string
 */
function raw_url( array $config, string $path ): string {
	return sprintf(
		'https://raw.githubusercontent.com/%s/%s/%s',
		$config['repo'],
		rawurlencode( $config['branch'] ),
		encode_path( $path )
	);
}

/**
 * Builds the commit-pinned blob URL.
 *
 * @param array  $config Config.
 * @param string $path   Repository-relative path.
 *
 * @return string
 */
function blob_url( array $config, string $path ): string {
	return sprintf(
		'https://github.com/%s/blob/%s/%s',
		$config['repo'],
		$config['sha'],
		encode_path( $path )
	);
}

/**
 * URL-encodes a path, leaving its separators intact.
 *
 * @param string $path Repository-relative path.
 *
 * @return string
 */
function encode_path( string $path ): string {
	return implode( '/', array_map( 'rawurlencode', explode( '/', $path ) ) );
}

/**
 * Builds the Tagify JSON the Entry Tags field stores.
 *
 * Mirrors the shape produced by the add-on's own field input and by
 * GravityOps\AIFeed\Runtime\Fields\Adapters\EntryTagsFieldAdapter::encode_for_storage(): a
 * plain value list is not enough, because the display path reads label/value/colour off each
 * tag object and silently renders nothing for a bare string.
 *
 * @param array<int, string> $values  Ecosystem choice values from the docblock.
 * @param array              $choices Configured choice records keyed by value.
 *
 * @return array{json: string}|string The encoded tags, or an error message. Success is wrapped
 *                                     in an array so the caller can tell it apart from an error
 *                                     with is_string(); returning the JSON bare made every
 *                                     success look like a failure.
 */
function encode_entry_tags( array $values, array $choices ) {
	$unknown = array_values( array_diff( $values, array_keys( $choices ) ) );
	if ( [] !== $unknown ) {
		return sprintf(
			'Ecosystem value(s) not configured on field %s: %s. Configured: %s.',
			FIELD_ECOSYSTEM,
			implode( ', ', $unknown ),
			implode( ', ', array_keys( $choices ) )
		);
	}

	$tags = [];

	// Emitted in the field's own choice order, which is how the add-on writes them.
	foreach ( $choices as $choice ) {
		if ( ! in_array( $choice['value'], $values, true ) ) {
			continue;
		}

		$color      = normalize_tag_color( $choice['color'] );
		$text_color = tag_text_color( $color );
		$tags[]     = [
			'label'      => $choice['label'],
			'value'      => $choice['value'],
			'color'      => $color,
			'text_color' => $text_color,
			'selected'   => $choice['selected'],
			'style'      => '--tag-bg:' . $color . '; --tag-text-color:' . $text_color
				. '; --tag-remove-btn-color: ' . $text_color . ';',
		];
	}

	$encoded = json_encode( $tags, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

	return is_string( $encoded ) ? [ 'json' => $encoded ] : 'The Ecosystem tags could not be JSON encoded.';
}

/**
 * Keeps a valid configured hex colour and falls back to the add-on's own default.
 *
 * @param string $color Configured choice colour.
 *
 * @return string
 */
function normalize_tag_color( string $color ): string {
	$color = trim( $color );

	return 1 === preg_match( '/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $color ) ? $color : TAG_FALLBACK_COLOR;
}

/**
 * Mirrors the add-on's brightness calculation for the tag's text colour.
 *
 * @param string $color Normalized hex colour.
 *
 * @return string
 */
function tag_text_color( string $color ): string {
	if ( 4 === strlen( $color ) ) {
		$color = '#' . $color[1] . $color[1] . $color[2] . $color[2] . $color[3] . $color[3];
	}

	$brightness = ( 0.299 * (float) hexdec( substr( $color, 1, 2 ) ) )
		+ ( 0.587 * (float) hexdec( substr( $color, 3, 2 ) ) )
		+ ( 0.114 * (float) hexdec( substr( $color, 5, 2 ) ) );

	return $brightness > 128 ? TAG_DARK_TEXT_COLOR : TAG_LIGHT_TEXT_COLOR;
}

/**
 * Finds the entry ID owning a title.
 *
 * @param array  $config Config.
 * @param string $title  Entry title.
 *
 * @return int|null|string Entry ID, null when the directory has no entry for the title, or an
 *                         error message. An int result is a hit and a string result is always
 *                         a failure, so the two can never be confused.
 */
function find_entry_id( array $config, string $title ) {
	$query = http_build_query(
		[
			'search'  => (string) json_encode(
				[
					'field_filters' => [
						[
							'key'      => FIELD_TITLE,
							'operator' => 'is',
							'value'    => $title,
						],
					],
				]
			),
			'paging'  => [
				'page_size' => 2,
			],
			'sorting' => [
				'key'       => 'id',
				'direction' => 'ASC',
			],
		]
	);

	$url      = sprintf( '%s/forms/%s/entries?%s', $config['api_base'], $config['form_id'], $query );
	$response = api_request( $config, 'GET', $url );
	if ( is_string( $response ) ) {
		return 'The entry lookup failed: ' . $response;
	}

	$entries = is_array( $response['entries'] ?? null ) ? $response['entries'] : [];
	if ( [] === $entries ) {
		return null;
	}

	if ( count( $entries ) > 1 ) {
		return sprintf(
			'More than one entry has the title "%s". Resolve the duplicate before syncing.',
			$title
		);
	}

	$id = (string) ( $entries[0]['id'] ?? '' );

	return ctype_digit( $id ) ? (int) $id : 'The entry lookup returned an entry with no usable ID.';
}

/**
 * Creates an entry through the form's submissions endpoint.
 *
 * The submissions endpoint is used rather than a direct entry write so notifications and any
 * downstream directory processing keyed on form submission still run.
 *
 * @param array $config Config.
 * @param array $values Field values keyed by field ID.
 *
 * @return int|string Entry ID, or an error message.
 */
function create_entry( array $config, array $values ) {
	$payload = [];
	foreach ( $values as $field_id => $value ) {
		$payload[ 'input_' . $field_id ] = $value;
	}

	if ( $config['dry_run'] ) {
		printf( "DRY-RUN create: %s\n", (string) json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );

		return 0;
	}

	$response = api_request( $config, 'POST', $config['submissions'], $payload );
	if ( is_string( $response ) ) {
		return 'The submission failed: ' . $response;
	}

	if ( false === ( $response['is_valid'] ?? true ) ) {
		return 'The submission was rejected: ' . (string) json_encode( $response['validation_messages'] ?? [] );
	}

	$entry_id = (string) ( $response['entry_id'] ?? '' );
	if ( ctype_digit( $entry_id ) ) {
		return (int) $entry_id;
	}

	// Older responses omit entry_id; fall back to looking the new entry up by title.
	$found = find_entry_id( $config, (string) $values[ FIELD_TITLE ] );
	if ( is_int( $found ) ) {
		return $found;
	}

	return is_string( $found ) ? $found : 'The submission succeeded but its entry could not be found.';
}

/**
 * Updates an entry, preserving every field this script does not own.
 *
 * Gravity Forms' entry endpoint replaces the entry rather than merging into it, so the
 * current entry is read and merged before it is written back. Without that, fields 6 and 10 -
 * hand-uploaded screenshots and the downstream directory URL - would be wiped on every sync.
 *
 * @param array $config   Config.
 * @param int   $entry_id Entry ID.
 * @param array $values   Field values keyed by field ID.
 *
 * @return true|string True, or an error message.
 */
function update_entry( array $config, int $entry_id, array $values ) {
	$url = sprintf( '%s/entries/%d', $config['api_base'], $entry_id );

	// The read happens even on a dry run: it is the step that proves the credentials can reach
	// the entry endpoint at all, which is the part a dry run most needs to tell you.
	$current = api_request( $config, 'GET', $url );
	if ( is_string( $current ) ) {
		return 'The entry could not be read before updating: ' . $current;
	}

	if ( $config['dry_run'] ) {
		printf(
			"DRY-RUN update entry %d: %s\n",
			$entry_id,
			(string) json_encode( $values, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE )
		);

		return true;
	}

	// NOT array_merge(): PHP casts the integer-like field IDs ('1', '3', '5') to integer keys,
	// and array_merge renumbers numeric keys from zero. That silently rewrites every field
	// value into a different field - the title into field 5, the description into field 4 -
	// and GF stores the result. Assign key by key so the field IDs survive.
	$merged = $current;
	foreach ( $values as $field_id => $value ) {
		$merged[ $field_id ] = $value;
	}

	$response = api_request( $config, 'PUT', $url, $merged );

	return is_string( $response ) ? 'The entry update failed: ' . $response : true;
}

/**
 * Performs one Gravity Forms REST API v2 request.
 *
 * @param array      $config       Config.
 * @param string     $method       HTTP method.
 * @param string     $url          Absolute URL.
 * @param array|null $body         Optional JSON body.
 * @param bool       $authenticate Whether to send the REST API credentials. The subscriber
 *                                 notification form is a public submissions endpoint and was
 *                                 always posted to unauthenticated.
 *
 * @return array|string Decoded response, or an error message.
 */
function api_request( array $config, string $method, string $url, ?array $body = null, bool $authenticate = true ) {
	$handle = curl_init();
	if ( false === $handle ) {
		return 'A cURL handle could not be created.';
	}

	$options = [
		CURLOPT_URL            => $url,
		CURLOPT_CUSTOMREQUEST  => $method,
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_TIMEOUT        => 60,
		CURLOPT_HTTPHEADER     => [ 'Accept: application/json' ],
	];

	if ( $authenticate ) {
		$options[ CURLOPT_USERPWD ]  = $config['key'] . ':' . $config['secret'];
		$options[ CURLOPT_HTTPAUTH ] = CURLAUTH_BASIC;
	}

	if ( null !== $body ) {
		$encoded = json_encode( $body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( ! is_string( $encoded ) ) {
			curl_close( $handle );

			return 'The request body could not be JSON encoded.';
		}

		$options[ CURLOPT_POSTFIELDS ] = $encoded;
		$options[ CURLOPT_HTTPHEADER ] = [ 'Accept: application/json', 'Content-Type: application/json' ];
	}

	curl_setopt_array( $handle, $options );

	$raw    = curl_exec( $handle );
	$status = (int) curl_getinfo( $handle, CURLINFO_RESPONSE_CODE );
	$error  = curl_error( $handle );
	curl_close( $handle );

	if ( false === $raw ) {
		return sprintf( '%s %s failed: %s', $method, redact( $url ), '' === $error ? 'unknown transport error' : $error );
	}

	$decoded = decode_json_body( (string) $raw );

	if ( $status < 200 || $status >= 300 ) {
		return sprintf(
			'%s %s returned HTTP %d: %s',
			$method,
			redact( $url ),
			$status,
			substr( (string) $raw, 0, 500 )
		);
	}

	if ( ! is_array( $decoded ) ) {
		return sprintf( '%s %s returned a non-JSON body: %s', $method, redact( $url ), substr( (string) $raw, 0, 200 ) );
	}

	return $decoded;
}

/**
 * Decodes a JSON response body, tolerating output echoed ahead of it.
 *
 * A plugin or snippet that echoes during the request - a stray debug print, a notice - lands
 * ahead of the JSON in the response body. The write has already happened by then, so treating
 * the whole response as unreadable reports a completed write as a failure. Live snippet 21 on
 * the directory site did exactly this, echoing <script>console.log(...)</script> from
 * gform_after_update_entry, and turned 57 successful writes into 57 reported failures.
 *
 * @param string $raw Raw response body.
 *
 * @return array|null Decoded array, or null when there is no JSON in the body.
 */
function decode_json_body( string $raw ) {
	$decoded = json_decode( $raw, true );
	if ( is_array( $decoded ) ) {
		return $decoded;
	}

	// Retry from the first plausible JSON opener.
	$start = strcspn( $raw, '{[' );
	if ( $start >= strlen( $raw ) ) {
		return null;
	}

	$decoded = json_decode( substr( $raw, $start ), true );
	if ( is_array( $decoded ) ) {
		fwrite(
			STDERR,
			sprintf(
				"Note: %d byte(s) of output preceded the JSON response and were ignored: %s\n",
				$start,
				substr( $raw, 0, min( $start, 120 ) )
			)
		);

		return $decoded;
	}

	return null;
}

/**
 * Shortens a URL for log output, dropping its query string.
 *
 * @param string $url Absolute URL.
 *
 * @return string
 */
function redact( string $url ): string {
	$position = strpos( $url, '?' );

	return false === $position ? $url : substr( $url, 0, $position ) . '?...';
}

<?php
/**
 * Snippet directory metadata extractor.
 *
 * Parses the header docblock of a snippet file into the values the Code Snippets Directory
 * (BLD Gravity Form 2) stores: the public description as HTML, the Ecosystem tags, and the
 * installation method. The GitHub sync workflow calls this with --file and --json; run it with
 * --all locally to check every snippet in the repository before pushing.
 *
 * The docblock format is documented in docs/snippet-directory.md in the project docs repo.
 *
 * Usage:
 *   php .github/scripts/parse-snippet-doc.php --file="Some Snippet.php" [--json]
 *   php .github/scripts/parse-snippet-doc.php --all [--json] [--quiet]
 *
 * Exit codes: 0 = every parsed file is valid, 1 = at least one file has errors,
 * 2 = the arguments themselves were unusable.
 */

declare( strict_types=1 );

/**
 * Ecosystem flag tags, mapped to the exact configured choice values on field 9.
 *
 * The flag form keeps the docblock free of the choice strings themselves, which are easy to
 * mistype and whose typos are invisible until the tag silently fails to render.
 */
const ECOSYSTEM_FLAGS = [
	'gravityforms' => 'Gravity Forms',
	'gravityflow'  => 'Gravity Flow',
	'gravityview'  => 'Gravity View',
	'gravityperks' => 'Gravity Perks',
	'gravitykit'   => 'Gravity Kit',
	'gravityops'   => 'GravityOps',
	'wordpress'    => 'General WordPress',
];

/**
 * Installation methods, matching field 7's configured choice values verbatim.
 */
const INSTALL_METHODS = [ 'Code Snippets', 'Code Chest', 'Custom JS' ];

/**
 * Recognised section headers, in the order they must appear.
 *
 * GOAL is required; the rest are optional. Fixing the set is what stops the format drifting
 * back into the twelve variations it had before it was standardised.
 */
const SECTIONS = [
	'GOAL'          => 'Goal',
	'REQUIREMENTS'  => 'Requirements',
	'CONFIGURATION' => 'Configuration',
	'USAGE'         => 'Usage',
	'FEATURES'      => 'Features',
	'FILTERS'       => 'Filters',
	'NOTES'         => 'Notes',
];

/**
 * Maximum Ecosystem tags per snippet, per the tagging policy in the docs.
 */
const MAX_ECOSYSTEMS = 3;

// Only run as a command; sync-directory.php requires this file for its parser.
if ( isset( $argv[0] ) && realpath( $argv[0] ) === realpath( __FILE__ ) ) {
	exit( main( $argv ) );
}

/**
 * Parses the arguments and dispatches to the single-file or whole-repository mode.
 *
 * @param array $argv Raw CLI arguments.
 *
 * @return int Process exit code.
 */
function main( array $argv ): int {
	$options = parse_args( $argv );
	if ( null === $options ) {
		fwrite( STDERR, usage() );

		return 2;
	}

	if ( null !== $options['file'] ) {
		return run_single( $options['file'], $options['json'] );
	}

	return run_all( $options['json'], $options['quiet'] );
}

/**
 * Returns the parsed options, or null when the arguments cannot be used.
 *
 * @param array $argv Raw CLI arguments.
 *
 * @return array|null
 */
function parse_args( array $argv ): ?array {
	$options = [
		'file'  => null,
		'all'   => false,
		'json'  => false,
		'quiet' => false,
	];

	foreach ( array_slice( $argv, 1 ) as $argument ) {
		if ( '--all' === $argument ) {
			$options['all'] = true;
		} elseif ( '--json' === $argument ) {
			$options['json'] = true;
		} elseif ( '--quiet' === $argument ) {
			$options['quiet'] = true;
		} elseif ( str_starts_with( $argument, '--file=' ) ) {
			$options['file'] = substr( $argument, 7 );
		} else {
			return null;
		}
	}

	if ( ( null === $options['file'] ) === ( false === $options['all'] ) ) {
		return null;
	}

	if ( null !== $options['file'] && '' === trim( $options['file'] ) ) {
		return null;
	}

	return $options;
}

/**
 * Returns the usage text.
 *
 * @return string
 */
function usage(): string {
	return <<<'TXT'
	Usage:
	  php .github/scripts/parse-snippet-doc.php --file="Some Snippet.php" [--json]
	  php .github/scripts/parse-snippet-doc.php --all [--json] [--quiet]

	Exactly one of --file= or --all is required.

	TXT;
}

/**
 * Parses one file and prints either its JSON metadata or a human-readable report.
 *
 * @param string $path Repository-relative path to the snippet.
 * @param bool   $json Whether to emit JSON.
 *
 * @return int Process exit code.
 */
function run_single( string $path, bool $json ): int {
	$result = parse_snippet( $path );

	if ( [] !== $result['errors'] ) {
		foreach ( $result['errors'] as $error ) {
			fwrite( STDERR, sprintf( "%s: %s\n", $path, $error ) );
		}

		return 1;
	}

	if ( $json ) {
		$encoded = json_encode( $result['data'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( ! is_string( $encoded ) ) {
			fwrite( STDERR, sprintf( "%s: metadata could not be JSON encoded.\n", $path ) );

			return 1;
		}

		echo $encoded, "\n";

		return 0;
	}

	if ( false === $result['data']['publish'] ) {
		printf( "Path:        %s\nPublish:     no (@nopublish)\n", $result['data']['path'] );

		return 0;
	}

	echo report( $result['data'] );

	return 0;
}

/**
 * Parses every snippet in the repository and prints a pass/fail summary.
 *
 * This is the dry run: it surfaces missing docblocks, unknown sections and missing Ecosystem
 * tags across the whole directory before any of it reaches the live form.
 *
 * @param bool $json  Whether to emit a JSON array of every valid result.
 * @param bool $quiet Whether to suppress the per-file OK lines.
 *
 * @return int Process exit code.
 */
function run_all( bool $json, bool $quiet ): int {
	$paths   = snippet_paths();
	$results = [];
	$failed  = 0;

	foreach ( $paths as $path ) {
		$result = parse_snippet( $path );

		if ( [] !== $result['errors'] ) {
			++$failed;
			if ( ! $json ) {
				printf( "FAIL  %s\n", $path );
				foreach ( $result['errors'] as $error ) {
					printf( "        %s\n", $error );
				}
			}
			continue;
		}

		$results[] = $result['data'];

		if ( $json || $quiet ) {
			continue;
		}

		if ( false === $result['data']['publish'] ) {
			printf( "SKIP  %s  (@nopublish)\n", $path );
			continue;
		}

		printf(
			"OK    %s  [%s] %s\n",
			$path,
			$result['data']['install_with'],
			implode( ', ', $result['data']['ecosystems'] )
		);
	}

	if ( $json ) {
		$encoded = json_encode( $results, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT );
		echo is_string( $encoded ) ? $encoded . "\n" : "[]\n";
	} else {
		printf( "\n%d file(s), %d valid, %d with errors.\n", count( $paths ), count( $results ), $failed );
	}

	return $failed > 0 ? 1 : 0;
}

/**
 * Returns every snippet path in the repository, sorted.
 *
 * Mirrors the workflow's own filter: .php and .js only, no dotfiles, nothing under .github.
 *
 * @return array<int, string>
 */
function snippet_paths(): array {
	$paths    = [];
	$root     = getcwd();
	$iterator = new RecursiveIteratorIterator(
		new RecursiveCallbackFilterIterator(
			new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ),
			static function ( $current ) {
				return ! str_starts_with( $current->getFilename(), '.' );
			}
		)
	);

	foreach ( $iterator as $file ) {
		$extension = strtolower( $file->getExtension() );
		if ( 'php' !== $extension && 'js' !== $extension ) {
			continue;
		}

		$paths[] = ltrim( substr( $file->getPathname(), strlen( $root ) ), DIRECTORY_SEPARATOR );
	}

	sort( $paths );

	return $paths;
}

/**
 * Parses one snippet file into directory metadata.
 *
 * @param string $path Repository-relative path to the snippet.
 *
 * @return array{data: array, errors: array<int, string>}
 */
function parse_snippet( string $path ): array {
	if ( ! is_file( $path ) || ! is_readable( $path ) ) {
		return [
			'data'   => [],
			'errors' => [ 'File does not exist or is not readable.' ],
		];
	}

	$source = file_get_contents( $path );
	if ( ! is_string( $source ) ) {
		return [
			'data'   => [],
			'errors' => [ 'File could not be read.' ],
		];
	}

	$extension = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
	if ( 'php' !== $extension && 'js' !== $extension ) {
		return [
			'data'   => [],
			'errors' => [ 'Only .php and .js snippets can be published.' ],
		];
	}

	$docblock = extract_docblock( $source );
	if ( null === $docblock ) {
		return [
			'data'   => [],
			'errors' => [ 'No header docblock found. Every published snippet needs one; see docs/snippet-directory.md.' ],
		];
	}

	$lines = strip_docblock_markers( $docblock );

	return build_metadata( $path, $extension, $lines );
}

/**
 * Returns the file's header docblock, or null when there is none.
 *
 * Skips a leading <?php tag, blank lines and any line comments or non-docblock block comments
 * that precede it, so a file opening with a phpcs:disable comment still resolves.
 *
 * @param string $source File contents.
 *
 * @return string|null
 */
function extract_docblock( string $source ): ?string {
	$offset = 0;
	$length = strlen( $source );

	if ( str_starts_with( $source, "\xEF\xBB\xBF" ) ) {
		$offset = 3;
	}

	while ( $offset < $length ) {
		// Skip whitespace.
		if ( 1 === preg_match( '/\G\s+/', $source, $matches, 0, $offset ) ) {
			$offset += strlen( $matches[0] );
			continue;
		}

		// Skip the opening PHP tag.
		if ( 1 === preg_match( '/\G<\?php\b/i', $source, $matches, 0, $offset ) ) {
			$offset += strlen( $matches[0] );
			continue;
		}

		// The header docblock.
		if ( str_starts_with( substr( $source, $offset, 3 ), '/**' ) ) {
			$end = strpos( $source, '*/', $offset + 3 );

			return false === $end ? null : substr( $source, $offset, $end + 2 - $offset );
		}

		// Skip a line comment.
		if ( 1 === preg_match( '#\G(//|\#)[^\n]*#', $source, $matches, 0, $offset ) ) {
			$offset += strlen( $matches[0] );
			continue;
		}

		// Skip a non-docblock block comment.
		if ( str_starts_with( substr( $source, $offset, 2 ), '/*' ) ) {
			$end = strpos( $source, '*/', $offset + 2 );
			if ( false === $end ) {
				return null;
			}
			$offset = $end + 2;
			continue;
		}

		// Anything else means the file has no header docblock.
		return null;
	}

	return null;
}

/**
 * Strips the comment markers from a docblock, preserving relative indentation.
 *
 * @param string $docblock Raw docblock including its /** and *​/ markers.
 *
 * @return array<int, string>
 */
function strip_docblock_markers( string $docblock ): array {
	$docblock = preg_replace( '#^/\*\*#', '', $docblock );
	$docblock = preg_replace( '#\*/\s*$#', '', (string) $docblock );

	$lines  = preg_split( '/\R/', (string) $docblock ) ?: [];
	$output = [];

	foreach ( $lines as $line ) {
		// Drop the leading whitespace and the single asterisk, and exactly one space after it.
		$stripped = preg_replace( '/^[ \t]*\*/', '', $line );
		if ( null === $stripped ) {
			continue;
		}

		if ( str_starts_with( $stripped, ' ' ) ) {
			$stripped = substr( $stripped, 1 );
		}

		$output[] = rtrim( $stripped );
	}

	// Trim leading and trailing blank lines.
	while ( [] !== $output && '' === trim( $output[0] ) ) {
		array_shift( $output );
	}
	while ( [] !== $output && '' === trim( $output[ count( $output ) - 1 ] ) ) {
		array_pop( $output );
	}

	return $output;
}

/**
 * Turns the stripped docblock lines into directory metadata.
 *
 * @param string             $path      Repository-relative path.
 * @param string             $extension Lowercased file extension.
 * @param array<int, string> $lines     Stripped docblock lines.
 *
 * @return array{data: array, errors: array<int, string>}
 */
function build_metadata( string $path, string $extension, array $lines ): array {
	$errors = [];

	if ( [] === $lines ) {
		return [
			'data'   => [],
			'errors' => [ 'The header docblock is empty.' ],
		];
	}

	foreach ( $lines as $line ) {
		if ( 1 === preg_match( '/^\s*Plugin Name\s*:/i', $line ) ) {
			return [
				'data'   => [],
				'errors' => [ 'The header docblock is a WordPress plugin header, not a snippet description. Replace it with the documented format.' ],
			];
		}
	}

	// A file kept in the repository but deliberately absent from the public directory. Checked
	// before anything else: an unpublished snippet owes the directory no description or tags.
	foreach ( $lines as $line ) {
		if ( 1 === preg_match( '/^\s*@nopublish\b/i', $line ) ) {
			return [
				'data'   => [
					'path'    => $path,
					'publish' => false,
				],
				'errors' => [],
			];
		}
	}

	$title = trim( array_shift( $lines ) );
	if ( '' === $title ) {
		$errors[] = 'The first docblock line must be the snippet title.';
	}
	if ( 1 === preg_match( '/^@/', $title ) || is_section_header( $title ) ) {
		$errors[] = 'The first docblock line must be the snippet title, not a tag or a section header.';
		$title    = '';
	}

	$ecosystems = [];
	$install    = null;

	// Tags occupy the block between the title and the first section header.
	while ( [] !== $lines ) {
		$line = trim( $lines[0] );

		if ( '' === $line ) {
			array_shift( $lines );
			continue;
		}

		if ( ! str_starts_with( $line, '@' ) ) {
			break;
		}

		array_shift( $lines );

		if ( 1 === preg_match( '/^@install\b[:\s]*(.*)$/i', $line, $matches ) ) {
			$value = trim( $matches[1] );
			if ( ! in_array( $value, INSTALL_METHODS, true ) ) {
				$errors[] = sprintf(
					'@install must be one of %s; found "%s".',
					implode( ', ', INSTALL_METHODS ),
					$value
				);
			} elseif ( null !== $install ) {
				$errors[] = '@install is declared more than once.';
			} else {
				$install = $value;
			}
			continue;
		}

		$flag = strtolower( ltrim( strtok( $line, " \t" ) ?: '', '@' ) );
		if ( ! isset( ECOSYSTEM_FLAGS[ $flag ] ) ) {
			$errors[] = sprintf(
				'Unknown tag "@%s". Allowed: %s, @install, @nopublish.',
				$flag,
				implode( ', ', array_map( static fn( $f ) => '@' . $f, array_keys( ECOSYSTEM_FLAGS ) ) )
			);
			continue;
		}

		if ( trim( (string) substr( $line, strlen( $flag ) + 1 ) ) !== '' ) {
			$errors[] = sprintf( 'Ecosystem tag "@%s" must stand alone on its line.', $flag );
			continue;
		}

		$value = ECOSYSTEM_FLAGS[ $flag ];
		if ( in_array( $value, $ecosystems, true ) ) {
			$errors[] = sprintf( 'Ecosystem tag "@%s" is repeated.', $flag );
			continue;
		}

		$ecosystems[] = $value;
	}

	if ( [] === $ecosystems ) {
		$errors[] = sprintf(
			'At least one Ecosystem tag is required. Allowed: %s.',
			implode( ', ', array_map( static fn( $f ) => '@' . $f, array_keys( ECOSYSTEM_FLAGS ) ) )
		);
	} elseif ( count( $ecosystems ) > MAX_ECOSYSTEMS ) {
		$errors[] = sprintf(
			'%d Ecosystem tags declared; the policy allows at most %d.',
			count( $ecosystems ),
			MAX_ECOSYSTEMS
		);
	}

	if ( null === $install ) {
		$install = 'js' === $extension ? 'Code Chest' : 'Code Snippets';
	}

	$sections = collect_sections( $lines, $errors );

	if ( ! array_key_exists( 'GOAL', $sections ) ) {
		$errors[] = 'A GOAL: section is required.';
	}

	if ( [] !== $errors ) {
		return [
			'data'   => [],
			'errors' => $errors,
		];
	}

	$description = render_sections( $sections );
	if ( '' === $description ) {
		return [
			'data'   => [],
			'errors' => [ 'The docblock produced an empty description.' ],
		];
	}

	return [
		'data'   => [
			'path'         => $path,
			'publish'      => true,
			'title'        => $title,
			'install_with' => $install,
			'ecosystems'   => $ecosystems,
			'description'  => $description,
		],
		'errors' => [],
	];
}

/**
 * Returns true when the line is a recognised or recognisable section header.
 *
 * @param string $line Trimmed line.
 *
 * @return bool
 */
function is_section_header( string $line ): bool {
	return 1 === preg_match( "/^[A-Z][A-Z0-9 ()\\/&,'-]*:$/", $line );
}

/**
 * Splits the remaining lines into their sections, in document order.
 *
 * @param array<int, string> $lines  Lines after the tag block.
 * @param array<int, string> $errors Error list, appended to by reference.
 *
 * @return array<string, array<int, string>>
 */
function collect_sections( array $lines, array &$errors ): array {
	$sections = [];
	$current  = null;
	$seen     = [];

	foreach ( $lines as $line ) {
		$trimmed = trim( $line );

		if ( is_section_header( $trimmed ) ) {
			$name = rtrim( $trimmed, ':' );

			if ( ! array_key_exists( $name, SECTIONS ) ) {
				$errors[] = sprintf(
					'Unknown section "%s:". Allowed sections: %s.',
					$name,
					implode( ', ', array_map( static fn( $s ) => $s . ':', array_keys( SECTIONS ) ) )
				);
				$current  = null;
				continue;
			}

			if ( isset( $seen[ $name ] ) ) {
				$errors[] = sprintf( 'Section "%s:" appears more than once.', $name );
				$current  = null;
				continue;
			}

			$seen[ $name ]     = true;
			$current           = $name;
			$sections[ $name ] = [];
			continue;
		}

		if ( null === $current ) {
			if ( '' !== $trimmed ) {
				$errors[] = sprintf( 'Text outside any section: "%s". Every paragraph must sit under a section header.', $trimmed );
			}
			continue;
		}

		$sections[ $current ][] = $line;
	}

	$order    = array_keys( SECTIONS );
	$actual   = array_keys( $sections );
	$expected = array_values( array_filter( $order, static fn( $s ) => in_array( $s, $actual, true ) ) );

	if ( $actual !== $expected ) {
		$errors[] = sprintf(
			'Sections are out of order. Expected %s, found %s.',
			implode( ', ', $expected ),
			implode( ', ', $actual )
		);
	}

	return $sections;
}

/**
 * Renders every section as the HTML stored in field 3.
 *
 * @param array<string, array<int, string>> $sections Section name to its lines.
 *
 * @return string
 */
function render_sections( array $sections ): string {
	$html = [];

	foreach ( array_keys( SECTIONS ) as $name ) {
		if ( ! array_key_exists( $name, $sections ) ) {
			continue;
		}

		$body = render_blocks( $sections[ $name ] );
		if ( '' === $body ) {
			continue;
		}

		$html[] = sprintf( '<h3>%s</h3>', esc( SECTIONS[ $name ] ) );
		$html[] = $body;
	}

	return implode( "\n", $html );
}

/**
 * Renders one section's lines into paragraphs, lists, sub-headings and code blocks.
 *
 * @param array<int, string> $lines Section lines, indentation preserved.
 *
 * @return string
 */
function render_blocks( array $lines ): string {
	$html      = [];
	$paragraph = [];
	$list      = [];
	$code      = [];

	$flush_paragraph = static function () use ( &$paragraph, &$html ): void {
		if ( [] === $paragraph ) {
			return;
		}
		$html[]    = sprintf( '<p>%s</p>', inline( implode( ' ', $paragraph ) ) );
		$paragraph = [];
	};

	$flush_list = static function () use ( &$list, &$html ): void {
		if ( [] === $list ) {
			return;
		}
		$html[] = render_list( $list );
		$list   = [];
	};

	$flush_code = static function () use ( &$code, &$html ): void {
		if ( [] === $code ) {
			return;
		}
		while ( [] !== $code && '' === trim( $code[ count( $code ) - 1 ] ) ) {
			array_pop( $code );
		}
		$html[] = sprintf( '<pre><code>%s</code></pre>', esc( implode( "\n", dedent( $code ) ) ) );
		$code   = [];
	};

	foreach ( $lines as $line ) {
		$trimmed = trim( $line );
		$indent  = indent_width( $line );

		// A blank line closes a paragraph or a list, but is kept inside a code block.
		if ( '' === $trimmed ) {
			if ( [] !== $code ) {
				$code[] = '';
				continue;
			}
			$flush_paragraph();
			$flush_list();
			continue;
		}

		$is_bullet = 1 === preg_match( '/^-\s+\S/', $trimmed );

		// Four or more spaces of indent starts a code block, unless it continues a list item.
		if ( $indent >= 4 && ! $is_bullet && [] === $list && [] === $paragraph ) {
			$code[] = $line;
			continue;
		}

		$flush_code();

		if ( $is_bullet ) {
			$flush_paragraph();
			$list[] = [
				'indent' => $indent,
				'text'   => [ ltrim( $trimmed, '- ' ) ],
			];
			continue;
		}

		// A deeper-indented non-bullet line continues the previous list item.
		if ( [] !== $list && $indent > $list[ count( $list ) - 1 ]['indent'] ) {
			$list[ count( $list ) - 1 ]['text'][] = $trimmed;
			continue;
		}

		$flush_list();

		// An explicitly marked "# Something" line is a sub-heading. The marker is required
		// because a lead-in sentence ending in a colon is far more common than a sub-heading,
		// and guessing between the two gets it wrong in both directions.
		if ( 1 === preg_match( '/^#\s+(\S.*)$/', $trimmed, $matches ) ) {
			$flush_paragraph();
			$html[] = sprintf( '<h4>%s</h4>', inline( trim( $matches[1] ) ) );
			continue;
		}

		$paragraph[] = $trimmed;
	}

	$flush_code();
	$flush_paragraph();
	$flush_list();

	return implode( "\n", $html );
}

/**
 * Renders collected list items, supporting one level of nesting.
 *
 * @param array<int, array{indent: int, text: array<int, string>}> $items Collected items.
 *
 * @return string
 */
function render_list( array $items ): string {
	$base   = min( array_map( static fn( $item ) => $item['indent'], $items ) );
	$html   = [ '<ul>' ];
	$nested = false;

	foreach ( $items as $item ) {
		$text = inline( implode( ' ', $item['text'] ) );

		if ( $item['indent'] > $base ) {
			if ( ! $nested ) {
				// Re-open the previous item so the nested list sits inside it.
				$last = array_pop( $html );
				if ( is_string( $last ) && str_ends_with( $last, '</li>' ) ) {
					$html[] = substr( $last, 0, -5 );
				} else {
					$html[] = (string) $last;
				}
				$html[] = '<ul>';
				$nested = true;
			}
			$html[] = sprintf( '<li>%s</li>', $text );
			continue;
		}

		if ( $nested ) {
			$html[] = '</ul>';
			$html[] = '</li>';
			$nested = false;
		}

		$html[] = sprintf( '<li>%s</li>', $text );
	}

	if ( $nested ) {
		$html[] = '</ul>';
		$html[] = '</li>';
	}

	$html[] = '</ul>';

	return implode( "\n", $html );
}

/**
 * Removes the common leading indentation from a code block.
 *
 * @param array<int, string> $lines Code block lines.
 *
 * @return array<int, string>
 */
function dedent( array $lines ): array {
	$widths = [];
	foreach ( $lines as $line ) {
		if ( '' !== trim( $line ) ) {
			$widths[] = indent_width( $line );
		}
	}

	$strip = [] === $widths ? 0 : min( $widths );

	return array_map(
		static function ( $line ) use ( $strip ) {
			$expanded = str_replace( "\t", '    ', $line );

			return substr( $expanded, min( $strip, strlen( $expanded ) - strlen( ltrim( $expanded ) ) ) );
		},
		$lines
	);
}

/**
 * Returns a line's indentation width, counting a tab as four columns.
 *
 * @param string $line Raw line.
 *
 * @return int
 */
function indent_width( string $line ): int {
	$expanded = str_replace( "\t", '    ', $line );

	return strlen( $expanded ) - strlen( ltrim( $expanded ) );
}

/**
 * Escapes text and applies inline markup.
 *
 * The text is escaped first and the markup inserted afterwards, so prose containing angle
 * brackets or ampersands cannot break the emitted HTML and the generated tags are never
 * double-escaped.
 *
 * @param string $text Plain text.
 *
 * @return string
 */
function inline( string $text ): string {
	$escaped = esc( $text );

	// Backticks become inline code; the content is already escaped.
	$escaped = preg_replace_callback(
		'/`([^`]+)`/',
		static fn( $matches ) => '<code>' . $matches[1] . '</code>',
		$escaped
	);

	// Double asterisks become strong emphasis.
	$escaped = preg_replace_callback(
		'/\*\*([^*]+)\*\*/',
		static fn( $matches ) => '<strong>' . $matches[1] . '</strong>',
		(string) $escaped
	);

	return (string) $escaped;
}

/**
 * Escapes text for HTML output.
 *
 * Quotes are deliberately left alone: every tag this script emits is attribute-free, so no
 * text ever lands inside an attribute, and escaping quotes would only litter the stored
 * description with entities.
 *
 * @param string $text Plain text.
 *
 * @return string
 */
function esc( string $text ): string {
	return htmlspecialchars( $text, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8' );
}

/**
 * Renders a human-readable report for one parsed snippet.
 *
 * @param array $data Parsed metadata.
 *
 * @return string
 */
function report( array $data ): string {
	return sprintf(
		"Path:        %s\nTitle:       %s\nInstall:     %s\nEcosystems:  %s\n\n%s\n",
		$data['path'],
		$data['title'],
		$data['install_with'],
		implode( ', ', $data['ecosystems'] ),
		$data['description']
	);
}

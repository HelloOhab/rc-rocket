<?php
/**
 * Keep only the CSS a page can use.
 *
 * @package RCRocket
 */

declare( strict_types=1 );

namespace RCRocket\Assets;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A rule survives when every class, id and element it names occurs in the
 * page: in its HTML, or in the page's own JavaScript, which is where the
 * classes a script adds later (a menu opening, an animation starting) are
 * spelled out. Anything this cannot judge is kept. The cost of keeping a
 * rule nobody uses is a few bytes; the cost of dropping one in use is a
 * broken page.
 *
 * Pure string work, no WordPress: tested on its own.
 */
final class CssPurger {

	/** At-rules whose contents are rules to filter, block by block. */
	private const NESTED = [ 'media', 'supports', 'layer', 'container', 'document', '-moz-document', 'scope' ];

	/** At-rules kept whole: not selectors, nothing to match. */
	private const KEEP_WHOLE = [ 'font-face', 'page', 'property', 'counter-style', 'font-feature-values', 'font-palette-values', 'view-transition', 'viewport', '-ms-viewport' ];

	/** Selectors that always match something on a web page. */
	private const ALWAYS = [ 'html', 'body', '*', ':root' ];

	/** @var array<string, true> */
	private array $tokens;

	/** @var string[] */
	private array $safelist;

	/** @var array<string, string> name => full @keyframes block, decided after the rules. */
	private array $keyframes = [];

	/**
	 * @param array<string, true> $tokens   Every word the page contains, see words().
	 * @param string[]            $safelist Selectors containing any of these are always kept.
	 */
	public function __construct( array $tokens, array $safelist = [] ) {
		$this->tokens   = $tokens;
		$this->safelist = array_values( array_filter( array_map( 'trim', $safelist ), static fn( string $s ): bool => '' !== $s ) );
	}

	/**
	 * Words that can name a class, id or element, as a set.
	 *
	 * Deliberately loose: a word in body text counts too. Over-keeping is
	 * the safe direction.
	 *
	 * @return array<string, true>
	 */
	public static function words( string $text ): array {
		preg_match_all( '#[A-Za-z_][A-Za-z0-9_-]*#', $text, $m );

		$set = [];

		foreach ( $m[0] as $word ) {
			$set[ $word ]                 = true;
			$set[ strtolower( $word ) ]   = true;
		}

		return $set;
	}

	/** The CSS this page uses, comments and redundant whitespace removed. */
	public function purge( string $css ): string {
		$this->keyframes = [];

		$css  = self::strip_comments( $css );
		$kept = $this->block( $css );

		// A @keyframes block is only worth keeping if a kept rule animates
		// with it.
		$used = self::words( $kept );

		foreach ( $this->keyframes as $name => $block ) {
			if ( isset( $used[ $name ] ) ) {
				$kept .= $block;
			}
		}

		return $kept;
	}

	/** Make url() references absolute, so the CSS still works inlined in a page. */
	public static function absolutize_urls( string $css, string $stylesheet_url ): string {
		$base = (string) preg_replace( '#[?\#].*$#', '', $stylesheet_url );
		$base = substr( $base, 0, (int) strrpos( $base, '/' ) + 1 );

		return (string) preg_replace_callback(
			'#url\(\s*([\'"]?)([^\'")]+)\1\s*\)#i',
			static function ( array $m ) use ( $base ): string {
				$url = trim( $m[2] );

				if ( '' === $url || preg_match( '#^(?:[a-z][a-z0-9+.-]*:|//|/|\#)#i', $url ) ) {
					return $m[0];
				}

				return 'url(' . $m[1] . self::resolve( $base, $url ) . $m[1] . ')';
			},
			$css
		);
	}

	// ----------------------------------------------------------------- internals

	/** Filter one level of CSS: rules and at-rules in sequence. */
	private function block( string $css ): string {
		$out = '';
		$len = strlen( $css );
		$i   = 0;

		while ( $i < $len ) {
			// Skip whitespace and stray semicolons between rules.
			while ( $i < $len && ( ctype_space( $css[ $i ] ) || ';' === $css[ $i ] ) ) {
				++$i;
			}

			if ( $i >= $len ) {
				break;
			}

			// The prelude runs to the first top-level "{" or ";".
			$end = self::find( $css, $i, [ '{', ';' ] );

			if ( null === $end ) {
				break;
			}

			$prelude = trim( substr( $css, $i, $end - $i ) );

			if ( ';' === $css[ $end ] ) {
				// A statement at-rule: @import, @charset, @namespace, @layer a, b;
				$i = $end + 1;

				if ( 0 === stripos( $prelude, '@import' ) || 0 === stripos( $prelude, '@namespace' ) || 0 === stripos( $prelude, '@layer' ) ) {
					$out .= $prelude . ';';
				}

				continue;
			}

			$close = self::matching_brace( $css, $end );

			if ( null === $close ) {
				// Unbalanced: keep the remainder rather than guess.
				$out .= substr( $css, $i );
				break;
			}

			$body = substr( $css, $end + 1, $close - $end - 1 );
			$i    = $close + 1;

			if ( '@' === $prelude[0] ) {
				$out .= $this->at_rule( $prelude, $body );
				continue;
			}

			$selectors = $this->keep_selectors( $prelude );

			if ( '' !== $selectors ) {
				$out .= $selectors . '{' . self::compact( $body ) . '}';
			}
		}

		return $out;
	}

	private function at_rule( string $prelude, string $body ): string {
		preg_match( '#^@([a-z-]+)#i', $prelude, $m );
		$name = strtolower( $m[1] ?? '' );

		if ( in_array( $name, self::NESTED, true ) ) {
			$inner = $this->block( $body );

			return '' === $inner ? '' : self::compact( $prelude ) . '{' . $inner . '}';
		}

		if ( preg_match( '#^(?:-[a-z]+-)?keyframes$#', $name ) ) {
			$id = trim( substr( $prelude, strlen( $m[0] ) ) );
			$id = trim( $id, " \t\n\r\"'" );

			$this->keyframes[ $id ] = ( $this->keyframes[ $id ] ?? '' ) . self::compact( $prelude ) . '{' . self::compact( $body ) . '}';

			return '';
		}

		// @font-face, @page, anything unknown: kept whole.
		return self::compact( $prelude ) . '{' . self::compact( $body ) . '}';
	}

	/** The selectors of a list that may match, joined again; '' when none. */
	private function keep_selectors( string $prelude ): string {
		$kept = [];

		foreach ( self::split_list( $prelude ) as $selector ) {
			$selector = trim( $selector );

			if ( '' !== $selector && $this->may_match( $selector ) ) {
				$kept[] = self::compact( $selector );
			}
		}

		return implode( ',', $kept );
	}

	public function may_match( string $selector ): bool {
		foreach ( $this->safelist as $safe ) {
			if ( str_contains( $selector, $safe ) ) {
				return true;
			}
		}

		// Remove what cannot be judged from the page alone: functional
		// pseudo-classes with their arguments, other pseudo-classes and
		// -elements, and attribute selectors. Each removal can only keep
		// more, never less.
		$s = self::remove_functional_pseudos( $selector );
		$s = (string) preg_replace( '#\[[^\]]*\]#', ' ', $s );
		$s = (string) preg_replace( '#::?-?[a-zA-Z][\w-]*#', ' ', $s );

		// Escaped characters in class names: .md\:flex is the class "md:flex".
		$s = (string) preg_replace( '#\\\\([^0-9a-fA-F\s])#', '$1', $s );

		preg_match_all( '#([.\#])((?:[\w-]|[^\x00-\x7F]|[:/@%])+)|(?<![\w.\#-])([a-zA-Z][\w-]*)#u', $s, $parts, PREG_SET_ORDER );

		foreach ( $parts as $part ) {
			if ( '' !== ( $part[2] ?? '' ) ) {
				// A class or id: every one of them must occur in the page.
				if ( ! $this->has( $part[2] ) ) {
					return false;
				}

				continue;
			}

			$tag = strtolower( $part[3] ?? '' );

			if ( '' !== $tag && ! in_array( $tag, self::ALWAYS, true ) && ! isset( $this->tokens[ $tag ] ) ) {
				return false;
			}
		}

		return true;
	}

	private function has( string $name ): bool {
		if ( isset( $this->tokens[ $name ] ) ) {
			return true;
		}

		// A name with characters the word scan splits on (md:flex, w-1/2)
		// counts when each piece occurs.
		$pieces = preg_split( '#[^A-Za-z0-9_-]+#', $name, -1, PREG_SPLIT_NO_EMPTY );

		if ( ! $pieces || ( 1 === count( $pieces ) && $pieces[0] === $name ) ) {
			return false;
		}

		foreach ( $pieces as $piece ) {
			if ( ! isset( $this->tokens[ $piece ] ) ) {
				return false;
			}
		}

		return true;
	}

	/** :not(), :is(), :where(), :has(), :nth-child() … with balanced arguments. */
	private static function remove_functional_pseudos( string $selector ): string {
		$out = '';
		$len = strlen( $selector );

		for ( $i = 0; $i < $len; $i++ ) {
			if ( ':' === $selector[ $i ] && preg_match( '#^::?-?[a-zA-Z][\w-]*\(#', substr( $selector, $i, 40 ), $m ) ) {
				$depth = 0;

				for ( $j = $i + strlen( $m[0] ) - 1; $j < $len; $j++ ) {
					if ( '(' === $selector[ $j ] ) {
						++$depth;
					} elseif ( ')' === $selector[ $j ] && 0 === --$depth ) {
						break;
					}
				}

				$out .= ' ';
				$i    = $j;
				continue;
			}

			$out .= $selector[ $i ];
		}

		return $out;
	}

	/** Split "a, b:is(c, d), e" at top-level commas only. */
	private static function split_list( string $list ): array {
		$parts = [];
		$depth = 0;
		$start = 0;
		$len   = strlen( $list );
		$quote = '';

		for ( $i = 0; $i < $len; $i++ ) {
			$c = $list[ $i ];

			if ( '' !== $quote ) {
				if ( '\\' === $c ) {
					++$i;
				} elseif ( $c === $quote ) {
					$quote = '';
				}
				continue;
			}

			if ( '"' === $c || "'" === $c ) {
				$quote = $c;
			} elseif ( '(' === $c || '[' === $c ) {
				++$depth;
			} elseif ( ')' === $c || ']' === $c ) {
				--$depth;
			} elseif ( ',' === $c && 0 === $depth ) {
				$parts[] = substr( $list, $start, $i - $start );
				$start   = $i + 1;
			}
		}

		$parts[] = substr( $list, $start );

		return $parts;
	}

	/**
	 * Position of the first of $chars at nesting depth 0 from $from, outside
	 * strings and parentheses (a url() can contain ";").
	 *
	 * @param string[] $chars
	 */
	private static function find( string $css, int $from, array $chars ): ?int {
		$len   = strlen( $css );
		$quote = '';
		$paren = 0;

		for ( $i = $from; $i < $len; $i++ ) {
			$c = $css[ $i ];

			if ( '' !== $quote ) {
				if ( '\\' === $c ) {
					++$i;
				} elseif ( $c === $quote ) {
					$quote = '';
				}
				continue;
			}

			if ( '"' === $c || "'" === $c ) {
				$quote = $c;
			} elseif ( '(' === $c ) {
				++$paren;
			} elseif ( ')' === $c ) {
				$paren = max( 0, $paren - 1 );
			} elseif ( 0 === $paren && in_array( $c, $chars, true ) ) {
				return $i;
			}
		}

		return null;
	}

	/** The "}" closing the "{" at $open, outside strings. */
	private static function matching_brace( string $css, int $open ): ?int {
		$len   = strlen( $css );
		$depth = 0;
		$quote = '';

		for ( $i = $open; $i < $len; $i++ ) {
			$c = $css[ $i ];

			if ( '' !== $quote ) {
				if ( '\\' === $c ) {
					++$i;
				} elseif ( $c === $quote ) {
					$quote = '';
				}
				continue;
			}

			if ( '"' === $c || "'" === $c ) {
				$quote = $c;
			} elseif ( '{' === $c ) {
				++$depth;
			} elseif ( '}' === $c && 0 === --$depth ) {
				return $i;
			}
		}

		return null;
	}

	private static function strip_comments( string $css ): string {
		$out   = '';
		$len   = strlen( $css );
		$quote = '';

		for ( $i = 0; $i < $len; $i++ ) {
			$c = $css[ $i ];

			if ( '' !== $quote ) {
				$out .= $c;

				if ( '\\' === $c && $i + 1 < $len ) {
					$out .= $css[ ++$i ];
				} elseif ( $c === $quote ) {
					$quote = '';
				}
				continue;
			}

			if ( '/' === $c && $i + 1 < $len && '*' === $css[ $i + 1 ] ) {
				$end = strpos( $css, '*/', $i + 2 );
				$i   = false === $end ? $len : $end + 1;
				continue;
			}

			if ( '"' === $c || "'" === $c ) {
				$quote = $c;
			}

			$out .= $c;
		}

		return $out;
	}

	/** Collapse runs of whitespace outside strings. */
	private static function compact( string $text ): string {
		return trim( (string) preg_replace( '#\s+#', ' ', $text ) );
	}

	private static function resolve( string $base, string $relative ): string {
		$path = $base . $relative;

		// Fold "dir/../" and "./" so the address stays tidy.
		$scheme = '';

		if ( preg_match( '#^([a-z][a-z0-9+.-]*://[^/]+)(/.*)$#i', $path, $m ) ) {
			$scheme = $m[1];
			$path   = $m[2];
		}

		$segments = [];

		foreach ( explode( '/', $path ) as $segment ) {
			if ( '..' === $segment ) {
				array_pop( $segments );
			} elseif ( '.' !== $segment ) {
				$segments[] = $segment;
			}
		}

		return $scheme . implode( '/', $segments );
	}
}

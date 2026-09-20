<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Chicago Website DragDrop's question builder — for Chicago's own Website
 * rule (see Citex_Chicago_Reference_Rules's own docblock): no "Available
 * at:" label and no accessed-date field at all (like APA's own modern
 * reasoning: this app's invented sources are stable, so a retrieval date
 * is never required), a double-quoted title (matching Journal Article's
 * own quoting rule — unlike Harvard/APA/MHRA's unquoted title), and "n.d."
 * for a missing year exactly like Harvard/APA (never MLA's "omit the
 * segment" approach) — so `year` is ALWAYS present as a token here, never
 * conditionally omitted.
 *
 * Eligible content candidates: author, year, title — exactly 3, so every
 * question draws all 3 deterministically (no selection randomness needed,
 * the same "nothing left to leave out" situation as APA Website's own
 * class). `url` carries a token key but is never eligible for drawing —
 * same rationale as every other style's own Website class: a URL needs no
 * style-format transformation, so dragging it as a blank would be pure
 * copy-paste recognition, not a formatting test.
 *
 * Pure and static, no WordPress/ACF calls — unit-testable directly.
 */
class Citex_Chicago_Website_Dragdrop_Parts {

	/**
	 * @return string[] `url` deliberately excluded — see this class's own
	 * docblock.
	 */
	private static function content_slots() {
		return array( 'author', 'year', 'title' );
	}

	/**
	 * Builds the full ordered token list for one Chicago Website record.
	 * The year segment's own trailing punctuation genuinely differs
	 * between a real 4-digit year (which needs its own added full stop)
	 * and the literal "n.d." (which already carries its own abbreviation
	 * period) — see Citex_Chicago_Reference_Rules::build_website_reference()'s
	 * own docblock for the identical reasoning; this split keeps the
	 * "year" token's own drawn VALUE a clean bare year or "n.d." (so
	 * distractors never have to strip a baked-in period) while the correct
	 * literal punctuation still surrounds it in fixedText either way.
	 *
	 * @param array $author {type: 'individual'|'organisation', surname?, givenName?, name?}.
	 * @param array $fields {year (4-digit string or literal 'n.d.'), title, url}.
	 * @return array<{key: string|null, kind: string, value: string, literal: bool}>
	 */
	public static function build_tokens( array $author, array $fields ) {
		$tokens = array();
		$tokens[] = array( 'key' => 'author', 'kind' => 'author', 'value' => Citex_Chicago_Reference_Rules::format_website_author( $author ), 'literal' => false );
		$tokens[] = array( 'key' => null, 'kind' => 'literal', 'value' => ' ', 'literal' => true );

		$year = (string) $fields['year'];
		if ( 'n.d.' === $year ) {
			$tokens[] = array( 'key' => 'year', 'kind' => 'year', 'value' => 'n.d.', 'literal' => false );
			$tokens[] = array( 'key' => null, 'kind' => 'literal', 'value' => ' "', 'literal' => true );
		} else {
			$tokens[] = array( 'key' => 'year', 'kind' => 'year', 'value' => $year, 'literal' => false );
			$tokens[] = array( 'key' => null, 'kind' => 'literal', 'value' => '. "', 'literal' => true );
		}

		$tokens[] = array( 'key' => 'title', 'kind' => 'title', 'value' => (string) $fields['title'], 'literal' => false );
		$tokens[] = array( 'key' => null, 'kind' => 'literal', 'value' => '." ', 'literal' => true );
		$tokens[] = array( 'key' => 'url', 'kind' => 'url', 'value' => (string) $fields['url'], 'literal' => false );
		$tokens[] = array( 'key' => null, 'kind' => 'literal', 'value' => '.', 'literal' => true );
		return $tokens;
	}

	/**
	 * Every eligible candidate is always drawn (exactly 3 exist, target is
	 * 3) — kept as an explicit method, seeded the same way as every other
	 * category, purely so a future design could narrow this without
	 * touching call sites.
	 *
	 * @param string|int $seed Typically the question's own id.
	 * @return string[] ordered candidate keys.
	 */
	public static function select_parts( $seed ) {
		$selected_set = array_fill_keys( self::content_slots(), true );
		$empty_author = array( 'type' => 'individual', 'surname' => '', 'givenName' => '' );
		$empty_fields = array( 'year' => '2000', 'title' => '', 'url' => '' );
		$ordered      = array();
		foreach ( self::build_tokens( $empty_author, $empty_fields ) as $token ) {
			if ( ! $token['literal'] && isset( $selected_set[ $token['key'] ] ) ) {
				$ordered[] = $token['key'];
			}
		}
		return $ordered;
	}

	/**
	 * Builds {parts, fixedText, confusingWords} for one question from a
	 * selection of candidate keys and the record's own canonical fields.
	 *
	 * @param string[] $selected_keys
	 * @param array    $author {type, surname?, givenName?, name?, fullName?}.
	 * @param array    $fields {year, title, url}.
	 * @return array{parts: string[], fixedText: string, confusingWords: string[]}|null
	 */
	public static function build( array $selected_keys, array $author, array $fields ) {
		$selected_set = array_fill_keys( array_map( 'strval', $selected_keys ), true );
		$tokens       = self::build_tokens( $author, $fields );
		$token_count  = count( $tokens );
		$record_seed  = implode( '|', array( (string) $fields['year'], (string) $fields['title'], (string) $fields['url'] ) );

		$parts     = array();
		$confusing = array();
		$fixed     = '';
		foreach ( $tokens as $index => $token ) {
			if ( $token['literal'] ) {
				$fixed .= $token['value'];
				continue;
			}
			if ( isset( $selected_set[ $token['key'] ] ) ) {
				$is_first    = '' === trim( $fixed );
				$is_last     = ( $index === $token_count - 1 );
				$fixed      .= ( $is_first || $is_last ) ? '|' : '||';
				$parts[]     = $token['value'];
				$confusing[] = self::distractor_for( $token['kind'], $token['value'], $author, $fields, $record_seed );
			} else {
				$fixed .= $token['value'];
			}
		}
		if ( empty( $parts ) ) {
			return null;
		}
		return array( 'parts' => $parts, 'fixedText' => $fixed, 'confusingWords' => $confusing );
	}

	/**
	 * One deterministic wrong chip for a drawn candidate, keyed by its
	 * "kind" — reuses Citex_Reference_Rules's own shared deterministic
	 * distractor primitives wherever the underlying shape is identical to
	 * Harvard's; `year` uses year_or_undated_distractor() since a `year`
	 * token here may genuinely be the literal "n.d.".
	 */
	private static function distractor_for( $kind, $value, array $author, array $fields, $record_seed ) {
		switch ( $kind ) {
			case 'author':
				if ( 'organisation' === ( $author['type'] ?? '' ) ) {
					$pick = Citex_Reference_Rules::pick_from_pool( Citex_Reference_Rules::organisation_pool(), array( $value ), $record_seed . '|author' );
					return null !== $pick ? $pick : 'Unknown Organisation';
				}
				$full_name = (string) ( $author['fullName'] ?? ( trim( ( $author['givenName'] ?? '' ) . ' ' . ( $author['surname'] ?? '' ) ) ) );
				$surname   = (string) ( $author['surname'] ?? '' );
				return Citex_Reference_Rules::combined_person_distractor( $value, null, $full_name, $surname, $record_seed . '|author' );
			case 'year':
				return Citex_Reference_Rules::year_or_undated_distractor( $value, $record_seed . '|year' );
			case 'title':
				return Citex_Reference_Rules::title_like_distractor( $value, $fields['year'], $record_seed . '|title' );
			default:
				return $value . '?';
		}
	}
}

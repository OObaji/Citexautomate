<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Website DragDrop's dynamic 3-part question builder — replaces the fixed
 * named-design catalogue (Citex_Reference_Rules::website_dragdrop_designs()
 * and website_dragdrop_shape(), both still used by MCQ and left untouched)
 * with the same "always the full reference, N random blanks" model Book
 * DragDrop already uses (see Citex_Book_Dragdrop_Parts): the complete
 * reference — "Author/Org (Year) Title [online]. Publisher. Available
 * from: <URL> [accessed Date]." — is always rendered in full, and exactly
 * 3 of its 6 fields are seeded-randomly chosen as draggable blanks; every
 * other field stays visible as literal text.
 *
 * This is the simplest of the three new classes: Website has no
 * author-joining rule at all (always exactly one author-or-organisation —
 * see Citex_Reference_Rules::format_website_author()) and no structural
 * filler word like "and" — all 6 candidates are pure content, so
 * select_parts() is a straight seeded-shuffle-and-take-3 with no special
 * "content floor" logic needed.
 *
 * Every distractor is authored deterministically by Citex, from the
 * record's own fields, via Citex_Reference_Rules's "SHARED DETERMINISTIC
 * DISTRACTOR PRIMITIVES" section — never Gemini.
 *
 * Pure and static, no WordPress/ACF calls, exactly like
 * Citex_Reference_Rules and Citex_Book_Dragdrop_Parts — unit-testable
 * directly.
 */
class Citex_Website_Dragdrop_Parts {

	/**
	 * @return string[]
	 */
	private static function content_slots() {
		return array( 'author', 'year', 'title', 'publisher', 'url', 'accessed_date' );
	}

	/**
	 * Builds the full ordered token list for one Website record.
	 *
	 * @param array $author {type: 'individual'|'organisation', surname?, initials?, name?}.
	 * @param array $fields {year, title, publisher, url, accessedDate}.
	 * @return array<{key: string|null, kind: string, value: string, literal: bool}>
	 */
	public static function build_tokens( array $author, array $fields ) {
		return array(
			array( 'key' => 'author', 'kind' => 'author', 'value' => Citex_Reference_Rules::format_website_author( $author ), 'literal' => false ),
			array( 'key' => null, 'kind' => 'literal', 'value' => ' (', 'literal' => true ),
			array( 'key' => 'year', 'kind' => 'year', 'value' => (string) $fields['year'], 'literal' => false ),
			array( 'key' => null, 'kind' => 'literal', 'value' => ') ', 'literal' => true ),
			array( 'key' => 'title', 'kind' => 'title', 'value' => (string) $fields['title'], 'literal' => false ),
			array( 'key' => null, 'kind' => 'literal', 'value' => ' [online]. ', 'literal' => true ),
			array( 'key' => 'publisher', 'kind' => 'publisher', 'value' => (string) $fields['publisher'], 'literal' => false ),
			array( 'key' => null, 'kind' => 'literal', 'value' => '. Available from: <', 'literal' => true ),
			array( 'key' => 'url', 'kind' => 'url', 'value' => (string) $fields['url'], 'literal' => false ),
			array( 'key' => null, 'kind' => 'literal', 'value' => '> [accessed ', 'literal' => true ),
			array( 'key' => 'accessed_date', 'kind' => 'accessed_date', 'value' => (string) $fields['accessedDate'], 'literal' => false ),
			array( 'key' => null, 'kind' => 'literal', 'value' => '].', 'literal' => true ),
		);
	}

	/**
	 * Deterministically, but effectively unpredictably, picks exactly which
	 * 3 of the 6 candidate keys to draw for one question, seeded by that
	 * question's own id — a straight seeded shuffle-and-take-3 of all 6
	 * (every candidate is real content, so no special content-floor step is
	 * needed, unlike Book/Edited Book), returned in REFERENCE order.
	 *
	 * @param string|int $seed Typically the question's own id.
	 * @return string[] ordered candidate keys.
	 */
	public static function select_parts( $seed ) {
		$seed = (string) $seed;
		$pool = self::content_slots();
		usort(
			$pool,
			function ( $a, $b ) use ( $seed ) {
				$hash_a = crc32( 'website_dragdrop_shuffle|' . $seed . '|' . $a );
				$hash_b = crc32( 'website_dragdrop_shuffle|' . $seed . '|' . $b );
				return ( $hash_a <=> $hash_b ) ?: strcmp( $a, $b );
			}
		);
		$selected_set = array_fill_keys( array_slice( $pool, 0, 3 ), true );

		$empty_author = array( 'type' => 'individual', 'surname' => '', 'initials' => '' );
		$empty_fields = array( 'year' => '', 'title' => '', 'publisher' => '', 'url' => '', 'accessedDate' => '' );
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
	 * selection of candidate keys (as returned by select_parts(), or
	 * re-supplied by the validator from a stored `dragdropPartKeys` field)
	 * and the record's own canonical fields.
	 *
	 * @param string[] $selected_keys
	 * @param array    $author {type, surname?, initials?, name?, fullName?}.
	 * @param array    $fields {year, title, publisher, url, accessedDate}.
	 * @return array{parts: string[], fixedText: string, confusingWords: string[]}|null
	 *         null when $selected_keys selects nothing at all.
	 */
	public static function build( array $selected_keys, array $author, array $fields ) {
		$selected_set = array_fill_keys( array_map( 'strval', $selected_keys ), true );
		$tokens       = self::build_tokens( $author, $fields );
		$token_count  = count( $tokens );
		$record_seed  = implode( '|', array( (string) $fields['year'], (string) $fields['title'], (string) $fields['publisher'], (string) $fields['url'], (string) $fields['accessedDate'] ) );

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
	 * "kind" — every flavour here is a shared primitive from
	 * Citex_Reference_Rules's "SHARED DETERMINISTIC DISTRACTOR PRIMITIVES"
	 * section, never Gemini-authored — mirrors
	 * Citex_Reference_Rules::website_distractor_for_index()'s identical
	 * per-field mapping.
	 */
	private static function distractor_for( $kind, $value, array $author, array $fields, $record_seed ) {
		switch ( $kind ) {
			case 'author':
				if ( 'organisation' === ( $author['type'] ?? '' ) ) {
					$pick = Citex_Reference_Rules::pick_from_pool( Citex_Reference_Rules::organisation_pool(), array( $value ), $record_seed . '|author' );
					return null !== $pick ? $pick : 'Unknown Organisation';
				}
				$full_name = (string) ( $author['fullName'] ?? '' );
				$surname   = (string) ( $author['surname'] ?? '' );
				return Citex_Reference_Rules::combined_person_distractor( $value, null, $full_name, $surname, $record_seed . '|author' );
			case 'year':
				return Citex_Reference_Rules::year_or_undated_distractor( $value, $record_seed . '|year' );
			case 'title':
				return Citex_Reference_Rules::title_like_distractor( $value, $fields['year'], $record_seed . '|title' );
			case 'publisher':
				$pick = Citex_Reference_Rules::pick_from_pool( Citex_Reference_Rules::organisation_pool(), array( $value ), $record_seed . '|publisher' );
				return null !== $pick ? $pick : 'Unknown Organisation';
			case 'url':
				return Citex_Reference_Rules::url_distractor( $value, $record_seed . '|url' );
			case 'accessed_date':
				return Citex_Reference_Rules::date_distractor( $value, $record_seed . '|accessed' );
			default:
				return $value . '?';
		}
	}
}

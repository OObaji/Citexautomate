<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * MLA Website DragDrop's dynamic 3-part question builder — mirrors
 * Citex_Website_Dragdrop_Parts's own "always the full reference, N random
 * blanks, `url` always literal text" model, but for MLA's own Website rule
 * (see Citex_MLA_Reference_Rules's own docblock): the page title is
 * double-quoted, and — the single biggest structural difference — the
 * `year` segment is OMITTED ENTIRELY when no year can be identified (real
 * MLA 9 has no "n.d." convention at all), so `year` is only ever an
 * eligible draggable candidate when the record actually has one.
 *
 * When dated: 3 of {author, year, title, accessed_date} are drawn (`url`
 * never draggable, same rationale as Harvard's own class). When undated:
 * all 3 of {author, title, accessed_date} are drawn — there is no 4th
 * candidate to leave out, so no randomness is needed for that case.
 *
 * Pure and static, no WordPress/ACF calls — unit-testable directly.
 */
class Citex_MLA_Website_Dragdrop_Parts {

	/**
	 * @return string[]
	 *
	 * `url` is deliberately excluded — same rationale as
	 * Citex_Website_Dragdrop_Parts::content_slots()'s own docblock (a URL
	 * needs no MLA-format transformation, so drawing it as a blank is pure
	 * copy-paste recognition). `year` is included only when $has_year.
	 */
	private static function content_slots( $has_year ) {
		return $has_year
			? array( 'author', 'year', 'title', 'accessed_date' )
			: array( 'author', 'title', 'accessed_date' );
	}

	/**
	 * Builds the full ordered token list for one MLA Website record. The
	 * `year` token (and its trailing comma) is entirely omitted from the
	 * token stream when no year is present — not merely left blank —
	 * matching Citex_MLA_Reference_Rules::build_website_reference()'s own
	 * two-branch shape exactly.
	 *
	 * @param array $author {type: 'individual'|'organisation', surname?, givenName?, name?}.
	 * @param array $fields {year (string, '' when unknown), title, url, accessedDate}.
	 * @return array<{key: string|null, kind: string, value: string, literal: bool}>
	 */
	public static function build_tokens( array $author, array $fields ) {
		$has_year = '' !== trim( (string) ( $fields['year'] ?? '' ) );

		$tokens   = array();
		$tokens[] = array( 'key' => 'author', 'kind' => 'author', 'value' => Citex_MLA_Reference_Rules::format_website_author( $author ), 'literal' => false );
		$tokens[] = array( 'key' => null, 'kind' => 'literal', 'value' => ' "', 'literal' => true );
		$tokens[] = array( 'key' => 'title', 'kind' => 'title', 'value' => (string) $fields['title'], 'literal' => false );
		if ( $has_year ) {
			$tokens[] = array( 'key' => null, 'kind' => 'literal', 'value' => '." ', 'literal' => true );
			$tokens[] = array( 'key' => 'year', 'kind' => 'year', 'value' => (string) $fields['year'], 'literal' => false );
			$tokens[] = array( 'key' => null, 'kind' => 'literal', 'value' => ', ', 'literal' => true );
		} else {
			$tokens[] = array( 'key' => null, 'kind' => 'literal', 'value' => '." ', 'literal' => true );
		}
		$tokens[] = array( 'key' => 'url', 'kind' => 'url', 'value' => (string) $fields['url'], 'literal' => false );
		$tokens[] = array( 'key' => null, 'kind' => 'literal', 'value' => '. Accessed ', 'literal' => true );
		$tokens[] = array( 'key' => 'accessed_date', 'kind' => 'accessed_date', 'value' => (string) $fields['accessedDate'], 'literal' => false );
		$tokens[] = array( 'key' => null, 'kind' => 'literal', 'value' => '.', 'literal' => true );
		return $tokens;
	}

	/**
	 * Deterministically, but effectively unpredictably, picks exactly
	 * which 3 (dated) or all 3 (undated, no choice to make) eligible
	 * candidate keys to draw for one question, seeded by that question's
	 * own id — same seeded shuffle-and-take pattern as
	 * Citex_Website_Dragdrop_Parts::select_parts().
	 *
	 * @param string|int $seed     Typically the question's own id.
	 * @param bool       $has_year Whether this record has a real year at all.
	 * @return string[] ordered candidate keys.
	 */
	public static function select_parts( $seed, $has_year ) {
		$seed = (string) $seed;
		$pool = self::content_slots( $has_year );
		usort(
			$pool,
			function ( $a, $b ) use ( $seed ) {
				$hash_a = crc32( 'mla_website_dragdrop_shuffle|' . $seed . '|' . $a );
				$hash_b = crc32( 'mla_website_dragdrop_shuffle|' . $seed . '|' . $b );
				return ( $hash_a <=> $hash_b ) ?: strcmp( $a, $b );
			}
		);
		$selected_set = array_fill_keys( array_slice( $pool, 0, min( 3, count( $pool ) ) ), true );

		$empty_author = array( 'type' => 'individual', 'surname' => '', 'givenName' => '' );
		$empty_fields = array( 'year' => $has_year ? '2000' : '', 'title' => '', 'url' => '', 'accessedDate' => '' );
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
	 * @param array    $fields {year, title, url, accessedDate}.
	 * @return array{parts: string[], fixedText: string, confusingWords: string[]}|null
	 */
	public static function build( array $selected_keys, array $author, array $fields ) {
		$selected_set = array_fill_keys( array_map( 'strval', $selected_keys ), true );
		$tokens       = self::build_tokens( $author, $fields );
		$token_count  = count( $tokens );
		$record_seed  = implode( '|', array( (string) $fields['year'], (string) $fields['title'], (string) $fields['url'], (string) $fields['accessedDate'] ) );

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
	 * Harvard's (a name, a title, a URL, a date); `year` uses the plain
	 * year_distractor() (never year_or_undated_distractor()) since a
	 * `year` token here is only ever drawn when a real year exists — MLA
	 * has no "n.d." literal to distract against at all.
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
				return Citex_Reference_Rules::year_distractor( $value, $record_seed . '|year' );
			case 'title':
				return Citex_Reference_Rules::title_like_distractor( $value, $fields['year'], $record_seed . '|title' );
			case 'url':
				return Citex_Reference_Rules::url_distractor( $value, $record_seed . '|url' );
			case 'accessed_date':
				return Citex_Reference_Rules::date_distractor( $value, $record_seed . '|accessed' );
			default:
				return $value . '?';
		}
	}
}

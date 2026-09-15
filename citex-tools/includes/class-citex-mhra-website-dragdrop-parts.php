<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * MHRA Website DragDrop's question builder — for MHRA's own Website rule
 * (see Citex_MHRA_Reference_Rules's own docblock): a single-quoted page
 * title, angle brackets around the URL, square brackets around the
 * accessed date, and — the single biggest structural difference from
 * every other style's own Website format in this codebase — NO
 * publication-year field at all.
 *
 * Eligible content candidates: author, title, accessed_date — exactly 3,
 * so every question draws all 3 deterministically (no selection
 * randomness needed, the same "nothing left to leave out" situation as
 * APA/Chicago Website's own class). `url` carries a token key but is never
 * eligible for drawing — same rationale as every other style's own
 * Website class: a URL needs no style-format transformation, so dragging
 * it as a blank would be pure copy-paste recognition, not a formatting
 * test.
 *
 * Pure and static, no WordPress/ACF calls — unit-testable directly.
 */
class Citex_MHRA_Website_Dragdrop_Parts {

	/**
	 * @return string[] `url` deliberately excluded — see this class's own
	 * docblock.
	 */
	private static function content_slots() {
		return array( 'author', 'title', 'accessed_date' );
	}

	/**
	 * Builds the full ordered token list for one MHRA Website record.
	 *
	 * @param array $author {type: 'individual'|'organisation', surname?, givenName?, name?}.
	 * @param array $fields {title, url, accessedDate}.
	 * @return array<{key: string|null, kind: string, value: string, literal: bool}>
	 */
	public static function build_tokens( array $author, array $fields ) {
		return array(
			array( 'key' => 'author', 'kind' => 'author', 'value' => Citex_MHRA_Reference_Rules::format_website_author( $author ), 'literal' => false ),
			array( 'key' => null, 'kind' => 'literal', 'value' => ", '", 'literal' => true ),
			array( 'key' => 'title', 'kind' => 'title', 'value' => (string) $fields['title'], 'literal' => false ),
			array( 'key' => null, 'kind' => 'literal', 'value' => "', <", 'literal' => true ),
			array( 'key' => 'url', 'kind' => 'url', 'value' => (string) $fields['url'], 'literal' => false ),
			array( 'key' => null, 'kind' => 'literal', 'value' => '> [accessed ', 'literal' => true ),
			array( 'key' => 'accessed_date', 'kind' => 'accessed_date', 'value' => (string) $fields['accessedDate'], 'literal' => false ),
			array( 'key' => null, 'kind' => 'literal', 'value' => '].', 'literal' => true ),
		);
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
		$empty_fields = array( 'title' => '', 'url' => '', 'accessedDate' => '' );
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
	 * @param array    $fields {title, url, accessedDate}.
	 * @return array{parts: string[], fixedText: string, confusingWords: string[]}|null
	 */
	public static function build( array $selected_keys, array $author, array $fields ) {
		$selected_set = array_fill_keys( array_map( 'strval', $selected_keys ), true );
		$tokens       = self::build_tokens( $author, $fields );
		$token_count  = count( $tokens );
		$record_seed  = implode( '|', array( (string) $fields['title'], (string) $fields['url'], (string) $fields['accessedDate'] ) );

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
	 * Harvard's. `accessed_date` reuses the same distractor shape a
	 * genuine date string invites — a deterministic day/month shift.
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
			case 'title':
				return Citex_Reference_Rules::title_like_distractor( $value, '', $record_seed . '|title' );
			case 'accessed_date':
				return self::accessed_date_distractor( $value, $record_seed );
			default:
				return $value . '?';
		}
	}

	/**
	 * A deterministic wrong accessed date — shifts the leading day number
	 * by a small, seeded amount, keeping the month/year text intact, so the
	 * distractor reads as a genuinely plausible (but wrong) date rather
	 * than an obviously malformed string.
	 */
	private static function accessed_date_distractor( $value, $record_seed ) {
		if ( ! preg_match( '/^(\d{1,2})(\D.*)$/u', (string) $value, $m ) ) {
			return $value . '?';
		}
		$delta = 1 + ( abs( crc32( 'mhra_website_dragdrop_accessed_date|' . $record_seed ) ) % 9 );
		$day   = max( 1, min( 28, ( (int) $m[1] + $delta ) ) );
		return $day . $m[2];
	}
}

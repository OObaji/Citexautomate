<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Journal Article DragDrop's dynamic 3-part question builder — replaces the
 * fixed named-design catalogue (Citex_Reference_Rules::journal_article_dragdrop_designs()
 * and journal_article_dragdrop_shape(), both still used by MCQ and left
 * untouched) with the same "always the full reference, N random blanks"
 * model Book DragDrop already uses (see Citex_Book_Dragdrop_Parts): the
 * complete Cite Them Right reference —
 * "Author(s) (Year) 'Article Title', Journal Title, Volume(Issue), pp. Start–End."
 * — is always rendered in full, and exactly 3 of its fields are
 * seeded-randomly chosen as draggable blanks; every other field stays
 * visible as literal text. The old named designs each hid every field
 * except their own 3 entirely (e.g. "Journal, Volume(Issue)" alone, with no
 * author/year/title/pages at all) — this class never omits a field.
 *
 * Every distractor is authored deterministically by Citex, from the
 * record's own fields, via Citex_Reference_Rules's "SHARED DETERMINISTIC
 * DISTRACTOR PRIMITIVES" section — never Gemini, and never a value that
 * could be confused with a coincidence rather than a genuine
 * Harvard-referencing mistake.
 *
 * The reference is modelled as an ordered TOKEN STREAM (see build_tokens()),
 * alternating literal text (never draggable) and candidate slots (each with
 * a stable key, a "kind" for distractor generation, and its correct literal
 * value) — the exact same structure Citex_Book_Dragdrop_Parts uses.
 * select_parts() deterministically (crc32-seeded, same pattern as
 * Citex_Book_Mcq_Variants::variant_for()) decides which 3 candidates get
 * drawn for one question; build() turns that decision plus the record's own
 * fields into {parts, fixedText, confusingWords}, and can be re-run by the
 * validator from the STORED selection (dragdropPartKeys) to recompute and
 * exactly compare the whole question.
 *
 * Unlike Book, the drawn author's name is ONE combined "Surname, I."
 * candidate (never split into separate surname/initials parts) — matching
 * what this category has always done, and costing exactly 1 slot like
 * every other candidate here (Journal Article has 7 real content fields,
 * so — unlike Book — no special "seed content slot" is needed to guarantee
 * a content floor: with at most one structural "and" candidate ever in the
 * pool of up to 8, picking 3 can never land on fewer than 2 real content
 * fields).
 *
 * Pure and static, no WordPress/ACF calls, exactly like
 * Citex_Reference_Rules (which this class depends on for
 * format_page_range() and the shared distractor primitives) and
 * Citex_Book_Dragdrop_Parts — unit-testable directly.
 */
class Citex_Journal_Article_Dragdrop_Parts {

	/**
	 * Every content slot costs exactly 1 concrete part (unlike Book, where
	 * 'author_name' costs 2) — the drawn author's name is always ONE
	 * combined "Surname, I." candidate here.
	 *
	 * @return string[]
	 */
	private static function content_slots() {
		return array( 'author_name', 'year', 'title', 'journal', 'volume', 'issue', 'pages' );
	}

	/**
	 * Builds the full ordered token list for one Journal Article record —
	 * every candidate slot that COULD be drawn, plus every literal
	 * character between them, in the exact order they appear in the final
	 * Cite Them Right reference. $drawn_author_index picks which single
	 * author's combined "Surname, I." is represented as a candidate token
	 * instead of literal text.
	 *
	 * @param array $authors             array<{surname, initials}>, 1 or more.
	 * @param array $fields              {year, articleTitle, journalTitle, volume, issue, pages}.
	 * @param int   $drawn_author_index
	 * @return array<{key: string|null, kind: string, value: string, literal: bool}>
	 */
	public static function build_tokens( array $authors, array $fields, $drawn_author_index = 0 ) {
		$tokens = array();
		$count  = count( $authors );
		for ( $i = 0; $i < $count; $i++ ) {
			$combined = sprintf( '%s, %s', $authors[ $i ]['surname'], $authors[ $i ]['initials'] );
			if ( $i === $drawn_author_index ) {
				$tokens[] = array( 'key' => 'author_' . $i, 'kind' => 'author_name', 'value' => $combined, 'literal' => false );
			} else {
				$tokens[] = array( 'key' => null, 'kind' => 'literal', 'value' => $combined, 'literal' => true );
			}
			if ( $i < $count - 1 ) {
				if ( $i === $count - 2 ) {
					$tokens[] = array( 'key' => null, 'kind' => 'literal', 'value' => ' ', 'literal' => true );
					$tokens[] = array( 'key' => 'and', 'kind' => 'and', 'value' => 'and', 'literal' => false );
					$tokens[] = array( 'key' => null, 'kind' => 'literal', 'value' => ' ', 'literal' => true );
				} else {
					$tokens[] = array( 'key' => null, 'kind' => 'literal', 'value' => ', ', 'literal' => true );
				}
			}
		}
		$tokens[] = array( 'key' => null, 'kind' => 'literal', 'value' => ' (', 'literal' => true );
		$tokens[] = array( 'key' => 'year', 'kind' => 'year', 'value' => (string) $fields['year'], 'literal' => false );
		$tokens[] = array( 'key' => null, 'kind' => 'literal', 'value' => ') ‘', 'literal' => true );
		$tokens[] = array( 'key' => 'title', 'kind' => 'title', 'value' => (string) $fields['articleTitle'], 'literal' => false );
		$tokens[] = array( 'key' => null, 'kind' => 'literal', 'value' => '’, ', 'literal' => true );
		$tokens[] = array( 'key' => 'journal', 'kind' => 'journal', 'value' => (string) $fields['journalTitle'], 'literal' => false );
		$tokens[] = array( 'key' => null, 'kind' => 'literal', 'value' => ', ', 'literal' => true );
		$tokens[] = array( 'key' => 'volume', 'kind' => 'volume', 'value' => (string) $fields['volume'], 'literal' => false );
		$tokens[] = array( 'key' => null, 'kind' => 'literal', 'value' => '(', 'literal' => true );
		$tokens[] = array( 'key' => 'issue', 'kind' => 'issue', 'value' => (string) $fields['issue'], 'literal' => false );
		$tokens[] = array( 'key' => null, 'kind' => 'literal', 'value' => '), pp. ', 'literal' => true );
		$tokens[] = array( 'key' => 'pages', 'kind' => 'pages', 'value' => Citex_Reference_Rules::format_page_range( (string) $fields['pages'] ), 'literal' => false );
		$tokens[] = array( 'key' => null, 'kind' => 'literal', 'value' => '.', 'literal' => true );
		return $tokens;
	}

	/**
	 * Deterministically, but effectively unpredictably, picks exactly which
	 * candidate keys to draw for one question, seeded by that question's
	 * own id (same crc32-seeding pattern as
	 * Citex_Book_Dragdrop_Parts::select_parts()):
	 * 1. Pick a drawn author index (any of count($authors), uniformly).
	 * 2. The target part count is always exactly 3.
	 * 3. Shuffle the full candidate pool (7 content slots, plus 'and' when
	 *    2+ authors) deterministically and take the first 3 — no separate
	 *    "seed content slot" step is needed here (see this class's own
	 *    docblock for why the content floor is automatic by pigeonhole).
	 * 4. Return the selected keys in REFERENCE order (not selection order),
	 *    by walking build_tokens()'s own output.
	 *
	 * @param string|int $seed    Typically the question's own id.
	 * @param array      $authors array<{surname, initials}>, 1 or more.
	 * @return string[] ordered candidate keys.
	 */
	public static function select_parts( $seed, array $authors ) {
		$seed         = (string) $seed;
		$author_count = count( $authors );
		$drawn_index  = abs( crc32( 'journal_article_dragdrop_author|' . $seed ) ) % max( 1, $author_count );
		$target_count = 3;

		$pool = self::content_slots();
		if ( $author_count >= 2 ) {
			$pool[] = 'and';
		}
		usort(
			$pool,
			function ( $a, $b ) use ( $seed ) {
				$hash_a = crc32( 'journal_article_dragdrop_shuffle|' . $seed . '|' . $a );
				$hash_b = crc32( 'journal_article_dragdrop_shuffle|' . $seed . '|' . $b );
				return ( $hash_a <=> $hash_b ) ?: strcmp( $a, $b );
			}
		);
		$selected_abstract = array_slice( $pool, 0, $target_count );

		$selected_concrete = array();
		foreach ( $selected_abstract as $slot ) {
			$selected_concrete[] = ( 'author_name' === $slot ) ? ( 'author_' . $drawn_index ) : $slot;
		}
		$selected_set = array_fill_keys( $selected_concrete, true );

		$empty_fields = array( 'year' => '', 'articleTitle' => '', 'journalTitle' => '', 'volume' => '', 'issue' => '', 'pages' => '' );
		$ordered      = array();
		foreach ( self::build_tokens( $authors, $empty_fields, $drawn_index ) as $token ) {
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
	 * and the record's own canonical fields. The drawn author index is
	 * recovered directly from $selected_keys (an "author_N" key names it)
	 * rather than needing to be passed separately.
	 *
	 * @param string[] $selected_keys
	 * @param array    $authors array<{surname, initials, fullName}>, 1 or more.
	 * @param array    $fields  {year, articleTitle, journalTitle, volume, issue, pages}.
	 * @return array{parts: string[], fixedText: string, confusingWords: string[]}|null
	 *         null when $selected_keys names an author index out of range
	 *         for $authors, or selects nothing at all.
	 */
	public static function build( array $selected_keys, array $authors, array $fields ) {
		$drawn_index = 0;
		foreach ( $selected_keys as $key ) {
			if ( 1 === preg_match( '/^author_(\d+)$/', (string) $key, $matches ) ) {
				$drawn_index = (int) $matches[1];
				break;
			}
		}
		if ( $drawn_index >= count( $authors ) ) {
			return null;
		}

		$selected_set = array_fill_keys( array_map( 'strval', $selected_keys ), true );
		$tokens       = self::build_tokens( $authors, $fields, $drawn_index );
		$token_count  = count( $tokens );
		$full_name    = isset( $authors[ $drawn_index ]['fullName'] ) ? (string) $authors[ $drawn_index ]['fullName'] : '';
		$surname      = (string) $authors[ $drawn_index ]['surname'];
		$other        = self::other_author( $authors, $drawn_index );
		$other_combined = null !== $other ? sprintf( '%s, %s', $other['surname'], $other['initials'] ) : null;
		$record_seed  = implode( '|', array( (string) $fields['year'], (string) $fields['articleTitle'], (string) $fields['journalTitle'], (string) $fields['volume'], (string) $fields['issue'], (string) $fields['pages'] ) );

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
				$confusing[] = self::distractor_for( $token['kind'], $token['value'], $full_name, $surname, $other_combined, $fields, $record_seed );
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
	 * section, never Gemini-authored.
	 */
	private static function distractor_for( $kind, $value, $full_name, $surname, $other_combined, array $fields, $record_seed ) {
		switch ( $kind ) {
			case 'author_name':
				return Citex_Reference_Rules::combined_person_distractor( $value, $other_combined, $full_name, $surname, $record_seed . '|author' );
			case 'and':
				// Tests "authors are joined with 'and', never '&'".
				return '&';
			case 'year':
				return Citex_Reference_Rules::year_distractor( $value, $record_seed . '|year' );
			case 'title':
				return Citex_Reference_Rules::title_like_distractor( $value, $fields['year'], $record_seed . '|title' );
			case 'journal':
				$pick = Citex_Reference_Rules::pick_from_pool( Citex_Reference_Rules::journal_pool(), array( $value ), $record_seed . '|journal' );
				return null !== $pick ? $pick : 'n.j.';
			case 'volume':
				return Citex_Reference_Rules::small_integer_distractor( $value, $record_seed . '|volume' );
			case 'issue':
				return Citex_Reference_Rules::small_integer_distractor( $value, $record_seed . '|issue' );
			case 'pages':
				$raw_distractor = Citex_Reference_Rules::page_range_distractor( (string) $fields['pages'], $record_seed . '|pages' );
				return Citex_Reference_Rules::format_page_range( $raw_distractor );
			default:
				return $value . '?';
		}
	}

	/**
	 * The first author in $authors that is NOT the drawn author, or null
	 * for a single-author record.
	 */
	private static function other_author( array $authors, $drawn_index ) {
		foreach ( $authors as $i => $author ) {
			if ( $i !== $drawn_index ) {
				return $author;
			}
		}
		return null;
	}
}

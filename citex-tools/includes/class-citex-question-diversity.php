<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Question-diversity tracking and selection: remembers which scenarios
 * (Citex_Question_Scenarios) have recently been generated per category, so
 * a new generation batch is steered toward whichever scenario has been
 * tested least recently, instead of the generator always defaulting to the
 * same one (e.g. always "one author"). Also flags an outright duplicate
 * real-world book/reference, the one concrete, checkable form of "too
 * similar to recent history" this increment enforces.
 *
 * History is recorded once per successful generation batch — independent
 * of the pending/populate lifecycle, so it is never lost when a question
 * is populated into WordPress and leaves the pending queue (unlike
 * Citex_Populator::get_population_coverage(), which only tracks
 * Category x Exercise x Type counts, not scenario/rule detail).
 */
class Citex_Question_Diversity {

	const OPTION_HISTORY = 'citex_question_scenario_history';

	/**
	 * How many of the most recent entries, per category, are kept and
	 * consulted for "least recently tested" selection. Old enough to see
	 * real imbalance across a handful of generation batches, small enough
	 * that the option never grows unbounded.
	 */
	const HISTORY_LIMIT_PER_CATEGORY = 60;

	public static function get_history( $category ) {
		$history = get_option( self::OPTION_HISTORY, array() );
		if ( ! is_array( $history ) || ! isset( $history[ $category ] ) || ! is_array( $history[ $category ] ) ) {
			return array();
		}
		return array_values( $history[ $category ] );
	}

	/**
	 * Records one blueprint per generated question, appended to that
	 * category's rolling history and capped at HISTORY_LIMIT_PER_CATEGORY
	 * (oldest entries drop off first).
	 *
	 * @param string  $category
	 * @param array[] $blueprints Each: {scenario, ruleTested, questionType}.
	 */
	public static function record_batch( $category, array $blueprints ) {
		if ( empty( $blueprints ) ) {
			return;
		}
		$history = get_option( self::OPTION_HISTORY, array() );
		if ( ! is_array( $history ) ) {
			$history = array();
		}
		if ( ! isset( $history[ $category ] ) || ! is_array( $history[ $category ] ) ) {
			$history[ $category ] = array();
		}
		foreach ( $blueprints as $blueprint ) {
			$history[ $category ][] = array(
				'scenario'     => (string) ( $blueprint['scenario'] ?? '' ),
				'ruleTested'   => (string) ( $blueprint['ruleTested'] ?? '' ),
				'questionType' => (string) ( $blueprint['questionType'] ?? '' ),
				'recordedAt'   => gmdate( 'c' ),
			);
		}
		if ( count( $history[ $category ] ) > self::HISTORY_LIMIT_PER_CATEGORY ) {
			$history[ $category ] = array_slice( $history[ $category ], -self::HISTORY_LIMIT_PER_CATEGORY );
		}
		update_option( self::OPTION_HISTORY, $history, false );
	}

	/**
	 * Greedy least-recently-tested-first scenario assignment for $quantity
	 * generation slots — the exact same algorithm shape as
	 * Citex_Generator::build_exercise_assignments(), just diversifying
	 * scenario instead of exercise. Ties (including "never tested before",
	 * count 0) break in catalog order, and the running count is
	 * decremented within this call too, so a single batch naturally spreads
	 * across every scenario instead of concentrating on whichever was
	 * least-used at the start.
	 *
	 * @return string[] Scenario id for each of the $quantity slots, in order.
	 */
	public static function assign_scenarios( $category, $question_type, $quantity ) {
		$scenarios = Citex_Question_Scenarios::catalog( $category, $question_type );
		if ( empty( $scenarios ) ) {
			return array_fill( 0, max( 0, (int) $quantity ), null );
		}

		$counts = array();
		foreach ( $scenarios as $scenario ) {
			$counts[ $scenario['id'] ] = 0;
		}
		foreach ( self::get_history( $category ) as $entry ) {
			$id = (string) ( $entry['scenario'] ?? '' );
			if ( $question_type === (string) ( $entry['questionType'] ?? '' ) && isset( $counts[ $id ] ) ) {
				$counts[ $id ]++;
			}
		}

		$assignments = array();
		for ( $i = 0; $i < $quantity; $i++ ) {
			$lowest_id    = $scenarios[0]['id'];
			$lowest_count = $counts[ $lowest_id ];
			foreach ( $scenarios as $scenario ) {
				if ( $counts[ $scenario['id'] ] < $lowest_count ) {
					$lowest_id    = $scenario['id'];
					$lowest_count = $counts[ $scenario['id'] ];
				}
			}
			$assignments[] = $lowest_id;
			$counts[ $lowest_id ]++;
		}
		return $assignments;
	}

	/**
	 * Splits $quantity as evenly as possible across every scenario bucket
	 * in this category+type's own catalog, considering ONLY this one
	 * batch — never cross-batch history. This is deliberately different
	 * from assign_scenarios() above: that method starts from each
	 * bucket's own historical count and always fills the currently-lowest
	 * one first, which balances out over MANY batches but can leave a
	 * SINGLE batch entirely lopsided (e.g. every one of 10 new questions
	 * landing in the same author-count bucket) whenever history already
	 * happens to be skewed — a reported real complaint ("too many of a
	 * particular author count" in one batch). This method never reads
	 * history at all, so a batch of 10 across 4 buckets always comes out
	 * 3/3/2/2 (or all-equal buckets when $quantity divides evenly), no
	 * matter what earlier batches looked like — the round-robin cycle
	 * gives the extra remainder slots to the first buckets, in catalog
	 * order.
	 *
	 * @return string[] Scenario id for each of the $quantity slots, in order.
	 */
	public static function assign_scenarios_equally( $category, $question_type, $quantity ) {
		$scenarios = Citex_Question_Scenarios::catalog( $category, $question_type );
		if ( empty( $scenarios ) ) {
			return array_fill( 0, max( 0, (int) $quantity ), null );
		}

		$ids = array();
		foreach ( $scenarios as $scenario ) {
			$ids[] = $scenario['id'];
		}

		$assignments = array();
		for ( $i = 0; $i < $quantity; $i++ ) {
			$assignments[] = $ids[ $i % count( $ids ) ];
		}
		return $assignments;
	}

	/**
	 * True when $reference already appears (case-insensitively,
	 * whitespace-normalised) among $existing_references — the concrete,
	 * checkable "too similar" case this increment guards against: Gemini
	 * regenerating the exact same real book a previous batch already used,
	 * which would put two questions testing the exact same underlying
	 * reference into the bank regardless of how different their scenario
	 * wording looks.
	 *
	 * @param string   $reference
	 * @param string[] $existing_references
	 */
	public static function is_duplicate_reference( $reference, array $existing_references ) {
		$normal = self::normalise_reference( $reference );
		if ( '' === $normal ) {
			return false;
		}
		foreach ( $existing_references as $existing ) {
			if ( $normal === self::normalise_reference( $existing ) ) {
				return true;
			}
		}
		return false;
	}

	private static function normalise_reference( $reference ) {
		$reference = strtolower( trim( (string) $reference ) );
		return trim( preg_replace( '/\s+/', ' ', $reference ) );
	}
}

<?php
/**
 * A minimal, dependency-free QR Code (Model 2) encoder.
 *
 * Byte mode only, error correction level M, smallest version that fits the
 * payload. That is deliberately narrow: every use in this plugin encodes a
 * fixed-shape ASCII verification URL, so there is no need for numeric or
 * alphanumeric mode, ECI, structured append, or anything else the full
 * specification covers. No PHP extension is required to build the module
 * matrix; rendering it as PNG needs GD, rendering it as SVG needs nothing.
 *
 * @package EventTicketManager
 */

defined( 'ABSPATH' ) || exit;

/**
 * Encodes a byte string into a QR Code module matrix (ISO/IEC 18004).
 */
class ETM_QR_Encoder {

	/**
	 * Error correction codewords per block, indexed by [version][ec_level].
	 * Level M only (index 1 of the four standard levels: L, M, Q, H), for
	 * versions 1-10 - enough for a URL several hundred characters long.
	 *
	 * @var array<int, array{total: int, ec_per_block: int, group1_blocks: int, group1_words: int, group2_blocks: int, group2_words: int}>
	 */
	private const VERSIONS = array(
		1  => array( 'total' => 26, 'ec' => 10, 'g1b' => 1, 'g1w' => 16, 'g2b' => 0, 'g2w' => 0 ),
		2  => array( 'total' => 44, 'ec' => 16, 'g1b' => 1, 'g1w' => 28, 'g2b' => 0, 'g2w' => 0 ),
		3  => array( 'total' => 70, 'ec' => 26, 'g1b' => 1, 'g1w' => 44, 'g2b' => 0, 'g2w' => 0 ),
		4  => array( 'total' => 100, 'ec' => 18, 'g1b' => 2, 'g1w' => 32, 'g2b' => 0, 'g2w' => 0 ),
		5  => array( 'total' => 134, 'ec' => 24, 'g1b' => 2, 'g1w' => 43, 'g2b' => 0, 'g2w' => 0 ),
		6  => array( 'total' => 172, 'ec' => 16, 'g1b' => 4, 'g1w' => 27, 'g2b' => 0, 'g2w' => 0 ),
		7  => array( 'total' => 196, 'ec' => 18, 'g1b' => 4, 'g1w' => 31, 'g2b' => 0, 'g2w' => 0 ),
		8  => array( 'total' => 242, 'ec' => 22, 'g1b' => 2, 'g1w' => 38, 'g2b' => 2, 'g2w' => 39 ),
		9  => array( 'total' => 292, 'ec' => 22, 'g1b' => 3, 'g1w' => 36, 'g2b' => 2, 'g2w' => 37 ),
		10 => array( 'total' => 346, 'ec' => 26, 'g1b' => 4, 'g1w' => 43, 'g2b' => 1, 'g2w' => 44 ),
	);

	/**
	 * Alignment pattern centre coordinates by version (empty below version 2).
	 *
	 * @var array<int, int[]>
	 */
	private const ALIGNMENT = array(
		2  => array( 6, 18 ),
		3  => array( 6, 22 ),
		4  => array( 6, 26 ),
		5  => array( 6, 30 ),
		6  => array( 6, 34 ),
		7  => array( 6, 22, 38 ),
		8  => array( 6, 24, 42 ),
		9  => array( 6, 26, 46 ),
		10 => array( 6, 28, 50 ),
	);

	/**
	 * Encode a string into a square module matrix.
	 *
	 * @param string $text Payload. ASCII is assumed - this plugin only ever
	 *                      feeds it a URL.
	 * @return bool[][]|null Rows of true (dark) / false (light) modules, or
	 *                        null when the payload is too long for the largest
	 *                        version this class supports.
	 */
	public static function encode( string $text ): ?array {
		$bytes = array_values( unpack( 'C*', $text ) ?: array() );
		$version = null;

		foreach ( self::VERSIONS as $v => $spec ) {
			// The symbol's DATA capacity, not its total codeword count: 'total'
			// includes the error-correction codewords appended after data, which
			// are computed separately by interleave_with_ec() and are never
			// payload. Sizing against 'total' instead of this would silently
			// truncate the payload wherever a byte fell past the data region -
			// exactly this class's original bug.
			$data_words = $spec['g1b'] * $spec['g1w'] + $spec['g2b'] * $spec['g2w'];

			// Byte mode's character count indicator is 8 bits for versions
			// 1-9 but 16 bits for versions 10-40 (ISO/IEC 18004 Table 3) - the
			// header is 4 (mode) + that many bits, rounded up to whole bytes.
			$count_bits  = $v < 10 ? 8 : 16;
			$header_bytes = (int) ceil( ( 4 + $count_bits ) / 8 );

			if ( count( $bytes ) <= $data_words - $header_bytes ) {
				$version = $v;
				break;
			}
		}

		if ( null === $version ) {
			return null;
		}

		$spec = self::VERSIONS[ $version ];
		$data_codewords = self::build_data_codewords( $bytes, $spec, $version );
		$ec_codewords   = self::interleave_with_ec( $data_codewords, $spec );

		$size = 17 + 4 * $version;

		$best_mask  = 0;
		$best_score = null;
		$best_modules = null;

		// Every candidate mask is built as a complete, independent matrix (one
		// single array per attempt, module = null until something claims it),
		// then scored; the lowest-penalty candidate's finished matrix is kept.
		// A shared "is this cell taken yet" array is what earlier, hand-split
		// matrix/reserved-map versions of this class kept losing sync on.
		for ( $mask = 0; $mask < 8; $mask++ ) {
			$modules = self::build_matrix( $version, $size, $ec_codewords, $mask );
			$score   = self::penalty_score( $modules, $size );

			if ( null === $best_score || $score < $best_score ) {
				$best_score   = $score;
				$best_mask    = $mask;
				$best_modules = $modules;
			}
		}

		return $best_modules;
	}

	/**
	 * Build one complete candidate matrix for a specific mask: every function
	 * pattern, the format info for that mask, and the data codewords placed
	 * and masked in the zig-zag order the specification defines.
	 *
	 * @param int   $version    QR version.
	 * @param int   $size       Symbol size in modules.
	 * @param int[] $codewords  Final interleaved codeword sequence.
	 * @param int   $mask       Mask pattern to build this candidate with.
	 * @return bool[][]
	 */
	private static function build_matrix( int $version, int $size, array $codewords, int $mask ): array {
		// null = unclaimed. Every placement step below only ever writes a
		// module once; there is no second array whose bookkeeping could drift
		// out of step with this one.
		$modules = array_fill( 0, $size, array_fill( 0, $size, null ) );

		self::place_finder_pattern( $modules, 0, 0, $size );
		self::place_finder_pattern( $modules, $size - 7, 0, $size );
		self::place_finder_pattern( $modules, 0, $size - 7, $size );
		self::place_alignment_patterns( $modules, $version, $size );
		self::place_timing_patterns( $modules, $size );
		self::place_format_info( $modules, $size, $mask );
		self::place_version_info( $modules, $version, $size );

		// The one dark module every version 1+ symbol always carries.
		$modules[ $size - 8 ][8] = true;

		self::place_data( $modules, $codewords, $mask, $size );

		// Anything a short payload left untouched (impossible in practice,
		// since capacity is sized to fill exactly) reads as light rather than
		// leaving a null in the final matrix.
		for ( $y = 0; $y < $size; $y++ ) {
			for ( $x = 0; $x < $size; $x++ ) {
				if ( null === $modules[ $y ][ $x ] ) {
					$modules[ $y ][ $x ] = false;
				}
			}
		}

		return $modules;
	}

	/**
	 * Mode indicator + length + payload, padded to fill every data codeword.
	 *
	 * @param int[]                $bytes Payload bytes.
	 * @param array<string, int>   $spec  Version spec.
	 * @return int[] One byte (0-255) per data codeword.
	 */
	private static function build_data_codewords( array $bytes, array $spec, int $version ): array {
		$bits = '0100'; // Byte mode.
		$count_bits = $version < 10 ? 8 : 16;
		$bits .= str_pad( decbin( count( $bytes ) ), $count_bits, '0', STR_PAD_LEFT );

		foreach ( $bytes as $byte ) {
			$bits .= str_pad( decbin( $byte ), 8, '0', STR_PAD_LEFT );
		}

		// Fill exactly the DATA region - 'total' also counts the EC codewords
		// appended afterwards, which must stay untouched by payload or padding.
		$data_words = $spec['g1b'] * $spec['g1w'] + $spec['g2b'] * $spec['g2w'];
		$total_bits = $data_words * 8;

		// Terminator: up to 4 zero bits, only as many as fit.
		$bits .= str_repeat( '0', min( 4, $total_bits - strlen( $bits ) ) );

		// Pad to a whole codeword.
		while ( strlen( $bits ) % 8 !== 0 ) {
			$bits .= '0';
		}

		$codewords = array();

		foreach ( str_split( $bits, 8 ) as $byte_bits ) {
			$codewords[] = bindec( $byte_bits );
		}

		// Pad codewords, alternating the two standard filler bytes.
		$fillers = array( 0xEC, 0x11 );
		$i = 0;

		while ( count( $codewords ) < $data_words ) {
			$codewords[] = $fillers[ $i % 2 ];
			++$i;
		}

		return $codewords;
	}

	/**
	 * Split into blocks, compute Reed-Solomon EC codewords per block, then
	 * interleave data and EC codewords per the specification's final ordering.
	 *
	 * @param int[]              $data Data codewords for the whole symbol.
	 * @param array<string, int> $spec Version spec.
	 * @return int[] Final codeword sequence, ready to place into the matrix.
	 */
	private static function interleave_with_ec( array $data, array $spec ): array {
		$blocks    = array();
		$ec_blocks = array();
		$offset    = 0;

		$groups = array(
			array( $spec['g1b'], $spec['g1w'] ),
			array( $spec['g2b'], $spec['g2w'] ),
		);

		foreach ( $groups as $group ) {
			list( $count, $words ) = $group;

			for ( $i = 0; $i < $count; $i++ ) {
				$block = array_slice( $data, $offset, $words );
				$offset += $words;

				$blocks[]    = $block;
				$ec_blocks[] = self::reed_solomon_ec( $block, $spec['ec'] );
			}
		}

		$result   = array();
		$max_data = max( array_map( 'count', $blocks ) );

		for ( $i = 0; $i < $max_data; $i++ ) {
			foreach ( $blocks as $block ) {
				if ( isset( $block[ $i ] ) ) {
					$result[] = $block[ $i ];
				}
			}
		}

		for ( $i = 0; $i < $spec['ec']; $i++ ) {
			foreach ( $ec_blocks as $ec_block ) {
				$result[] = $ec_block[ $i ];
			}
		}

		return $result;
	}

	/**
	 * Reed-Solomon error correction codewords over GF(256), using the
	 * generator polynomial with primitive 0x11D that the QR spec requires.
	 *
	 * @param int[] $data      Data codewords for one block.
	 * @param int   $ec_length How many EC codewords to produce.
	 * @return int[]
	 */
	private static function reed_solomon_ec( array $data, int $ec_length ): array {
		list( $exp, $log ) = self::gf256_tables();

		$generator = array( 1 );

		for ( $i = 0; $i < $ec_length; $i++ ) {
			$next = array_fill( 0, count( $generator ) + 1, 0 );

			for ( $j = 0; $j < count( $generator ); $j++ ) {
				$next[ $j ] ^= self::gf_mul( $generator[ $j ], 1, $exp, $log );
				$next[ $j + 1 ] ^= self::gf_mul( $generator[ $j ], $exp[ $i ], $exp, $log );
			}

			$generator = $next;
		}

		$remainder = array_merge( $data, array_fill( 0, $ec_length, 0 ) );

		for ( $i = 0; $i < count( $data ); $i++ ) {
			$coefficient = $remainder[ $i ];

			if ( 0 === $coefficient ) {
				continue;
			}

			for ( $j = 0; $j < count( $generator ); $j++ ) {
				$remainder[ $i + $j ] ^= self::gf_mul( $generator[ $j ], $coefficient, $exp, $log );
			}
		}

		return array_slice( $remainder, count( $data ), $ec_length );
	}

	/**
	 * GF(256) exp/log tables for primitive polynomial x^8+x^4+x^3+x^2+1 (0x11D).
	 *
	 * @return array{0: int[], 1: int[]}
	 */
	private static function gf256_tables(): array {
		static $cache = null;

		if ( null !== $cache ) {
			return $cache;
		}

		$exp = array_fill( 0, 512, 0 );
		$log = array_fill( 0, 256, 0 );
		$x   = 1;

		for ( $i = 0; $i < 255; $i++ ) {
			$exp[ $i ] = $x;
			$log[ $x ] = $i;
			$x <<= 1;

			if ( $x & 0x100 ) {
				$x ^= 0x11D;
			}
		}

		for ( $i = 255; $i < 512; $i++ ) {
			$exp[ $i ] = $exp[ $i - 255 ];
		}

		$cache = array( $exp, $log );

		return $cache;
	}

	/**
	 * Multiply two GF(256) elements.
	 *
	 * @param int   $a   First value.
	 * @param int   $b   Second value.
	 * @param int[] $exp Exponent table.
	 * @param int[] $log Log table.
	 * @return int
	 */
	private static function gf_mul( int $a, int $b, array $exp, array $log ): int {
		if ( 0 === $a || 0 === $b ) {
			return 0;
		}

		return $exp[ $log[ $a ] + $log[ $b ] ];
	}

	/**
	 * Draw one 7x7 finder pattern with its separator, top-left corner at (x,y).
	 *
	 * Ported directly from the reference algorithm's setupPositionProbePattern:
	 * unconditional writes (finder patterns are always placed first, onto an
	 * empty matrix, so nothing to defer to yet), clipped at the symbol edge.
	 *
	 * @param bool[][] $modules Module matrix, by reference. null = unclaimed.
	 * @param int      $x       Column of the pattern's top-left module.
	 * @param int      $y       Row of the pattern's top-left module.
	 * @param int      $size    Symbol size in modules.
	 */
	private static function place_finder_pattern( array &$modules, int $x, int $y, int $size ): void {
		for ( $r = -1; $r <= 7; $r++ ) {
			if ( $y + $r <= -1 || $size <= $y + $r ) {
				continue;
			}

			for ( $c = -1; $c <= 7; $c++ ) {
				if ( $x + $c <= -1 || $size <= $x + $c ) {
					continue;
				}

				$dark = ( 0 <= $r && $r <= 6 && ( 0 === $c || 6 === $c ) )
					|| ( 0 <= $c && $c <= 6 && ( 0 === $r || 6 === $r ) )
					|| ( 2 <= $r && $r <= 4 && 2 <= $c && $c <= 4 );

				$modules[ $y + $r ][ $x + $c ] = $dark;
			}
		}
	}

	/**
	 * The two timing pattern lines (row 6 and column 6), alternating modules.
	 *
	 * Deferring to whatever is already there (rather than overwriting) matters
	 * for versions 7+, where an alignment pattern can legitimately overlap
	 * this line - alignment is placed first, exactly as the reference does it.
	 *
	 * @param bool[][] $modules Module matrix, by reference.
	 * @param int      $size    Symbol size in modules.
	 */
	private static function place_timing_patterns( array &$modules, int $size ): void {
		for ( $r = 8; $r < $size - 8; $r++ ) {
			if ( null === $modules[ $r ][6] ) {
				$modules[ $r ][6] = ( 0 === $r % 2 );
			}
		}

		for ( $c = 8; $c < $size - 8; $c++ ) {
			if ( null === $modules[6][ $c ] ) {
				$modules[6][ $c ] = ( 0 === $c % 2 );
			}
		}
	}

	/**
	 * Every alignment pattern for this version, skipping one whose centre
	 * already belongs to a finder pattern.
	 *
	 * @param bool[][] $modules Module matrix, by reference.
	 * @param int      $version QR version (1-10 for this class).
	 * @param int      $size    Symbol size in modules.
	 */
	private static function place_alignment_patterns( array &$modules, int $version, int $size ): void {
		if ( ! isset( self::ALIGNMENT[ $version ] ) ) {
			return;
		}

		$positions = self::ALIGNMENT[ $version ];

		foreach ( $positions as $row ) {
			foreach ( $positions as $col ) {
				// A centre that is already set belongs to a finder pattern -
				// checked by position, not by a hard-coded corner list, so this
				// also does the right thing for the larger versions where
				// alignment centres can coincide with the timing line too.
				if ( null !== $modules[ $row ][ $col ] ) {
					continue;
				}

				for ( $r = -2; $r <= 2; $r++ ) {
					for ( $c = -2; $c <= 2; $c++ ) {
						$dark = ( -2 === $r || 2 === $r || -2 === $c || 2 === $c || ( 0 === $r && 0 === $c ) );

						$modules[ $row + $r ][ $col + $c ] = $dark;
					}
				}
			}
		}
	}

	/**
	 * Write the 15-bit format info string (EC level M, the given mask) around
	 * the finder patterns.
	 *
	 * @param bool[][] $modules Module matrix, by reference.
	 * @param int      $size    Symbol size in modules.
	 * @param int      $mask    Mask pattern (0-7).
	 */
	private static function place_format_info( array &$modules, int $size, int $mask ): void {
		$bits = self::format_info_bits( $mask );

		for ( $i = 0; $i < 15; $i++ ) {
			$mod = (bool) ( ( $bits >> $i ) & 1 );

			if ( $i < 6 ) {
				$modules[ $i ][8] = $mod;
			} elseif ( $i < 8 ) {
				$modules[ $i + 1 ][8] = $mod;
			} else {
				$modules[ $size - 15 + $i ][8] = $mod;
			}
		}

		for ( $i = 0; $i < 15; $i++ ) {
			$mod = (bool) ( ( $bits >> $i ) & 1 );

			if ( $i < 8 ) {
				$modules[8][ $size - $i - 1 ] = $mod;
			} elseif ( $i < 9 ) {
				$modules[8][ 15 - $i - 1 + 1 ] = $mod;
			} else {
				$modules[8][ 15 - $i - 1 ] = $mod;
			}
		}
	}

	/**
	 * Compute the 15-bit format info value for EC level M and a given mask,
	 * per the specification's own BCH(15,5) definition - the 5 data bits (2
	 * for EC level, 3 for mask) protected by a 10-bit remainder from division
	 * by generator polynomial x^10+x^8+x^5+x^4+x^2+x+1, then XORed with the
	 * fixed pattern 101010000010010.
	 *
	 * Computed rather than looked up from a hand-copied table: a single wrong
	 * bit in eight 15-bit magic numbers is exactly the kind of transcription
	 * error that is invisible on inspection and breaks every symbol that
	 * happens to choose that mask.
	 *
	 * @param int $mask Mask pattern (0-7).
	 * @return int
	 */
	private static function format_info_bits( int $mask ): int {
		$data = ( 0b00 << 3 ) | $mask; // EC level M = 00.
		$generator = 0b10100110111;
		$remainder = $data << 10;

		for ( $i = 14; $i >= 10; $i-- ) {
			if ( $remainder & ( 1 << $i ) ) {
				$remainder ^= $generator << ( $i - 10 );
			}
		}

		return ( ( $data << 10 ) | $remainder ) ^ 0b101010000010010;
	}

	/**
	 * Write the 18-bit version-information block (a 3x6 rectangle placed
	 * twice, once by each of two finder patterns) that every version 7+
	 * symbol carries in addition to the format info every version has.
	 *
	 * A version below 7 fits its number inside the format info's own EC
	 * level bits implicitly (there is only one version that size can be), so
	 * no separate block exists and nothing is written.
	 *
	 * @param bool[][] $modules Module matrix, by reference.
	 * @param int      $version QR version.
	 * @param int      $size    Symbol size in modules.
	 */
	private static function place_version_info( array &$modules, int $version, int $size ): void {
		if ( $version < 7 ) {
			return;
		}

		$bits = self::version_info_bits( $version );

		for ( $i = 0; $i < 18; $i++ ) {
			$mod = (bool) ( ( $bits >> $i ) & 1 );

			$modules[ intdiv( $i, 3 ) ][ $i % 3 + $size - 11 ] = $mod;
			$modules[ $i % 3 + $size - 11 ][ intdiv( $i, 3 ) ] = $mod;
		}
	}

	/**
	 * Compute the 18-bit version-info value: the 6-bit version number
	 * protected by a 12-bit BCH(18,6) remainder, generator polynomial
	 * x^12+x^11+x^10+x^9+x^8+x^5+x^2+1 - unlike format info, not XORed with
	 * any further mask afterwards.
	 *
	 * @param int $version QR version (7-40; this class supports up to 10).
	 * @return int
	 */
	private static function version_info_bits( int $version ): int {
		$data      = $version << 12;
		$generator = 0b1111100100101;
		$remainder = $data;

		for ( $i = 17; $i >= 12; $i-- ) {
			if ( $remainder & ( 1 << $i ) ) {
				$remainder ^= $generator << ( $i - 12 );
			}
		}

		return $data | $remainder;
	}

	/**
	 * Place the codeword bits into every still-unclaimed module, in the
	 * zig-zag order the specification defines, masking each one as it goes.
	 *
	 * Ported directly from the reference algorithm's mapData: a single
	 * continuously-carried row cursor that reverses direction at each edge,
	 * rather than an independently-restarted sweep per column pair. The two
	 * are equivalent for an unbroken zig-zag, but this is the literal
	 * structure the specification (and every interoperable decoder) assumes,
	 * so there is nothing left to prove equivalent by hand.
	 *
	 * @param bool[][] $modules   Module matrix, by reference.
	 * @param int[]    $codewords Final interleaved codeword sequence.
	 * @param int      $mask      Mask pattern to apply while placing (0-7).
	 * @param int      $size      Symbol size in modules.
	 */
	private static function place_data( array &$modules, array $codewords, int $mask, int $size ): void {
		$inc        = -1;
		$row        = $size - 1;
		$bit_index  = 7;
		$byte_index = 0;

		for ( $col = $size - 1; $col > 0; $col -= 2 ) {
			if ( 6 === $col ) {
				--$col;
			}

			while ( true ) {
				for ( $c = 0; $c < 2; $c++ ) {
					if ( null === $modules[ $row ][ $col - $c ] ) {
						$dark = false;

						if ( $byte_index < count( $codewords ) ) {
							$dark = 1 === ( ( $codewords[ $byte_index ] >> $bit_index ) & 1 );
						}

						if ( self::mask_bit( $mask, $col - $c, $row ) ) {
							$dark = ! $dark;
						}

						$modules[ $row ][ $col - $c ] = $dark;
						--$bit_index;

						if ( -1 === $bit_index ) {
							++$byte_index;
							$bit_index = 7;
						}
					}
				}

				$row += $inc;

				if ( $row < 0 || $size <= $row ) {
					$row -= $inc;
					$inc  = -$inc;
					break;
				}
			}
		}
	}

	/**
	 * Whether mask pattern $mask flips the module at ($x, $y).
	 *
	 * @param int $mask Mask pattern (0-7).
	 * @param int $x    Column.
	 * @param int $y    Row.
	 * @return bool
	 */
	private static function mask_bit( int $mask, int $x, int $y ): bool {
		switch ( $mask ) {
			case 0:
				return 0 === ( $x + $y ) % 2;
			case 1:
				return 0 === $y % 2;
			case 2:
				return 0 === $x % 3;
			case 3:
				return 0 === ( $x + $y ) % 3;
			case 4:
				return 0 === ( intdiv( $y, 2 ) + intdiv( $x, 3 ) ) % 2;
			case 5:
				return 0 === ( $x * $y ) % 2 + ( $x * $y ) % 3;
			case 6:
				return 0 === ( ( $x * $y ) % 2 + ( $x * $y ) % 3 ) % 2;
			default:
				return 0 === ( ( $x + $y ) % 2 + ( $x * $y ) % 3 ) % 2;
		}
	}

	/**
	 * The four standard penalty rules (adjacent runs, 2x2 blocks, finder-like
	 * patterns, dark/light balance) from the specification, summed.
	 *
	 * @param bool[][] $grid Full module grid for one candidate mask.
	 * @param int      $size Symbol size in modules.
	 * @return int
	 */
	private static function penalty_score( array $grid, int $size ): int {
		$score = 0;

		// Rule 1: runs of 5+ same-colour modules in a row or column.
		foreach ( array( 'row', 'col' ) as $axis ) {
			for ( $i = 0; $i < $size; $i++ ) {
				$run = 1;

				for ( $j = 1; $j < $size; $j++ ) {
					$prev = 'row' === $axis ? $grid[ $i ][ $j - 1 ] : $grid[ $j - 1 ][ $i ];
					$cur  = 'row' === $axis ? $grid[ $i ][ $j ] : $grid[ $j ][ $i ];

					if ( $cur === $prev ) {
						++$run;
						continue;
					}

					if ( $run >= 5 ) {
						$score += 3 + ( $run - 5 );
					}

					$run = 1;
				}

				if ( $run >= 5 ) {
					$score += 3 + ( $run - 5 );
				}
			}
		}

		// Rule 2: 2x2 blocks of the same colour.
		for ( $y = 0; $y < $size - 1; $y++ ) {
			for ( $x = 0; $x < $size - 1; $x++ ) {
				$v = $grid[ $y ][ $x ];

				if ( $v === $grid[ $y ][ $x + 1 ] && $v === $grid[ $y + 1 ][ $x ] && $v === $grid[ $y + 1 ][ $x + 1 ] ) {
					$score += 3;
				}
			}
		}

		// Rule 3: the 1:1:3:1:1 finder-like run, with 4 light modules on
		// either side, in either direction.
		$pattern_a = array( true, false, true, true, true, false, true, false, false, false, false );
		$pattern_b = array( false, false, false, false, true, false, true, true, true, false, true );

		for ( $y = 0; $y < $size; $y++ ) {
			for ( $x = 0; $x <= $size - 11; $x++ ) {
				$slice = array_slice( $grid[ $y ], $x, 11 );

				if ( $slice === $pattern_a || $slice === $pattern_b ) {
					$score += 40;
				}
			}
		}

		for ( $x = 0; $x < $size; $x++ ) {
			for ( $y = 0; $y <= $size - 11; $y++ ) {
				$slice = array();

				for ( $i = 0; $i < 11; $i++ ) {
					$slice[] = $grid[ $y + $i ][ $x ];
				}

				if ( $slice === $pattern_a || $slice === $pattern_b ) {
					$score += 40;
				}
			}
		}

		// Rule 4: how far the dark-module proportion sits from 50%.
		$dark = 0;

		foreach ( $grid as $row ) {
			$dark += count( array_filter( $row ) );
		}

		$percent  = ( $dark * 100 ) / ( $size * $size );
		$deviation = (int) ( abs( $percent - 50 ) / 5 );
		$score    += $deviation * 10;

		return $score;
	}

	/**
	 * Render a matrix as a self-contained SVG string.
	 *
	 * @param bool[][] $matrix     Module matrix from encode().
	 * @param int      $module_px  Pixels per module.
	 * @param int      $quiet_zone Quiet-zone width, in modules.
	 * @return string
	 */
	public static function to_svg( array $matrix, int $module_px = 6, int $quiet_zone = 4 ): string {
		$size  = count( $matrix );
		$total = ( $size + $quiet_zone * 2 ) * $module_px;
		$path  = '';

		for ( $y = 0; $y < $size; $y++ ) {
			for ( $x = 0; $x < $size; $x++ ) {
				if ( $matrix[ $y ][ $x ] ) {
					$px = ( $x + $quiet_zone ) * $module_px;
					$py = ( $y + $quiet_zone ) * $module_px;
					$path .= "M{$px} {$py}h{$module_px}v{$module_px}h-{$module_px}z";
				}
			}
		}

		return sprintf(
			'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 %1$d %1$d" width="%1$d" height="%1$d" shape-rendering="crispEdges"><rect width="%1$d" height="%1$d" fill="#fff"/><path d="%2$s" fill="#000"/></svg>',
			$total,
			$path
		);
	}

	/**
	 * Render a matrix as a PNG, base64-encoded as a data: URI.
	 *
	 * @param bool[][] $matrix     Module matrix from encode().
	 * @param int      $module_px  Pixels per module.
	 * @param int      $quiet_zone Quiet-zone width, in modules.
	 * @return string|null Null when the GD extension is unavailable.
	 */
	public static function to_png_data_uri( array $matrix, int $module_px = 6, int $quiet_zone = 4 ): ?string {
		if ( ! function_exists( 'imagecreatetruecolor' ) ) {
			return null;
		}

		$size  = count( $matrix );
		$total = ( $size + $quiet_zone * 2 ) * $module_px;

		$image = imagecreatetruecolor( $total, $total );
		$white = imagecolorallocate( $image, 255, 255, 255 );
		$black = imagecolorallocate( $image, 0, 0, 0 );

		imagefilledrectangle( $image, 0, 0, $total - 1, $total - 1, $white );

		for ( $y = 0; $y < $size; $y++ ) {
			for ( $x = 0; $x < $size; $x++ ) {
				if ( ! $matrix[ $y ][ $x ] ) {
					continue;
				}

				$px = ( $x + $quiet_zone ) * $module_px;
				$py = ( $y + $quiet_zone ) * $module_px;

				imagefilledrectangle( $image, $px, $py, $px + $module_px - 1, $py + $module_px - 1, $black );
			}
		}

		ob_start();
		imagepng( $image );
		$bytes = ob_get_clean();
		imagedestroy( $image );

		if ( false === $bytes || '' === $bytes ) {
			return null;
		}

		return 'data:image/png;base64,' . base64_encode( $bytes ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- encoding an inline image, not obfuscating anything.
	}
}

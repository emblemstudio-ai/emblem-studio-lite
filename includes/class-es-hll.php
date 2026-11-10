<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Emblem_Studio_Hll {

	public const P = 14;

	public const M = 16384;

	private const SPARSE_MAX = 5000;

	/** @var array<int, int> register index => rank, only those set */
	private array $reg = [];

	public function add( string $value ): void {
		$h    = hash( 'xxh64', $value );
		$hi   = (int) hexdec( substr( $h, 0, 8 ) );
		$lo   = (int) hexdec( substr( $h, 8, 8 ) );
		$idx  = $hi >> ( 32 - self::P );
		$rest = $hi & ( ( 1 << ( 32 - self::P ) ) - 1 );
		$rank = 1;
		for ( $bit = 32 - self::P - 1; $bit >= 0 && ! ( ( $rest >> $bit ) & 1 ); $bit-- ) {
			$rank++;
		}
		if ( $rank > 32 - self::P ) {
			for ( $bit = 31; $bit >= 0 && ! ( ( $lo >> $bit ) & 1 ); $bit-- ) {
				$rank++;
			}
		}
		if ( ( $this->reg[ $idx ] ?? 0 ) < $rank ) {
			$this->reg[ $idx ] = $rank;
		}
	}

	public function merge( self $other ): void {
		foreach ( $other->reg as $i => $r ) {
			if ( ( $this->reg[ $i ] ?? 0 ) < $r ) {
				$this->reg[ $i ] = $r;
			}
		}
	}

	public function count(): int {
		if ( $this->reg === [] ) {
			return 0;
		}
		$m   = self::M;
		$sum = 0.0;
		foreach ( $this->reg as $r ) {
			$sum += 2 ** -$r;
		}
		$zeros    = $m - count( $this->reg );
		$sum     += $zeros;
		$alpha    = 0.7213 / ( 1 + 1.079 / $m );
		$estimate = $alpha * $m * $m / $sum;
		if ( $estimate <= 2.5 * $m && $zeros > 0 ) {
			$estimate = $m * log( $m / $zeros );
		}
		return (int) round( $estimate );
	}

	public function encode(): string {
		if ( count( $this->reg ) <= self::SPARSE_MAX ) {
			ksort( $this->reg );
			$bin = 'S';
			foreach ( $this->reg as $i => $r ) {
				$bin .= pack( 'nC', $i, $r );
			}
		} else {
			$bytes = str_repeat( "\0", self::M );
			foreach ( $this->reg as $i => $r ) {
				$bytes[ $i ] = chr( $r );
			}
			$bin = 'D' . $bytes;
		}
		return base64_encode( $bin );
	}

	public static function decode( ?string $stored ): self {
		$h   = new self();
		$bin = base64_decode( (string) $stored, true );
		if ( $bin === false || $bin === '' ) {
			return $h;
		}
		if ( $bin[0] === 'S' ) {
			foreach ( str_split( substr( $bin, 1 ), 3 ) as $chunk ) {
				if ( strlen( $chunk ) === 3 ) {
					$u = unpack( 'ni/Cr', $chunk );
					if ( is_array( $u ) ) {
						$h->reg[ (int) $u['i'] ] = (int) $u['r'];
					}
				}
			}
		} elseif ( $bin[0] === 'D' && strlen( $bin ) === self::M + 1 ) {
			for ( $i = 0; $i < self::M; $i++ ) {
				$r = ord( $bin[ $i + 1 ] );
				if ( $r > 0 ) {
					$h->reg[ $i ] = $r;
				}
			}
		}
		return $h;
	}
}

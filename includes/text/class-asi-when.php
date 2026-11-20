<?php
/**
 * "El viernes a las 8am", "mañana en la tarde", "27/11", "el 27 de noviembre": la fecha y la hora
 * concretas de lo que pide el visitante, para confirmarlas con el día exacto y no aceptar algo
 * que ya pasó. Sin WordPress (lo prueban las pruebas).
 *
 * @package AsesorInteligente
 */

defined( 'ABSPATH' ) || defined( 'ASI_CLI_TESTS' ) || exit;

class ASI_When {

	/**
	 * @param string $text Lo que escribió.
	 * @param int    $now  Ahora, en la hora del sitio (como current_time( 'timestamp' )).
	 * @param array  $cal  Palabras del idioma (léxico: when_calendar).
	 * @return array { date: Y-m-d|'', time: H:i|'', part: morning|afternoon|night|'', asap: bool, past: bool, label: string }
	 */
	public static function resolve( $text, $now, array $cal ) {
		$t     = self::plain( $text );
		$today = gmdate( 'Y-m-d', $now );
		$out   = array( 'date' => '', 'time' => '', 'part' => '', 'asap' => false, 'past' => false, 'label' => '' );

		$out['asap'] = '' !== self::find( $t, self::words( $cal, 'asap' ) );

		// Momento del día ("en la mañana" no es "mañana").
		foreach ( array( 'morning', 'afternoon', 'night' ) as $part ) {
			if ( '' !== self::find( $t, self::words( $cal, $part ) ) ) {
				$out['part'] = $part;
				break;
			}
		}
		$no_part = $t;
		foreach ( array( 'morning', 'afternoon', 'night' ) as $part ) {
			foreach ( self::words( $cal, $part ) as $w ) {
				$no_part = str_replace( ' ' . $w . ' ', ' ', ' ' . $no_part . ' ' );
			}
		}

		// Hora.
		$hour = null;
		$min  = 0;
		if ( preg_match( '/(?:^|\s)(\d{1,2}):(\d{2})\s*(am|pm)?(?=\s|$)/', $t, $m ) || preg_match( '/(?:^|\s)(\d{1,2})()\s*(am|pm|hrs|hr|h)(?=\s|$)/', $t, $m ) ) {
			$hour = (int) $m[1];
			$min  = '' !== $m[2] ? (int) $m[2] : 0;
			$ampm = isset( $m[3] ) ? $m[3] : '';
		} elseif ( preg_match( '/(?:^|\s)(?:' . implode( '|', array_map( 'preg_quote', self::words( $cal, 'at' ) ) ) . ')\s+(\d{1,2})(?=\s|$)/', $t, $m ) ) {
			$hour = (int) $m[1];
			$ampm = '';
		} elseif ( '' !== self::find( $t, self::words( $cal, 'noon' ) ) ) {
			$hour = 12;
			$ampm = '';
		}
		if ( null !== $hour && $hour <= 23 && $min <= 59 ) {
			if ( 'pm' === $ampm && $hour < 12 ) {
				$hour += 12;
			} elseif ( 'am' === $ampm && 12 === $hour ) {
				$hour = 0;
			} elseif ( '' === $ampm && $hour < 12 && in_array( $out['part'], array( 'afternoon', 'night' ), true ) ) {
				$hour += 12;
			} elseif ( '' === $ampm && $hour >= 1 && $hour <= 6 && '' === $out['part'] ) {
				$hour += 12; // "A las 3": de la tarde (nadie agenda a las 3 de la madrugada).
			}
			$out['time'] = sprintf( '%02d:%02d', $hour, $min );
		}

		// Día.
		$days   = self::words( $cal, 'weekdays' ); // 0 = domingo.
		$months = self::words( $cal, 'months' );   // 0 = enero.
		$wd     = (int) gmdate( 'w', $now );
		$date   = '';
		if ( '' !== self::find( $no_part, self::words( $cal, 'after_tomorrow' ) ) ) {
			$date = self::add( $today, 2 );
		} elseif ( '' !== self::find( $no_part, self::words( $cal, 'tomorrow' ) ) ) {
			$date = self::add( $today, 1 );
		} elseif ( '' !== self::find( $no_part, self::words( $cal, 'today' ) ) ) {
			$date = $today;
		}
		// 27/11 o 27/11/2026.
		if ( '' === $date && preg_match( '/(?:^|\s)(\d{1,2})\/(\d{1,2})(?:\/(\d{2,4}))?(?=\s|$)/', $t, $m ) ) {
			$date = self::day_month( (int) $m[1], (int) $m[2], isset( $m[3] ) ? (int) $m[3] : 0, $today );
		}
		// "27 de noviembre".
		if ( '' === $date && preg_match( '/(?:^|\s)(\d{1,2})\s+(?:de\s+)?(' . implode( '|', array_map( 'preg_quote', $months ) ) . ')(?=\s|$)/', $t, $m ) ) {
			$date = self::day_month( (int) $m[1], array_search( $m[2], $months, true ) + 1, 0, $today );
		}
		// Día de la semana ("el viernes", "el próximo lunes", "el lunes 23").
		$named = -1;
		foreach ( $days as $i => $name ) {
			if ( preg_match( '/(?:^|\s)' . preg_quote( $name, '/' ) . '(?=\s|$)/', $t ) ) {
				$named = $i;
				break;
			}
		}
		// "El 27" (sin mes): de este mes, o del siguiente si ya pasó.
		if ( '' === $date && preg_match( '/(?:^|\s)(?:el|day|the)\s+(?:' . ( $named >= 0 ? preg_quote( $days[ $named ], '/' ) . '\s+' : '' ) . ')?(\d{1,2})(?!\s*(?::|am|pm|hrs|h\b))(?=\s|$)/', $t, $m ) && (int) $m[1] >= 1 && (int) $m[1] <= 31 ) {
			$y = (int) gmdate( 'Y', $now );
			$n = (int) gmdate( 'n', $now );
			$d = (int) $m[1];
			if ( $d < (int) gmdate( 'j', $now ) ) {
				$n = 12 === $n ? 1 : $n + 1;
				$y = 1 === $n ? $y + 1 : $y;
			}
			$date = checkdate( $n, $d, $y ) ? sprintf( '%04d-%02d-%02d', $y, $n, $d ) : '';
		}
		if ( '' === $date && $named >= 0 ) {
			$ahead = ( $named - $wd + 7 ) % 7;
			if ( '' !== self::find( $t, self::words( $cal, 'next' ) ) && 0 === $ahead ) {
				$ahead = 7;
			}
			$date = self::add( $today, $ahead );
		}
		$out['date'] = $date;

		// ¿Ya pasó?
		if ( '' !== $date ) {
			if ( $date < $today ) {
				$out['past'] = true;
			} elseif ( $date === $today ) {
				$now_hm = gmdate( 'H:i', $now );
				$ends   = array( 'morning' => '12:00', 'afternoon' => '18:00', 'night' => '23:59' );
				if ( ( '' !== $out['time'] && $out['time'] <= $now_hm ) || ( '' === $out['time'] && '' !== $out['part'] && $ends[ $out['part'] ] <= $now_hm ) ) {
					$out['past'] = true;
				}
			}
			$out['label'] = self::label( $date, $out['time'], $out['part'], $cal );
		}
		return $out;
	}

	/** "viernes 27 de noviembre a las 8:00" / "en la mañana". */
	public static function label( $date, $time, $part, array $cal ) {
		$ts     = strtotime( $date . ' 12:00:00 UTC' );
		$names  = self::words( $cal, 'weekday_names' );
		$months = self::words( $cal, 'month_names' );
		$format = isset( $cal['date_format'] ) ? (string) $cal['date_format'] : '{weekday} {day} de {month}';
		$label  = strtr(
			$format,
			array(
				'{weekday}' => isset( $names[ (int) gmdate( 'w', $ts ) ] ) ? $names[ (int) gmdate( 'w', $ts ) ] : '',
				'{day}'     => gmdate( 'j', $ts ),
				'{month}'   => isset( $months[ (int) gmdate( 'n', $ts ) - 1 ] ) ? $months[ (int) gmdate( 'n', $ts ) - 1 ] : '',
			)
		);
		if ( '' !== $time ) {
			$label .= ' ' . strtr( isset( $cal['time_format'] ) ? (string) $cal['time_format'] : 'a las {time}', array( '{time}' => (int) substr( $time, 0, 2 ) . substr( $time, 2 ) ) );
		} elseif ( '' !== $part ) {
			$parts  = isset( $cal['part_names'] ) ? (array) $cal['part_names'] : array();
			$label .= isset( $parts[ $part ] ) ? ' ' . $parts[ $part ] : '';
		}
		return $label;
	}

	/**
	 * Los siguientes días para elegir, como botones: "sábado 21 de noviembre". Con horario, solo
	 * los días que se atiende; sin horario, todos menos el domingo.
	 *
	 * @param array|null $hours 0..6 => array( from, to ) | null (cerrado). null = sin horario.
	 * @return string[]
	 */
	public static function next_days( $now, array $cal, $count = 3, $hours = null ) {
		$out  = array();
		$date = gmdate( 'Y-m-d', $now );
		for ( $i = 1; count( $out ) < $count && $i < 21; $i++ ) {
			$day = self::add( $date, $i );
			$wd  = (int) gmdate( 'w', strtotime( $day . ' 12:00:00 UTC' ) );
			if ( is_array( $hours ) ? ! empty( $hours[ $wd ] ) : 0 !== $wd ) {
				$out[] = self::label( $day, '', '', $cal );
			}
		}
		return $out;
	}

	/**
	 * ¿Se atiende ese día y a esa hora? '' si sí; 'closed' (ese día no se atiende) u 'hours' (fuera de horario).
	 *
	 * @param array      $r     De resolve().
	 * @param array|null $hours 0..6 => array( from, to ) | null.
	 */
	public static function outside( array $r, $hours ) {
		if ( ! is_array( $hours ) || '' === $r['date'] ) {
			return '';
		}
		$wd = (int) gmdate( 'w', strtotime( $r['date'] . ' 12:00:00 UTC' ) );
		if ( empty( $hours[ $wd ] ) ) {
			return 'closed';
		}
		list( $from, $to ) = $hours[ $wd ];
		if ( '' !== $r['time'] && ( $r['time'] < $from || $r['time'] >= $to ) ) {
			return 'hours';
		}
		// "En la noche" un día que se cierra temprano; "en la mañana" un día que abre después del mediodía.
		if ( '' === $r['time'] && ( ( 'night' === $r['part'] && $to <= '19:00' ) || ( 'morning' === $r['part'] && $from >= '12:00' ) || ( 'afternoon' === $r['part'] && $to <= '12:00' ) ) ) {
			return 'hours';
		}
		return '';
	}

	/** "8:00 a 18:30" de un día (para decirlo en el chat). */
	public static function span( array $day, array $cal ) {
		$fmt = isset( $cal['span_format'] ) ? (string) $cal['span_format'] : 'de {from} a {to}';
		return strtr( $fmt, array( '{from}' => (int) substr( $day[0], 0, 2 ) . substr( $day[0], 2 ), '{to}' => (int) substr( $day[1], 0, 2 ) . substr( $day[1], 2 ) ) );
	}

	/**
	 * Lee un horario escrito ("Lunes a viernes 8:00 a 18:30, sábado 8:00 a 16:00"). null si no se
	 * entiende todo (mejor sin horario que con uno mal leído).
	 *
	 * @return array|null 0..6 => array( 'HH:MM', 'HH:MM' ) | null (cerrado).
	 */
	public static function parse_hours( $text, array $cal ) {
		$t     = self::plain( str_replace( array( '–', '—' ), '-', (string) $text ) );
		$days  = self::words( $cal, 'weekdays' );
		$short = self::words( $cal, 'weekdays_short' );
		if ( '' === $t || ! $days ) {
			return null;
		}
		$day_rx  = implode( '|', array_map( 'preg_quote', array_merge( $days, $short ) ) );
		$range   = implode( '|', array_map( 'preg_quote', self::words( $cal, 'range' ) ) );
		$range   = '' !== $range ? $range : 'a|-';
		$time_rx = '(\d{1,2})(?::(\d{2}))?\s*(am|pm|hrs|h)?';
		$out     = array_fill( 0, 7, null );
		$found   = false;
		// Cada tramo empieza con un día: "lunes a viernes 8:00 a 18:30", "sabado 8 am - 4 pm".
		preg_match_all( '/((?:' . $day_rx . ')(?:\s*(?:' . $range . '|y|,)\s*(?:' . $day_rx . '))*)\s*(?:de\s+|from\s+)?' . $time_rx . '\s*(?:' . $range . '|hasta|to)\s*' . $time_rx . '/u', $t, $all, PREG_SET_ORDER );
		foreach ( $all as $m ) {
			$idx = self::day_list( $m[1], $days, $short, $range );
			$a   = self::hm( (int) $m[2], '' !== $m[3] ? (int) $m[3] : 0, isset( $m[4] ) ? $m[4] : '', false );
			$b   = self::hm( (int) $m[5], isset( $m[6] ) && '' !== $m[6] ? (int) $m[6] : 0, isset( $m[7] ) ? $m[7] : '', true );
			if ( ! $idx || ! $a || ! $b || $a >= $b ) {
				return null;
			}
			foreach ( $idx as $i ) {
				$out[ $i ] = array( $a, $b );
			}
			$found = true;
		}
		return $found ? $out : null;
	}

	/** "lunes a viernes" → 1..5; "lunes y miercoles" → 1, 3. */
	private static function day_list( $text, array $days, array $short, $range ) {
		$index = function ( $w ) use ( $days, $short ) {
			$i = array_search( $w, $days, true );
			return false !== $i ? $i : array_search( $w, $short, true );
		};
		$rx = implode( '|', array_map( 'preg_quote', array_merge( $days, $short ) ) );
		preg_match_all( '/(' . $rx . ')(?:\s*(?:' . $range . ')\s*(' . $rx . '))?/u', $text, $parts, PREG_SET_ORDER );
		$out = array();
		foreach ( $parts as $p ) {
			$a = $index( $p[1] );
			if ( false === $a ) {
				return array();
			}
			if ( ! empty( $p[2] ) ) {
				$b = $index( $p[2] );
				if ( false === $b ) {
					return array();
				}
				for ( $i = $a, $n = 0; $n < 7; $i = ( $i + 1 ) % 7, $n++ ) {
					$out[] = $i;
					if ( $i === $b ) {
						break;
					}
				}
			} else {
				$out[] = $a;
			}
		}
		return array_values( array_unique( $out ) );
	}

	/** Hora "HH:MM" de un horario escrito; sin am/pm, el cierre antes de las 8 es de la tarde ("8 a 5"). */
	private static function hm( $h, $m, $ampm, $closing ) {
		if ( 'pm' === $ampm && $h < 12 ) {
			$h += 12;
		} elseif ( 'am' === $ampm && 12 === $h ) {
			$h = 0;
		} elseif ( '' === $ampm && $closing && $h < 8 ) {
			$h += 12;
		}
		return $h <= 24 && $m <= 59 ? sprintf( '%02d:%02d', min( $h, 23 ), 24 === $h ? 59 : $m ) : '';
	}

	/**
	 * Horario guardado en el panel (0..6 => 'HH:MM-HH:MM' | '') a la forma que usa el chat.
	 *
	 * @return array|null null si no hay ningún día abierto.
	 */
	public static function from_setting( $saved ) {
		if ( ! is_array( $saved ) ) {
			return null;
		}
		$out = array_fill( 0, 7, null );
		$any = false;
		for ( $i = 0; $i < 7; $i++ ) {
			$v = isset( $saved[ $i ] ) ? (string) $saved[ $i ] : '';
			if ( preg_match( '/^(\d{2}:\d{2})-(\d{2}:\d{2})$/', $v, $m ) && $m[1] < $m[2] ) {
				$out[ $i ] = array( $m[1], $m[2] );
				$any       = true;
			}
		}
		return $any ? $out : null;
	}

	// ---------- Utilidades ----------

	private static function plain( $text ) {
		$t = function_exists( 'mb_strtolower' ) ? mb_strtolower( (string) $text, 'UTF-8' ) : strtolower( (string) $text );
		$t = strtr( $t, array( 'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'à' => 'a', 'è' => 'e' ) );
		$t = preg_replace( '/\b([ap])\s*\.?\s*m\s*\.?(?=[\s,.;!?]|$)/u', '$1m', $t );
		$t = preg_replace( '/(\d)\s*(am|pm)\b/u', '$1 $2', $t );
		$t = preg_replace( '/[^\p{L}\d:\/ \-]+/u', ' ', $t );
		$t = preg_replace( '/\s*-\s*/u', ' - ', $t ); // "Lun-Vie 9-5": el guion es un rango.
		return trim( preg_replace( '/\s+/u', ' ', $t ) );
	}

	private static function words( array $cal, $key ) {
		return isset( $cal[ $key ] ) ? array_values( (array) $cal[ $key ] ) : array();
	}

	private static function find( $text, array $words ) {
		usort(
			$words,
			static function ( $a, $b ) {
				return strlen( $b ) - strlen( $a );
			}
		);
		foreach ( $words as $w ) {
			if ( '' !== $w && preg_match( '/(?:^|\s)' . preg_quote( $w, '/' ) . '(?=\s|$)/', $text ) ) {
				return $w;
			}
		}
		return '';
	}

	private static function add( $date, $days ) {
		return gmdate( 'Y-m-d', strtotime( $date . ' 12:00:00 UTC' ) + $days * 86400 );
	}

	/** Día y mes (y año, si lo dijo). Sin año, una fecha de hace más de dos meses es del año que viene. */
	private static function day_month( $d, $n, $y, $today ) {
		$this_year = (int) substr( $today, 0, 4 );
		if ( $y > 0 && $y < 100 ) {
			$y += 2000;
		}
		if ( ! $y ) {
			$y = $this_year;
			if ( sprintf( '%04d-%02d-%02d', $y, $n, $d ) < gmdate( 'Y-m-d', strtotime( $today . ' 12:00:00 UTC' ) - 60 * 86400 ) ) {
				$y++;
			}
		}
		return $n >= 1 && $n <= 12 && checkdate( $n, $d, $y ) ? sprintf( '%04d-%02d-%02d', $y, $n, $d ) : '';
	}
}

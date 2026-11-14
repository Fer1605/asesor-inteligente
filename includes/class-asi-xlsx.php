<?php
/**
 * Excel (.xlsx) mínimo con formato: títulos, encabezados de color, números con separador de miles,
 * porcentajes, anchos de columna y encabezado fijo. Sin librerías: solo ZipArchive.
 *
 * Cada celda es un valor (número o texto) o array( valor, estilo ). Estilos: title, subtitle, note,
 * header, header_num, text, bold, number, percent.
 *
 * @package AsesorInteligente
 */

defined( 'ABSPATH' ) || exit;

class ASI_Xlsx {

	const STYLES = array(
		'default'    => 0,
		'title'      => 1,
		'subtitle'   => 2,
		'header'     => 3,
		'text'       => 4,
		'number'     => 5,
		'percent'    => 6,
		'bold'       => 7,
		'header_num' => 8,
		'note'       => 9,
		'warn'       => 10,
	);

	/** @var array[] name, rows, widths, freeze */
	private $sheets = array();

	public static function available() {
		return class_exists( 'ZipArchive' );
	}

	/**
	 * @param string  $name   Nombre de la hoja (máx. 31 caracteres).
	 * @param array[] $rows   Filas.
	 * @param int[]   $widths Ancho de cada columna (en caracteres).
	 * @param int     $freeze Filas fijas arriba (0 = ninguna).
	 */
	public function add_sheet( $name, array $rows, array $widths = array(), $freeze = 0 ) {
		$name           = preg_replace( '#[\\\\/?*\[\]:]#', ' ', (string) $name );
		$this->sheets[] = array(
			'name'   => function_exists( 'mb_substr' ) ? mb_substr( $name, 0, 31 ) : substr( $name, 0, 31 ),
			'rows'   => $rows,
			'widths' => $widths,
			'freeze' => (int) $freeze,
		);
	}

	/**
	 * @return string|null Contenido del archivo, o null si no se pudo armar.
	 */
	public function build() {
		if ( ! self::available() || ! $this->sheets ) {
			return null;
		}
		$file = wp_tempnam( 'asi-reporte.xlsx' );
		$zip  = new ZipArchive();
		if ( true !== $zip->open( $file, ZipArchive::OVERWRITE ) ) {
			return null;
		}
		$n          = count( $this->sheets );
		$overrides  = '';
		$sheets_xml = '';
		$rels       = '';
		for ( $i = 1; $i <= $n; $i++ ) {
			$overrides  .= '<Override PartName="/xl/worksheets/sheet' . $i . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
			$sheets_xml .= '<sheet name="' . self::esc( $this->sheets[ $i - 1 ]['name'] ) . '" sheetId="' . $i . '" r:id="rId' . $i . '"/>';
			$rels       .= '<Relationship Id="rId' . $i . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $i . '.xml"/>';
			$zip->addFromString( 'xl/worksheets/sheet' . $i . '.xml', $this->sheet_xml( $this->sheets[ $i - 1 ] ) );
		}
		$rels .= '<Relationship Id="rId' . ( $n + 1 ) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>';

		$zip->addFromString( '[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>' . $overrides . '</Types>' );
		$zip->addFromString( '_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>' );
		$zip->addFromString( 'xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>' . $sheets_xml . '</sheets></workbook>' );
		$zip->addFromString( 'xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . $rels . '</Relationships>' );
		$zip->addFromString( 'xl/styles.xml', self::styles_xml() );
		$zip->close();

		$data = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		wp_delete_file( $file );
		return false === $data ? null : $data;
	}

	private function sheet_xml( array $sheet ) {
		$xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">';
		$xml .= '<sheetPr><pageSetUpPr fitToPage="1"/></sheetPr>';
		$xml .= '<sheetViews><sheetView workbookViewId="0" showGridLines="0">';
		if ( $sheet['freeze'] > 0 ) {
			$xml .= '<pane ySplit="' . $sheet['freeze'] . '" topLeftCell="A' . ( $sheet['freeze'] + 1 ) . '" activePane="bottomLeft" state="frozen"/>';
		}
		$xml .= '</sheetView></sheetViews><sheetFormatPr defaultRowHeight="18"/>';
		if ( $sheet['widths'] ) {
			$xml .= '<cols>';
			foreach ( array_values( $sheet['widths'] ) as $i => $w ) {
				$xml .= '<col min="' . ( $i + 1 ) . '" max="' . ( $i + 1 ) . '" width="' . (float) $w . '" customWidth="1"/>';
			}
			$xml .= '</cols>';
		}
		$xml .= '<sheetData>';
		foreach ( array_values( $sheet['rows'] ) as $r => $row ) {
			$num    = $r + 1;
			$height = '';
			$first  = $row ? reset( $row ) : null;
			if ( is_array( $first ) && isset( $first[1] ) && 'title' === $first[1] ) {
				$height = ' ht="28" customHeight="1"';
			} elseif ( is_array( $first ) && isset( $first[1] ) && in_array( $first[1], array( 'header', 'header_num' ), true ) ) {
				$height = ' ht="34" customHeight="1"';
			}
			$xml .= '<row r="' . $num . '"' . $height . '>';
			foreach ( array_values( $row ) as $c => $cell ) {
				list( $value, $style ) = is_array( $cell ) ? array( $cell[0], isset( $cell[1] ) ? $cell[1] : '' ) : array( $cell, '' );
				if ( null === $value || '' === $value ) {
					if ( '' !== $style ) {
						$xml .= '<c r="' . self::col( $c ) . $num . '" s="' . self::STYLES[ $style ] . '"/>';
					}
					continue;
				}
				if ( '' === $style ) {
					$style = is_int( $value ) || is_float( $value ) ? 'number' : 'text';
				}
				$ref = self::col( $c ) . $num;
				$s   = isset( self::STYLES[ $style ] ) ? self::STYLES[ $style ] : 0;
				if ( is_int( $value ) || is_float( $value ) ) {
					$xml .= '<c r="' . $ref . '" s="' . $s . '"><v>' . ( is_float( $value ) ? rtrim( rtrim( sprintf( '%.6F', $value ), '0' ), '.' ) : $value ) . '</v></c>';
				} else {
					$xml .= '<c r="' . $ref . '" s="' . $s . '" t="inlineStr"><is><t xml:space="preserve">' . self::esc( (string) $value ) . '</t></is></c>';
				}
			}
			$xml .= '</row>';
		}
		$xml .= '</sheetData><pageMargins left="0.5" right="0.5" top="0.6" bottom="0.6" header="0.3" footer="0.3"/><pageSetup orientation="portrait" fitToWidth="1" fitToHeight="0"/></worksheet>';
		return $xml;
	}

	private static function styles_xml() {
		$border = '<border><left/><right/><top/><bottom style="thin"><color rgb="FFDCDCDE"/></bottom><diagonal/></border>';
		return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
			. '<numFmts count="1"><numFmt numFmtId="164" formatCode="0.0%"/></numFmts>'
			. '<fonts count="6">'
			. '<font><sz val="11"/><color rgb="FF1D2327"/><name val="Calibri"/><family val="2"/></font>'
			. '<font><b/><sz val="11"/><color rgb="FF1D2327"/><name val="Calibri"/><family val="2"/></font>'
			. '<font><b/><sz val="16"/><color rgb="FF1D2327"/><name val="Calibri"/><family val="2"/></font>'
			. '<font><sz val="10"/><color rgb="FF646970"/><name val="Calibri"/><family val="2"/></font>'
			. '<font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/><family val="2"/></font>'
			. '<font><b/><sz val="10"/><color rgb="FF8A4B00"/><name val="Calibri"/><family val="2"/></font>'
			. '</fonts>'
			. '<fills count="4"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill>'
			. '<fill><patternFill patternType="solid"><fgColor rgb="FF2A78D6"/><bgColor indexed="64"/></patternFill></fill>'
			. '<fill><patternFill patternType="solid"><fgColor rgb="FFFFF4E5"/><bgColor indexed="64"/></patternFill></fill></fills>'
			. '<borders count="2"><border><left/><right/><top/><bottom/><diagonal/></border>' . $border . '</borders>'
			. '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
			. '<cellXfs count="11">'
			. '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'                                                                                        // default
			. '<xf numFmtId="0" fontId="2" fillId="0" borderId="0" xfId="0" applyFont="1"><alignment vertical="center"/></xf>'                                          // title
			. '<xf numFmtId="0" fontId="3" fillId="0" borderId="0" xfId="0" applyFont="1"/>'                                                                         // subtitle
			. '<xf numFmtId="0" fontId="4" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1" applyAlignment="1"><alignment vertical="center" wrapText="1"/></xf>'       // header
			. '<xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1" applyAlignment="1"><alignment vertical="center"/></xf>'                  // text
			. '<xf numFmtId="3" fontId="0" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyBorder="1" applyAlignment="1"><alignment vertical="center"/></xf>' // number
			. '<xf numFmtId="164" fontId="0" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyBorder="1" applyAlignment="1"><alignment vertical="center"/></xf>' // percent
			. '<xf numFmtId="0" fontId="1" fillId="0" borderId="1" xfId="0" applyFont="1" applyBorder="1" applyAlignment="1"><alignment vertical="center"/></xf>'   // bold
			. '<xf numFmtId="0" fontId="4" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1" applyAlignment="1"><alignment horizontal="right" vertical="center" wrapText="1"/></xf>' // header_num
			. '<xf numFmtId="0" fontId="3" fillId="0" borderId="0" xfId="0" applyFont="1" applyAlignment="1"><alignment wrapText="1" vertical="top"/></xf>'          // note
			. '<xf numFmtId="0" fontId="5" fillId="3" borderId="0" xfId="0" applyFont="1" applyFill="1"/>'                                                           // warn
			. '</cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>';
	}

	/** Letra de la columna (0 = A). */
	private static function col( $i ) {
		$s = '';
		for ( $i++; $i > 0; $i = (int) ( ( $i - 1 ) / 26 ) ) {
			$s = chr( 65 + ( $i - 1 ) % 26 ) . $s;
		}
		return $s;
	}

	private static function esc( $text ) {
		$text = preg_replace( '/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}]/u', '', (string) $text );
		return htmlspecialchars( (string) $text, ENT_XML1 | ENT_QUOTES, 'UTF-8' );
	}
}

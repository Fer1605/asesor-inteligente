<?php
/**
 * Modelos de referencia del mercado, para entender "parecido al X" aunque el X no esté en el
 * catálogo del sitio. Datos generales de la versión más común (no de un sitio), aproximados.
 *
 * clave (como lo escribe la gente) => brand, name, body, seats, traction, fuel, cc, traits (es/en)
 *
 * @package AsesorInteligente
 */

defined( 'ABSPATH' ) || defined( 'ASI_CLI_TESTS' ) || exit;

$pickup = array( 'body' => 'pickup', 'seats' => 5 );
$t = static function ( $es, $en ) {
	return array( 'es' => $es, 'en' => $en );
};

return array(
	// Pickups.
	'frontier'  => $pickup + array( 'brand' => 'Nissan', 'name' => 'Nissan Frontier', 'traction' => '4x4', 'fuel' => 'diesel', 'cc' => 2500, 'traits' => $t( 'resistencia, un motor diésel con buen torque y buena capacidad de carga y arrastre', 'toughness, a high-torque diesel engine and good payload and towing' ) ),
	'navara'    => $pickup + array( 'brand' => 'Nissan', 'name' => 'Nissan Navara', 'traction' => '4x4', 'fuel' => 'diesel', 'cc' => 2300, 'traits' => $t( 'confort de SUV con capacidad de pickup', 'SUV-like comfort with pickup capability' ) ),
	'hilux'     => $pickup + array( 'brand' => 'Toyota', 'name' => 'Toyota Hilux', 'traction' => '4x4', 'fuel' => 'diesel', 'cc' => 2800, 'traits' => $t( 'ser casi indestructible, un motor diésel muy confiable y un valor de reventa alto', 'being nearly indestructible, a very reliable diesel and strong resale value' ) ),
	'ranger'    => $pickup + array( 'brand' => 'Ford', 'name' => 'Ford Ranger', 'traction' => '4x4', 'fuel' => 'diesel', 'cc' => 2000, 'traits' => $t( 'tecnología, manejo cómodo y buena capacidad de carga', 'technology, comfortable handling and good payload' ) ),
	'l200'      => $pickup + array( 'brand' => 'Mitsubishi', 'name' => 'Mitsubishi L200', 'traction' => '4x4', 'fuel' => 'diesel', 'cc' => 2400, 'traits' => $t( 'un 4x4 muy capaz fuera de carretera y resistencia', 'very capable off-road 4x4 and toughness' ) ),
	'triton'    => $pickup + array( 'brand' => 'Mitsubishi', 'name' => 'Mitsubishi Triton', 'traction' => '4x4', 'fuel' => 'diesel', 'cc' => 2400, 'traits' => $t( 'un 4x4 muy capaz fuera de carretera y resistencia', 'very capable off-road 4x4 and toughness' ) ),
	'd-max'     => $pickup + array( 'brand' => 'Isuzu', 'name' => 'Isuzu D-Max', 'traction' => '4x4', 'fuel' => 'diesel', 'cc' => 3000, 'traits' => $t( 'un motor diésel de trabajo pesado y bajo consumo', 'a heavy-duty, fuel-efficient diesel' ) ),
	'dmax'      => $pickup + array( 'brand' => 'Isuzu', 'name' => 'Isuzu D-Max', 'traction' => '4x4', 'fuel' => 'diesel', 'cc' => 3000, 'traits' => $t( 'un motor diésel de trabajo pesado y bajo consumo', 'a heavy-duty, fuel-efficient diesel' ) ),
	'bt-50'     => $pickup + array( 'brand' => 'Mazda', 'name' => 'Mazda BT-50', 'traction' => '4x4', 'fuel' => 'diesel', 'cc' => 3000, 'traits' => $t( 'diseño, confort interior y un motor diésel robusto', 'design, interior comfort and a robust diesel' ) ),
	'bt 50'     => $pickup + array( 'brand' => 'Mazda', 'name' => 'Mazda BT-50', 'traction' => '4x4', 'fuel' => 'diesel', 'cc' => 3000, 'traits' => $t( 'diseño, confort interior y un motor diésel robusto', 'design, interior comfort and a robust diesel' ) ),
	'tacoma'    => $pickup + array( 'brand' => 'Toyota', 'name' => 'Toyota Tacoma', 'traction' => '4x4', 'fuel' => 'gasoline', 'cc' => 2400, 'traits' => $t( 'robustez y un 4x4 muy capaz', 'ruggedness and a very capable 4x4' ) ),
	'amarok'    => $pickup + array( 'brand' => 'Volkswagen', 'name' => 'Volkswagen Amarok', 'traction' => '4x4', 'fuel' => 'diesel', 'cc' => 3000, 'traits' => $t( 'potencia y un manejo cómodo', 'power and comfortable handling' ) ),
	'gladiator' => $pickup + array( 'brand' => 'Jeep', 'name' => 'Jeep Gladiator', 'traction' => '4x4', 'fuel' => 'gasoline', 'cc' => 3600, 'traits' => $t( 'capacidad todoterreno extrema', 'extreme off-road capability' ) ),
	'shark'     => $pickup + array( 'brand' => 'BYD', 'name' => 'BYD Shark', 'traction' => '4x4', 'fuel' => 'hybrid', 'cc' => 1500, 'traits' => $t( 'ser híbrida enchufable, potente y de bajo consumo', 'being a powerful, efficient plug-in hybrid' ) ),
	// SUV.
	'rav4'      => array( 'brand' => 'Toyota', 'name' => 'Toyota RAV4', 'body' => 'suv', 'seats' => 5, 'traction' => 'awd', 'fuel' => 'hybrid', 'cc' => 2500, 'traits' => $t( 'confiabilidad, buen espacio y versión híbrida de bajo consumo', 'reliability, good space and an efficient hybrid version' ) ),
	'cr-v'      => array( 'brand' => 'Honda', 'name' => 'Honda CR-V', 'body' => 'suv', 'seats' => 5, 'traction' => '4x2', 'fuel' => 'gasoline', 'cc' => 1500, 'traits' => $t( 'mucho espacio interior y manejo suave', 'lots of interior space and smooth handling' ) ),
	'crv'       => array( 'brand' => 'Honda', 'name' => 'Honda CR-V', 'body' => 'suv', 'seats' => 5, 'traction' => '4x2', 'fuel' => 'gasoline', 'cc' => 1500, 'traits' => $t( 'mucho espacio interior y manejo suave', 'lots of interior space and smooth handling' ) ),
	'tucson'    => array( 'brand' => 'Hyundai', 'name' => 'Hyundai Tucson', 'body' => 'suv', 'seats' => 5, 'traction' => '4x2', 'fuel' => 'gasoline', 'cc' => 2000, 'traits' => $t( 'diseño moderno, mucho equipamiento y buena garantía', 'modern design, lots of features and a good warranty' ) ),
	'sportage'  => array( 'brand' => 'Kia', 'name' => 'Kia Sportage', 'body' => 'suv', 'seats' => 5, 'traction' => '4x2', 'fuel' => 'gasoline', 'cc' => 2000, 'traits' => $t( 'diseño, equipamiento y buena relación precio-valor', 'design, features and good value' ) ),
	'cx-5'      => array( 'brand' => 'Mazda', 'name' => 'Mazda CX-5', 'body' => 'suv', 'seats' => 5, 'traction' => 'awd', 'fuel' => 'gasoline', 'cc' => 2500, 'traits' => $t( 'manejo deportivo e interior de buena calidad', 'sporty handling and a quality interior' ) ),
	'cx5'       => array( 'brand' => 'Mazda', 'name' => 'Mazda CX-5', 'body' => 'suv', 'seats' => 5, 'traction' => 'awd', 'fuel' => 'gasoline', 'cc' => 2500, 'traits' => $t( 'manejo deportivo e interior de buena calidad', 'sporty handling and a quality interior' ) ),
	'rush'      => array( 'brand' => 'Toyota', 'name' => 'Toyota Rush', 'body' => 'suv', 'seats' => 7, 'traction' => '4x2', 'fuel' => 'gasoline', 'cc' => 1500, 'traits' => $t( 'siete plazas a buen precio y bajo consumo', 'seven seats at a good price and low fuel use' ) ),
	'fortuner'  => array( 'brand' => 'Toyota', 'name' => 'Toyota Fortuner', 'body' => 'suv', 'seats' => 7, 'traction' => '4x4', 'fuel' => 'diesel', 'cc' => 2800, 'traits' => $t( 'robustez de pickup, siete plazas y 4x4', 'pickup toughness, seven seats and 4x4' ) ),
	'prado'     => array( 'brand' => 'Toyota', 'name' => 'Toyota Land Cruiser Prado', 'body' => 'suv', 'seats' => 7, 'traction' => '4x4', 'fuel' => 'diesel', 'cc' => 2800, 'traits' => $t( 'lujo, siete plazas y un 4x4 de primera', 'luxury, seven seats and a top-tier 4x4' ) ),
	'montero'   => array( 'brand' => 'Mitsubishi', 'name' => 'Mitsubishi Montero Sport', 'body' => 'suv', 'seats' => 7, 'traction' => '4x4', 'fuel' => 'diesel', 'cc' => 2400, 'traits' => $t( 'siete plazas y 4x4 capaz', 'seven seats and a capable 4x4' ) ),
	'outlander' => array( 'brand' => 'Mitsubishi', 'name' => 'Mitsubishi Outlander', 'body' => 'suv', 'seats' => 7, 'traction' => '4x2', 'fuel' => 'gasoline', 'cc' => 2500, 'traits' => $t( 'siete plazas y buen equipamiento', 'seven seats and good features' ) ),
	'x-trail'   => array( 'brand' => 'Nissan', 'name' => 'Nissan X-Trail', 'body' => 'suv', 'seats' => 7, 'traction' => '4x2', 'fuel' => 'gasoline', 'cc' => 2500, 'traits' => $t( 'espacio familiar y comodidad', 'family space and comfort' ) ),
	'xtrail'    => array( 'brand' => 'Nissan', 'name' => 'Nissan X-Trail', 'body' => 'suv', 'seats' => 7, 'traction' => '4x2', 'fuel' => 'gasoline', 'cc' => 2500, 'traits' => $t( 'espacio familiar y comodidad', 'family space and comfort' ) ),
	'kicks'     => array( 'brand' => 'Nissan', 'name' => 'Nissan Kicks', 'body' => 'suv', 'seats' => 5, 'traction' => '4x2', 'fuel' => 'gasoline', 'cc' => 1600, 'traits' => $t( 'tamaño compacto, bajo consumo y buen precio', 'compact size, low fuel use and a good price' ) ),
	'creta'     => array( 'brand' => 'Hyundai', 'name' => 'Hyundai Creta', 'body' => 'suv', 'seats' => 5, 'traction' => '4x2', 'fuel' => 'gasoline', 'cc' => 1500, 'traits' => $t( 'tamaño compacto y buen equipamiento', 'compact size and good features' ) ),
	'seltos'    => array( 'brand' => 'Kia', 'name' => 'Kia Seltos', 'body' => 'suv', 'seats' => 5, 'traction' => '4x2', 'fuel' => 'gasoline', 'cc' => 1600, 'traits' => $t( 'diseño juvenil y buen equipamiento', 'youthful design and good features' ) ),
	// Automóviles.
	'corolla'   => array( 'brand' => 'Toyota', 'name' => 'Toyota Corolla', 'body' => 'sedan', 'seats' => 5, 'traction' => '4x2', 'fuel' => 'gasoline', 'cc' => 1800, 'traits' => $t( 'confiabilidad, bajo mantenimiento y reventa', 'reliability, low maintenance and resale value' ) ),
	'yaris'     => array( 'brand' => 'Toyota', 'name' => 'Toyota Yaris', 'body' => 'car', 'seats' => 5, 'traction' => '4x2', 'fuel' => 'gasoline', 'cc' => 1500, 'traits' => $t( 'economía y confiabilidad', 'economy and reliability' ) ),
	'accent'    => array( 'brand' => 'Hyundai', 'name' => 'Hyundai Accent', 'body' => 'car', 'seats' => 5, 'traction' => '4x2', 'fuel' => 'gasoline', 'cc' => 1600, 'traits' => $t( 'buen precio y bajo consumo', 'a good price and low fuel use' ) ),
	'versa'     => array( 'brand' => 'Nissan', 'name' => 'Nissan Versa', 'body' => 'sedan', 'seats' => 5, 'traction' => '4x2', 'fuel' => 'gasoline', 'cc' => 1600, 'traits' => $t( 'espacio atrás y baúl grandes para su precio', 'a big back seat and trunk for the price' ) ),
	'sentra'    => array( 'brand' => 'Nissan', 'name' => 'Nissan Sentra', 'body' => 'sedan', 'seats' => 5, 'traction' => '4x2', 'fuel' => 'gasoline', 'cc' => 2000, 'traits' => $t( 'confort y espacio', 'comfort and space' ) ),
	'civic'     => array( 'brand' => 'Honda', 'name' => 'Honda Civic', 'body' => 'sedan', 'seats' => 5, 'traction' => '4x2', 'fuel' => 'gasoline', 'cc' => 2000, 'traits' => $t( 'manejo deportivo y confiabilidad', 'sporty handling and reliability' ) ),
	'elantra'   => array( 'brand' => 'Hyundai', 'name' => 'Hyundai Elantra', 'body' => 'sedan', 'seats' => 5, 'traction' => '4x2', 'fuel' => 'gasoline', 'cc' => 2000, 'traits' => $t( 'diseño y equipamiento', 'design and features' ) ),
	'mazda 3'   => array( 'brand' => 'Mazda', 'name' => 'Mazda 3', 'body' => 'car', 'seats' => 5, 'traction' => '4x2', 'fuel' => 'gasoline', 'cc' => 2000, 'traits' => $t( 'diseño, calidad interior y manejo', 'design, interior quality and handling' ) ),
	'rio'       => array( 'brand' => 'Kia', 'name' => 'Kia Rio', 'body' => 'car', 'seats' => 5, 'traction' => '4x2', 'fuel' => 'gasoline', 'cc' => 1400, 'traits' => $t( 'economía y buen equipamiento', 'economy and good features' ) ),
	'picanto'   => array( 'brand' => 'Kia', 'name' => 'Kia Picanto', 'body' => 'hatchback', 'seats' => 5, 'traction' => '4x2', 'fuel' => 'gasoline', 'cc' => 1200, 'traits' => $t( 'tamaño compacto y consumo muy bajo', 'compact size and very low fuel use' ) ),
	'i10'       => array( 'brand' => 'Hyundai', 'name' => 'Hyundai Grand i10', 'body' => 'hatchback', 'seats' => 5, 'traction' => '4x2', 'fuel' => 'gasoline', 'cc' => 1200, 'traits' => $t( 'tamaño compacto y consumo muy bajo', 'compact size and very low fuel use' ) ),
	'swift'     => array( 'brand' => 'Suzuki', 'name' => 'Suzuki Swift', 'body' => 'hatchback', 'seats' => 5, 'traction' => '4x2', 'fuel' => 'gasoline', 'cc' => 1200, 'traits' => $t( 'agilidad y consumo bajo', 'agility and low fuel use' ) ),
);

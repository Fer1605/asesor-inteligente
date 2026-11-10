<?php
/**
 * Perfil del cliente → qué vehículo conviene.
 *
 * BORRADOR propio, sin validar todavía con asesores de venta reales. Cada regla que se cumple suma
 * sus pesos por carrocería y aporta su porqué; las carrocerías de "avoid" se descartan.
 *
 * Condiciones (todas las que trae la regla deben cumplirse):
 *   people_min / people_max  cuántos viajan normalmente
 *   kids                     true = hay niños
 *   small_kids               true = hay un niño menor de 5 años (silla, carriola)
 *   use                      uno de: work, rough, city, highway
 *   cargo_min                toneladas que necesita cargar
 * Efectos:
 *   weights   carrocería => peso
 *   avoid     carrocerías que no conviene ofrecer
 *   prefer    atributos que suben en el orden (p. ej. tracción 4x4)
 *   reason / avoid_reason   el porqué, por idioma ({people} = personas)
 *
 * @package AsesorInteligente
 */

defined( 'ABSPATH' ) || defined( 'ASI_CLI_TESTS' ) || exit;

return array(
	array(
		'when'    => array( 'people_min' => 9 ),
		'weights' => array( 'microbus' => 1.0, 'bus' => 0.6 ),
		'avoid'   => array( 'sedan', 'hatchback', 'car', 'coupe', 'pickup', 'suv' ),
		'reason'  => array(
			'es' => 'Para {people} personas lo que funciona es un microbús: todos van sentados y con cinturón.',
			'en' => 'For {people} people a minibus is what works: everyone gets a seat and a belt.',
		),
		'avoid_reason' => array(
			'es' => 'Un carro o una SUV no alcanzan para {people}.',
			'en' => 'A car or an SUV is not enough for {people}.',
		),
	),
	array(
		'when'    => array( 'people_min' => 6, 'people_max' => 8 ),
		'weights' => array( 'suv' => 1.0, 'van' => 0.9, 'microbus' => 0.5 ),
		'avoid'   => array( 'sedan', 'hatchback', 'car', 'coupe', 'pickup' ),
		'reason'  => array(
			'es' => 'Siendo {people}, necesitas tercera fila de asientos: una SUV de 7 plazas o una van.',
			'en' => 'With {people} of you, you need a third row: a 7-seat SUV or a van.',
		),
		'avoid_reason' => array(
			'es' => 'No te recomendaría un sedán ni una pickup: no caben {people} cómodos.',
			'en' => 'I would not suggest a sedan or a pickup: {people} will not fit comfortably.',
		),
	),
	array(
		'when'    => array( 'people_min' => 3, 'people_max' => 5, 'kids' => true ),
		'weights' => array( 'suv' => 1.0, 'van' => 0.6, 'sedan' => 0.5, 'car' => 0.5, 'pickup' => 0.45, 'hatchback' => 0.35 ),
		'avoid'   => array( 'coupe', 'truck', 'panel' ),
		'reason'  => array(
			'es' => 'Con niños, una SUV es lo más práctico: espacio atrás para las sillas, baúl amplio y buena altura para subirlos.',
			'en' => 'With kids, an SUV is the most practical: room for car seats, a big trunk and an easy height to get them in.',
		),
	),
	array(
		'when'   => array( 'small_kids' => true ),
		'prefer' => array( 'doors' => array( 'min' => 4 ) ),
		'reason' => array(
			'es' => 'Con un niño pequeño conviene que tenga 4 puertas y anclajes ISOFIX para la silla.',
			'en' => 'With a small child you want 4 doors and ISOFIX anchors for the car seat.',
		),
	),
	array(
		'when'    => array( 'people_min' => 3, 'people_max' => 5, 'kids' => false ),
		'weights' => array( 'suv' => 0.9, 'sedan' => 0.8, 'car' => 0.8, 'pickup' => 0.6, 'hatchback' => 0.6 ),
		'avoid'   => array( 'coupe' ),
		'reason'  => array(
			'es' => 'Para {people} adultos te va bien una SUV o un sedán: los dos llevan 5 cómodos.',
			'en' => 'For {people} adults an SUV or a sedan works: both seat 5 comfortably.',
		),
	),
	array(
		'when'    => array( 'people_max' => 2 ),
		'weights' => array( 'hatchback' => 1.0, 'car' => 1.0, 'sedan' => 0.9, 'suv' => 0.7 ),
		'reason'  => array(
			'es' => 'Para una o dos personas, un automóvil o una SUV compacta gasta menos y se estaciona fácil.',
			'en' => 'For one or two people, a car or a compact SUV uses less fuel and is easy to park.',
		),
	),
	array(
		'when'    => array( 'use' => 'work' ),
		'weights' => array( 'pickup' => 1.2, 'panel' => 0.8 ),
		'reason'  => array(
			'es' => 'Como también es para trabajar y cargar, una pickup te da la palangana para herramientas o mercadería.',
			'en' => 'Since it is also for work and hauling, a pickup gives you the bed for tools or goods.',
		),
	),
	array(
		'when'    => array( 'use' => 'rough' ),
		'weights' => array( 'pickup' => 0.6, 'suv' => 0.5 ),
		'avoid'   => array( 'sedan', 'hatchback', 'car', 'coupe' ),
		'prefer'  => array( 'traction' => array( 'in' => array( '4x4', 'awd' ) ) ),
		'reason'  => array(
			'es' => 'Para caminos de terracería o finca, que sea 4x4 y con buena altura al piso.',
			'en' => 'For dirt roads or a farm, go for 4x4 and good ground clearance.',
		),
		'avoid_reason' => array(
			'es' => 'Un sedán no te lo recomiendo para terracería: es bajo y sufre en esos caminos.',
			'en' => 'I would not suggest a sedan for dirt roads: it sits low and suffers there.',
		),
	),
	array(
		'when'    => array( 'use' => 'city' ),
		'weights' => array( 'hatchback' => 0.5, 'car' => 0.5, 'sedan' => 0.4, 'suv' => 0.2 ),
		'prefer'  => array( 'transmission' => array( 'in' => array( 'auto' ) ) ),
		'reason'  => array(
			'es' => 'En ciudad se agradece uno automático y de tamaño manejable para el tráfico y el parqueo.',
			'en' => 'In the city an automatic of manageable size makes traffic and parking easier.',
		),
	),
	array(
		'when'    => array( 'use' => 'highway' ),
		'weights' => array( 'suv' => 0.3, 'sedan' => 0.3 ),
		'reason'  => array(
			'es' => 'Para carretera conviene buen motor y estabilidad.',
			'en' => 'For highway driving you want a good engine and stability.',
		),
	),
	array(
		'when'    => array( 'cargo_min' => 1.5 ),
		'weights' => array( 'truck' => 1.5 ),
		'avoid'   => array( 'sedan', 'hatchback', 'car', 'coupe', 'suv' ),
		'reason'  => array(
			'es' => 'Para mover {cargo} toneladas lo indicado es un camión.',
			'en' => 'To move {cargo} tons a truck is the right choice.',
		),
	),
);

<?php
/**
 * Conocimiento del idioma español para el buscador.
 *
 * Las listas de comparación van normalizadas (minúsculas, sin acentos, con ñ).
 * Las respuestas van escritas normal, con acentos. Ninguna lista nombra un sitio concreto.
 *
 * Marcadores en respuestas:
 *   {n}        número de resultados ("3 opciones", "1 opción")
 *   {label}    lo que busca el usuario, con sus términos reales ("montacargas eléctricos")
 *   {query}    el texto que escribió
 *   {en_pais}  " en El Salvador" solo si el sitio es multipaís; si no, vacío
 *   {site}     nombre del sitio
 *   {title}    título de un resultado
 *   {refine}   lo que se agregó al refinar
 *   {facets}   lista de líneas o categorías ("tractores, implementos y repuestos")
 *   {saludo}   "Buenos días" / "Buenas tardes" / "Buenas noches"
 *
 * @package AsesorInteligente
 */

if ( ! defined( 'ABSPATH' ) && ! defined( 'ASI_CLI_TESTS' ) ) {
	exit;
}

return array(

	'code' => 'es',

	'stopwords' => array(
		'a', 'al', 'ante', 'con', 'contra', 'de', 'del', 'desde', 'en', 'entre', 'hacia', 'hasta', 'para', 'por', 'segun', 'sin', 'sobre', 'tras',
		'el', 'la', 'los', 'las', 'lo', 'un', 'una', 'unos', 'unas', 'y', 'e', 'o', 'u', 'ni', 'que', 'se', 'su', 'sus', 'mi', 'mis', 'tu', 'tus',
		'me', 'te', 'le', 'les', 'nos', 'yo', 'usted', 'ustedes', 'este', 'esta', 'estos', 'estas', 'ese', 'esa', 'esos', 'esas', 'aqui', 'alli',
		'muy', 'mas', 'tambien', 'ya', 'si', 'no', 'como', 'cual', 'cuales', 'donde', 'cuando', 'porque', 'pues', 'entonces', 'algo', 'alguno', 'alguna',
		'algunos', 'algunas', 'otro', 'otra', 'otros', 'otras', 'todo', 'toda', 'todos', 'todas', 'cada', 'es', 'son', 'sea', 'sean', 'ser', 'esta', 'estan',
		'hay', 'tipo', 'tipos', 'cosa', 'cosas', 'favor', 'porfa', 'porfavor', 'gracias', 'hola', 'bueno', 'buena', 'buenas', 'buenos',
	),

	// Palabras de petición: indican intención de buscar, pero no son lo que se busca.
	'fillers' => array(
		'quiero', 'quisiera', 'queria', 'busco', 'buscaba', 'buscando', 'necesito', 'necesitaba', 'ocupo', 'tienen', 'tiene', 'tendran', 'tendra',
		'venden', 'vende', 'manejan', 'ofrecen', 'hay', 'ver', 'mostrar', 'muestrame', 'muestreme', 'ensename', 'dame', 'deme', 'me', 'gustaria',
		'interesa', 'interesan', 'saber', 'informacion', 'info', 'disponible', 'disponibles', 'opcion', 'opciones', 'producto', 'productos',
		'podrian', 'puede', 'pueden', 'podria', 'ayudar', 'ayudame', 'ayudeme', 'conseguir', 'encontrar', 'comprar', 'adquirir', 'cotizar', 'precio', 'precios',
		'cuanto', 'cuesta', 'cuestan', 'vale', 'valen', 'costo', 'costos',
		// Marcas de refinamiento: dicen cómo filtrar, no qué buscar.
		'solo', 'solamente', 'unicamente', 'nada', 'pero', 'mejor', 'ahora',
	),

	// Terminaciones de género intercambiables al comparar ("automático" encuentra "automática").
	'gender_endings' => array( 'o' => 'a', 'a' => 'o' ),

	// Palabras que piden precio.
	'price_words' => array( 'precio', 'precios', 'cuanto cuesta', 'cuanto cuestan', 'cuanto vale', 'cuanto valen', 'costo', 'costos', 'valor', 'tarifa' ),

	// Montos del cliente (ASI_Money::extract_budget). Frases sin acentos, en minúsculas.
	'profile_chips' => array( 'Somos 4 con niños', 'Es para trabajar', 'Para la ciudad', 'Para la finca' ),

	'similar_markers' => array( 'parecido a', 'parecida a', 'parecidos a', 'parecidas a', 'parecido al', 'parecida al', 'parecidos al', 'similar a', 'similar al', 'similares a', 'similares al', 'alternativa a', 'alternativa al', 'alternativas a', 'alternativas al', 'competencia del', 'competencia de la', 'competencia de', 'en vez del', 'en vez de la', 'en lugar del', 'en lugar de la', 'igual al', 'igual a la', 'algo como', 'como el', 'como la', 'tipo el', 'tipo la', 'estilo del', 'estilo de la', 'que se parezca a', 'que se parezca al', 'se parezca al', 'se parezca a' ),
	'similar_strong'  => array( 'parecid', 'similar', 'alternativa', 'competencia', 'en vez', 'en lugar', 'parezca' ),
	'similar_stops'   => array( 'que', 'pero', 'y', 'o', 'me', 'te', 'le', 'recomiendas', 'recomendas', 'recomienda', 'recomendarias', 'para', 'con', 'porque', 'por', 'mas', 'menos', 'sin', 'hasta', 'de', 'del', 'en', 'cual', 'algo', 'tienes', 'tienen', 'hay' ),
	'similar_chips'      => array( 'Compáralas', 'Más barato', 'Calcular cuotas' ),
	'similar_body_chips' => array( 'Es una pickup', 'Es una SUV', 'Es un sedán' ),

	'quote_words'       => array( 'me interesa', 'me interesan', 'me gusta ese', 'me gusta esa', 'lo quiero', 'la quiero', 'me lo llevo', 'me la llevo', 'cotizacion', 'cotizar', 'cotizame', 'cotizamelo', 'cotizamela', 'cotizalo', 'cotizala', 'cotizeme', 'mandame la cotizacion', 'enviame la cotizacion', 'quiero la cotizacion', 'ficha tecnica', 'la ficha', 'el pdf', 'en pdf', 'proforma', 'quote' ),
	'compare_pdf_words' => array( 'comparacion en pdf', 'comparativo en pdf', 'pdf de la comparacion', 'pdf comparativo', 'mandame la comparacion', 'enviame la comparacion', 'mandame el comparativo', 'enviame el comparativo', 'la comparacion por correo', 'comparacion por correo', 'comparativo', 'manda la comparacion' ),
	'finance_words'     => array( 'cuota', 'cuotas', 'mensualidad', 'mensualidades', 'al mes', 'mensual', 'mensuales', 'financiamiento', 'financiar', 'financiado', 'enganche', 'a plazos', 'plazo', 'credito', 'cuanto pagaria', 'cuanto pagaria al mes', 'calcular cuotas', 'calcula las cuotas', 'calculame', 'monthly' ),
	'yes_words'         => array( 'si', 'sii', 'si por favor', 'si porfa', 'si dale', 'dale', 'va', 'sale', 'ok', 'okay', 'claro', 'claro que si', 'me parece', 'me parece bien', 'perfecto', 'de una', 'por favor', 'porfa', 'esta bien', 'si me interesa', 'enviamela', 'mandamela', 'si enviamela', 'si mandamela', 'hagale', 'yes', 'sure', 'please' ),
	'cancel_words'      => array( 'cancelar', 'cancela', 'mejor no', 'no gracias', 'ya no', 'olvidalo', 'despues', 'luego', 'cancel', 'never mind' ),
	'which_chips'       => array( 'El primero', 'El segundo', 'El tercero' ),
	'after_quote_chips' => array( 'Calcular cuotas', 'Ver otras opciones' ),
	'finance_chips'     => array( 'Sí, envíamela', 'Con 30 % de enganche', 'A 60 meses' ),

	'target_fillers'    => array( 'te dije', 'como te dije', 'de estos', 'de estas', 'de esos', 'de esas', 'quiero', 'quisiera', 'me puedes', 'me podrias', 'por favor', 'porfa', 'la cotizacion de', 'cotizacion de', 'las cotizaciones de', 'cotizaciones de', 'tambien', 'y tambien', 'mejor' ),

	'not_data_words'    => array( 'mas opciones', 'otras opciones', 'ver mas', 'ver otras', 'ninguna', 'ninguno', 'no elegi', 'no escogi', 'no quiero esa', 'no quiero ese', 'otra cosa', 'sugiereme', 'sugiere', 'recomiendame', 'muestrame', 'ensename', 'que mas tienes', 'que otras' ),
	'more_options_words' => array( 'mas opciones', 'otras opciones', 'ver mas', 'ver otras', 'ninguna', 'ninguno', 'no elegi', 'no escogi', 'que mas tienes', 'que otras', 'otras', 'mas', 'sugiereme', 'suguiereme', 'sugiere', 'sugerencias', 'sugiereme vehiculos', 'recomiendame mas', 'muestrame mas' ),
	'not_name_words'    => array( 'hola', 'buenas', 'gracias', 'porfavor', 'por favor', 'ok', 'opciones', 'vehiculos', 'carros', 'autos', 'camionetas', 'pickup', 'suv', 'familia', 'trabajo', 'finca', 'precio', 'cuotas', 'cotizacion', 'mas', 'otras', 'ninguna' ),

	'money_words' => array(
		'max'          => array( 'menos de', 'menor a', 'menor de', 'hasta', 'maximo', 'max', 'no mas de', 'por debajo de', 'que no pase de', 'que no pase los', 'abajo de', 'tope de', 'que no supere', 'que no supere los', 'no mayor a' ),
		'min'          => array( 'mas de', 'mayor a', 'mayor de', 'desde', 'minimo', 'arriba de', 'por encima de', 'no menos de' ),
		'between'      => array( 'entre', 'de', 'desde' ),
		'between_join' => array( 'y', 'a', 'hasta' ),
		'budget'       => array( 'presupuesto', 'mi presupuesto', 'tengo', 'cuento con', 'dispongo de', 'puedo pagar', 'puedo gastar', 'quiero gastar', 'pienso gastar', 'pagar' ),
		'around'       => array( 'alrededor de', 'aproximadamente', 'aprox', 'como de', 'unos', 'unas', 'cerca de', 'por ahi de', 'mas o menos' ),
	),

	// Rangos de precio: el número se captura en el grupo 1.
	'price_max_patterns' => array(
		'/(?:menos de|menor a|hasta|maximo|max|no mas de|por debajo de|que no pase de|abajo de)\s*(?:q|\$|usd|lps|l|c|b\/|crc|₡)?\s*([0-9][0-9.,]*\s*(?:mil|k)?)/',
	),
	'price_min_patterns' => array(
		'/(?:mas de|mayor a|desde|minimo|min|arriba de|por encima de)\s*(?:q|\$|usd|lps|l|c|b\/|crc|₡)?\s*([0-9][0-9.,]*\s*(?:mil|k)?)/',
	),

	// Grupos de sinónimos generales (no de un sitio). Solo se usan los que existen en el vocabulario del sitio.
	'synonyms' => array(
		array( 'repuesto', 'refaccion', 'pieza', 'parte', 'recambio' ),
		array( 'usado', 'seminuevo', 'segunda mano' ),
		array( 'camion', 'troca', 'camioneta' ),
		array( 'pickup', 'pick up', 'picop', 'palangana' ),
		array( 'carro', 'auto', 'automovil', 'vehiculo', 'coche' ),
		array( 'montacarga', 'montacargas', 'carretilla elevadora', 'elevador de carga' ),
		array( 'computadora', 'ordenador', 'pc', 'laptop', 'portatil' ),
		array( 'celular', 'telefono movil', 'smartphone', 'movil' ),
		array( 'llanta', 'neumatico', 'caucho', 'cubierta' ),
		array( 'aceite', 'lubricante' ),
		array( 'fertilizante', 'abono' ),
		array( 'semilla', 'simiente' ),
		array( 'bomba', 'motobomba' ),
		array( 'generador', 'planta electrica', 'planta de luz' ),
		array( 'excavadora', 'retroexcavadora', 'retro' ),
		array( 'riego', 'irrigacion' ),
		array( 'electrico', 'bateria' ),
		array( 'diesel', 'gasoil', 'gasoleo' ),
		array( 'gasolina', 'nafta' ),
		array( 'barato', 'economico', 'accesible' ),
		array( 'renta', 'alquiler', 'arrendamiento', 'arrendar', 'rentar', 'alquilar' ),
		array( 'venta', 'vender', 'comprar', 'compra' ),
		array( 'mantenimiento', 'servicio tecnico', 'reparacion', 'taller' ),
		array( 'capacitacion', 'curso', 'entrenamiento', 'formacion' ),
		array( 'ropa', 'prenda', 'vestimenta' ),
		array( 'zapato', 'calzado' ),
		array( 'playera', 'camiseta', 'remera', 'polera' ),
		// Para quién: el sitio suele decir "masculino" o "dama" y la gente escribe "hombre" o "mujer".
		array( 'hombre', 'masculino', 'caballero', 'varon', 'hombres', 'caballeros' ),
		array( 'mujer', 'femenino', 'dama', 'mujeres', 'damas' ),
		array( 'niño', 'niña', 'infantil', 'niños', 'kids', 'nene' ),
		array( 'bebe', 'bebes', 'baby' ),
	),

	// Saludos y cierres de conversación (normalizados).
	'greetings' => array( 'hola', 'holi', 'buenas', 'buenos dias', 'buen dia', 'buenas tardes', 'buenas noches', 'que tal', 'saludos', 'hey', 'alo', 'que onda' ),

	// Pedir más resultados.
	'more' => array( 'mas', 'mas opciones', 'ver mas', 'muestrame mas', 'otras', 'otros', 'otras opciones', 'siguiente', 'siguientes', 'que mas tienen', 'algo mas', 'mas resultados', 'sigue', 'continua' ),

	// Referirse a un resultado ya mostrado.
	'ordinals' => array(
		'primero' => 1, 'primera' => 1, '1' => 1, 'segundo' => 2, 'segunda' => 2, '2' => 2, 'tercero' => 3, 'tercera' => 3, '3' => 3,
		'cuarto' => 4, 'cuarta' => 4, '4' => 4, 'quinto' => 5, 'quinta' => 5, '5' => 5, 'sexto' => 6, 'sexta' => 6, '6' => 6,
		'septimo' => 7, 'septima' => 7, '7' => 7, 'octavo' => 8, 'octava' => 8, '8' => 8, 'ultimo' => -1, 'ultima' => -1,
	),
	'reference_markers' => array( 'el', 'la', 'ese', 'esa', 'este', 'esta', 'numero', 'opcion', 'del', 'de la', 'sobre el', 'sobre la', 'info del', 'info de la', 'mas del', 'mas de la' ),
	'reference_alone'   => array( 'ese', 'esa', 'ese mismo', 'esa misma', 'ese me interesa', 'esa me interesa', 'me interesa ese', 'me interesa esa', 'cuentame mas', 'mas detalles', 'mas informacion de ese', 'detalles' ),

	// Pedir el listado completo ("¿qué zonas tienen?", "todas las sucursales").
	'list_words' => array( 'todas', 'todos', 'lista', 'listado', 'cuales', 'cuantas', 'cuantos', 'zonas', 'que zonas', 'todas las' ),

	// Palabras genéricas de lugar: no nombran una sucursal concreta.
	'place_words' => array( 'tienda', 'tiendas', 'sucursal', 'sucursales', 'agencia', 'agencias', 'local', 'locales', 'ubicacion', 'ubicaciones', 'oficina', 'oficinas', 'punto', 'puntos', 'entrega', 'direccion', 'zona', 'zonas', 'horario', 'horarios', 'abierto', 'abren', 'cierran', 'fisica', 'cerca', 'mapa', 'estan', 'queda', 'quedan', 'ubicados' ),

	// Palabras que marcan que el mensaje refina la búsqueda anterior.
	'refine_markers' => array( 'y', 'solo', 'solamente', 'nada mas', 'pero', 'que sea', 'que sean', 'mejor', 'y en', 'de marca', 'en color', 'con', 'sin' ),

	// "Que no sea eléctrico", "excepto los usados": lo que sigue se excluye de los resultados.
	'negation_markers' => array( 'que no sea', 'que no sean', 'que no tenga', 'que no tengan', 'no quiero', 'no me muestres', 'no me interesan', 'no me interesa', 'excepto', 'salvo', 'menos los', 'menos las', 'menos el', 'menos la', 'pero no', 'no sea', 'no sean', 'sin incluir' ),

	// Palabras que suelen estar en la frase que responde cada pregunta informativa.
	'evidence' => array(
		'hours'     => array( 'horario', 'horarios', 'atencion', 'abierto', 'abrimos', 'cerramos', 'lunes', 'martes', 'miercoles', 'jueves', 'viernes', 'sabado', 'domingo', 'am', 'pm', 'hrs', 'horas', 'feriado' ),
		'contact'   => array( 'telefono', 'tel', 'pbx', 'whatsapp', 'correo', 'email', 'escribenos', 'llamanos', 'contacto', 'atencion', 'cliente', 'numero', 'celular' ),
		'location'  => array( 'direccion', 'ubicado', 'ubicados', 'ubicada', 'calle', 'avenida', 'zona', 'km', 'kilometro', 'boulevard', 'bulevar', 'colonia', 'centro', 'comercial', 'local', 'edificio', 'carretera' ),
		'about'     => array( 'somos', 'empresa', 'mision', 'vision', 'valores', 'historia', 'fundada', 'fundado', 'anos', 'experiencia', 'lider', 'lideres', 'compromiso', 'nacimos', 'trayectoria', 'dedicamos', 'ofrecemos' ),
		'quote'     => array( 'cotizacion', 'cotizar', 'formulario', 'asesor', 'solicitud', 'precio' ),
		'financing' => array( 'financiamiento', 'credito', 'cuotas', 'plazo', 'plazos', 'enganche', 'tasa', 'leasing', 'banco' ),
		'jobs'      => array( 'vacante', 'vacantes', 'cv', 'curriculum', 'plaza', 'plazas', 'empleo', 'talento', 'trabaja' ),
		'human'     => array( 'asesor', 'asesores', 'telefono', 'whatsapp', 'correo', 'llamanos', 'escribenos' ),
	),

	// Preguntas sobre un dato de un producto ("¿cuánto pesa?", "¿qué potencia tiene?").
	// fact_words: palabra de la pregunta => palabras que puede tener el nombre del dato en el sitio.
	// brand, category, code y price son datos propios de la tarjeta.
	'fact_words' => array(
		'precio' => array( 'price' ), 'cuesta' => array( 'price' ), 'cuestan' => array( 'price' ), 'vale' => array( 'price' ), 'valen' => array( 'price' ), 'costo' => array( 'price' ),
		'marca' => array( 'brand', 'marca', 'fabricante' ), 'fabricante' => array( 'brand', 'fabricante', 'marca' ), 'fabrica' => array( 'brand', 'fabricante' ),
		'codigo' => array( 'code', 'codigo', 'sku', 'referencia' ), 'sku' => array( 'code', 'sku' ), 'referencia' => array( 'code', 'referencia' ), 'modelo' => array( 'modelo', 'code' ),
		'categoria' => array( 'category', 'categoria', 'linea' ), 'linea' => array( 'category', 'linea' ),
		'pesa' => array( 'peso' ), 'peso' => array( 'peso' ), 'pesado' => array( 'peso' ),
		'mide' => array( 'medida', 'dimension', 'tamano', 'largo', 'ancho', 'alto', 'altura' ), 'medida' => array( 'medida', 'dimension', 'tamano' ), 'tamano' => array( 'tamano', 'medida', 'dimension' ), 'grande' => array( 'tamano', 'medida', 'dimension' ), 'alto' => array( 'alto', 'altura' ), 'ancho' => array( 'ancho' ), 'largo' => array( 'largo' ),
		'color' => array( 'color' ), 'colores' => array( 'color' ), 'talla' => array( 'talla', 'tamano' ), 'tallas' => array( 'talla' ),
		'material' => array( 'material', 'composicion' ), 'hecho' => array( 'material', 'composicion' ), 'fabricado' => array( 'material' ),
		'garantia' => array( 'garantia' ), 'capacidad' => array( 'capacidad', 'carga' ), 'carga' => array( 'capacidad', 'carga' ),
		'potencia' => array( 'potencia', 'hp', 'fuerza', 'caballo' ), 'caballos' => array( 'potencia', 'hp', 'caballo' ), 'hp' => array( 'potencia', 'hp' ),
		'motor' => array( 'motor' ), 'consumo' => array( 'consumo', 'rendimiento' ), 'consume' => array( 'consumo' ), 'rendimiento' => array( 'rendimiento', 'consumo' ),
		'bateria' => array( 'bateria', 'autonomia' ), 'dura' => array( 'duracion', 'autonomia', 'garantia' ), 'duracion' => array( 'duracion', 'autonomia' ),
		'contenido' => array( 'contenido', 'presentacion', 'volumen' ), 'presentacion' => array( 'presentacion', 'contenido' ), 'ml' => array( 'contenido', 'volumen', 'presentacion' ),
		'ingredientes' => array( 'ingrediente', 'composicion' ), 'aroma' => array( 'aroma', 'fragancia' ), 'fragancia' => array( 'fragancia', 'aroma' ),
		'velocidad' => array( 'velocidad' ), 'voltaje' => array( 'voltaje', 'voltio' ), 'combustible' => array( 'combustible' ), 'transmision' => array( 'transmision' ),
		'año' => array( 'ano', 'modelo' ), 'ano' => array( 'ano', 'modelo' ), 'origen' => array( 'origen', 'pais' ),
	),
	// Verbos de pregunta de sí o no sobre un producto ya mostrado ("¿tiene garantía?", "¿trae cabina?").
	'yes_no_verbs' => array( 'tiene', 'trae', 'incluye', 'viene con', 'cuenta con', 'lleva', 'usa', 'funciona con', 'sirve para', 'es de', 'es para', 'acepta' ),
	// Palabras de pregunta que no son lo que se busca.
	'question_words' => array( 'cuanto', 'cuanta', 'cuantos', 'cuantas', 'cual', 'cuales', 'que', 'como', 'de que', 'es', 'son', 'tiene', 'tienen', 'viene', 'vienen', 'sabes', 'dime', 'decirme', 'me', 'dices', 'saber', 'quisiera' ),

	// Comparar dos productos.
	'compare_markers'   => array( 'diferencia entre', 'diferencias entre', 'diferencia de', 'diferencia hay entre', 'compara', 'comparar', 'comparame', 'compareme', 'comparalo', 'comparala', 'comparalos', 'comparalas', 'comparelo', 'comparela', 'comparacion entre', 'comparacion de', 'vs', 'versus', 'cual es mejor', 'que es mejor', 'cual me recomiendas entre', 'cual conviene mas' ),
	'compare_splitters' => array( 'y', 'o', 'con', 'vs', 'versus', 'contra', 'frente a' ),
	'compare_both'      => array( 'compararlos', 'compararlas', 'comparalos', 'comparalas', 'comparame esos', 'la diferencia', 'que diferencia hay', 'cual es mejor', 'cual me conviene' ),

	// El más barato o el más caro.
	'cheapest_markers'  => array( 'mas barato', 'mas barata', 'mas baratos', 'mas baratas', 'mas economico', 'mas economica', 'mas economicos', 'mas economicas', 'menor precio', 'precio mas bajo', 'mas accesible', 'mas accesibles' ),
	'priciest_markers'  => array( 'mas caro', 'mas cara', 'mas caros', 'mas caras', 'mayor precio', 'precio mas alto', 'mas costoso', 'mas costosa', 'de mas precio' ),

	// Tratamiento de usted: se aplica a las plantillas (nunca a nombres de productos). Frases primero.
	'usted' => array(
		'te refieres' => 'se refiere', 'Te refieres' => 'Se refiere', 'quisiste' => 'quiso', 'pediste' => 'pidió', 'estás' => 'está', 'tú' => 'usted', 'Tú' => 'Usted',
		'Cuéntame' => 'Cuénteme', 'cuéntame' => 'cuénteme', 'Escríbeme' => 'Escríbame', 'escríbeme' => 'escríbame', 'Escríbenos' => 'Escríbanos', 'escríbenos' => 'escríbanos',
		'llámanos' => 'llámenos', 'Llámanos' => 'Llámenos', 'Dime' => 'Dígame', 'dime' => 'dígame', 'Dame' => 'Deme', 'dame' => 'deme', 'Mira' => 'Mire', 'mira' => 'mire',
		'Prueba' => 'Pruebe', 'prueba' => 'pruebe', 'revisa' => 'revise', 'Revisa' => 'Revise', 'Solicita' => 'Solicite', 'solicita' => 'solicite', 'Escribe' => 'Escriba', 'escribe' => 'escriba',
		'Intenta' => 'Intente', 'intenta' => 'intente', 'Espera' => 'Espere', 'vuelve' => 'vuelva', 'Activa' => 'Active', 'Vas' => 'Va', 'Pregúntame' => 'Pregúnteme', 'pregúntame' => 'pregúnteme',
		'buscas' => 'busca', 'Buscas' => 'Busca', 'necesitas' => 'necesita', 'quieres' => 'quiere', 'Quieres' => 'Quiere', 'tengas' => 'tenga', 'tienes' => 'tiene', 'Tienes' => 'Tiene',
		'puedes' => 'puede', 'Puedes' => 'Puede', 'prefieres' => 'prefiere', 'Prefieres' => 'Prefiere', 'das' => 'da', 'refieres' => 'refiere', 'escribes' => 'escribe', 'describes' => 'describe', 'eliges' => 'elige',
		'saludarte' => 'saludarle', 'ayudarte' => 'ayudarle', 'servirte' => 'servirle', 'darte' => 'darle', 'mostrarte' => 'mostrarle', 'contarte' => 'contarle', 'confirmarte' => 'confirmarle', 'ayudarlo' => 'ayudarle',
		'te' => 'le', 'Te' => 'Le', 'tu' => 'su', 'Tu' => 'Su', 'tus' => 'sus', 'Tus' => 'Sus', 'bienvenido' => 'bienvenido',
	),

	// Preguntas por el propio sitio ("¿qué es {nombre del sitio}?", "cuéntame de {nombre}"): van a la página de quiénes somos.
	// about_site_words: lo que puede acompañar al nombre en esa pregunta. company_words: partes genéricas de un nombre
	// de empresa, que solas no nombran al sitio ("grupo", "almacén").
	'about_site_words' => array( 'que', 'quien', 'quienes', 'es', 'son', 'fue', 'cuentame', 'cuentanos', 'hablame', 'dime', 'informacion', 'info', 'acerca', 'sobre', 'conocer', 'saber', 'mas', 'poco', 'historia' ),
	'company_words'    => array( 'grupo', 'almacen', 'almacenes', 'tienda', 'tiendas', 'empresa', 'empresas', 'corporacion', 'compania', 'distribuidora', 'comercial', 'comercializadora', 'industrias', 'sa', 'inc', 'llc', 'ltda', 'cia', 'y' ),

	// Intenciones informativas. anchors: palabras que tienen que aparecer. keywords: cómo se llama la página que responde.
	'intents' => array(
		'greeting' => array(
			'anchors'  => array( 'hola', 'holi', 'buenas', 'buenos dias', 'buen dia', 'buenas tardes', 'buenas noches', 'que tal', 'saludos', 'hey', 'que onda' ),
			'training' => array( 'hola', 'hola buenas', 'buenos dias', 'buenas tardes como estan', 'que tal', 'hola que tal', 'saludos', 'hey hola' ),
		),
		'thanks' => array(
			'anchors'  => array( 'gracias', 'muchas gracias', 'mil gracias', 'te agradezco', 'le agradezco', 'agradecido', 'agradecida', 'excelente gracias', 'perfecto gracias' ),
			'training' => array( 'gracias', 'muchas gracias', 'ok gracias', 'perfecto gracias', 'te agradezco mucho', 'mil gracias por la ayuda' ),
		),
		'goodbye' => array(
			'anchors'  => array( 'adios', 'hasta luego', 'nos vemos', 'bye', 'chao', 'chau', 'hasta pronto', 'eso es todo', 'es todo' ),
			'training' => array( 'adios', 'hasta luego', 'nos vemos', 'chao gracias', 'eso es todo', 'bye' ),
		),
		'help' => array(
			'anchors'  => array( 'ayuda', 'ayudame', 'que puedes hacer', 'que haces', 'como funciona', 'quien eres', 'que eres', 'como te uso', 'como busco', 'que sabes' ),
			'training' => array( 'ayuda', 'que puedes hacer', 'como funciona esto', 'quien eres', 'en que me puedes ayudar', 'como busco' ),
		),
		'contact' => array(
			'anchors'  => array( 'contacto', 'contactar', 'contactarlos', 'contactarme', 'contactenos', 'contactanos', 'comunicar', 'comunicarme', 'telefono', 'telefonos', 'numero de telefono', 'llamar', 'llamarles', 'whatsapp', 'correo', 'email', 'mail', 'escribirles' ),
			'keywords' => array( 'contacto', 'contactenos', 'contactanos', 'contact' ),
			'training' => array( 'como los contacto', 'cual es su telefono', 'numero de whatsapp', 'quiero comunicarme con ustedes', 'correo de contacto', 'donde les puedo llamar' ),
		),
		'location' => array(
			'anchors'  => array( 'donde estan', 'donde quedan', 'donde se ubican', 'ubicacion', 'ubicaciones', 'direccion', 'direcciones', 'sucursal', 'sucursales', 'agencia', 'agencias', 'tienda fisica', 'tiendas', 'tienda en', 'tienen tienda', 'oficina', 'oficinas', 'mapa', 'como llego', 'cerca de mi', 'zona', 'zonas', 'punto de entrega', 'puntos de entrega' ),
			'keywords' => array( 'sucursales', 'ubicaciones', 'agencias', 'tiendas', 'oficinas', 'donde estamos', 'contacto' ),
			'training' => array( 'donde estan ubicados', 'donde quedan sus sucursales', 'direccion de la tienda', 'tienen agencias', 'como llego a sus oficinas', 'ubicacion' ),
		),
		'hours' => array(
			'anchors'  => array( 'horario', 'horarios', 'a que hora', 'abren', 'cierran', 'atienden', 'hora de atencion', 'dias de atencion' ),
			'keywords' => array( 'horarios', 'horario', 'sucursales', 'contacto' ),
			'training' => array( 'cual es su horario', 'a que hora abren', 'atienden los sabados', 'horario de atencion', 'a que hora cierran' ),
		),
		'about' => array(
			'anchors'  => array( 'quienes son', 'quienes somos', 'nosotros', 'acerca de', 'sobre ustedes', 'historia', 'la empresa', 'su empresa', 'trayectoria', 'a que se dedican', 'que hacen', 'mision', 'mision y vision', 'vision y mision', 'su vision', 'sus valores', 'filosofia', 'conocerlos', 'conocer mas de ustedes' ),
			'keywords' => array( 'quienes somos', 'sobre nosotros', 'nosotros', 'acerca de', 'conocenos', 'mision', 'vision', 'historia', 'empresa', 'about' ),
			'training' => array( 'quienes son ustedes', 'a que se dedican', 'historia de la empresa', 'hablame de la empresa', 'que hacen', 'cual es su mision', 'mision y vision', 'sus valores' ),
		),
		'quote' => array(
			'anchors'  => array( 'cotizar', 'cotizacion', 'cotizaciones', 'presupuesto', 'proforma', 'cotizame' ),
			'keywords' => array( 'cotizar', 'cotizacion', 'cotiza', 'solicitar cotizacion', 'contacto' ),
			'training' => array( 'quiero cotizar', 'necesito una cotizacion', 'me pueden cotizar', 'pedir presupuesto', 'proforma' ),
		),
		'financing' => array(
			'anchors'  => array( 'financiamiento', 'financiar', 'financiado', 'credito', 'creditos', 'cuotas', 'leasing', 'arrendamiento financiero', 'plazos', 'pagar en partes' ),
			'keywords' => array( 'financiamiento', 'financiera', 'credito', 'leasing' ),
			'training' => array( 'tienen financiamiento', 'puedo pagar en cuotas', 'dan credito', 'opciones de financiamiento', 'leasing' ),
		),
		'jobs' => array(
			'anchors'  => array( 'empleo', 'empleos', 'trabajo', 'trabajar con ustedes', 'vacante', 'vacantes', 'plaza', 'plazas', 'bolsa de trabajo', 'carreras', 'curriculum', 'cv' ),
			'keywords' => array( 'empleos', 'trabaja con nosotros', 'bolsa de trabajo', 'carreras', 'vacantes' ),
			'training' => array( 'tienen vacantes', 'quiero trabajar con ustedes', 'bolsa de trabajo', 'donde mando mi cv' ),
		),
		'human' => array(
			'anchors'  => array( 'asesor', 'asesora', 'vendedor', 'vendedora', 'agente', 'una persona', 'un humano', 'ejecutivo', 'ejecutiva', 'representante' ),
			'keywords' => array( 'contacto', 'contactenos', 'asesor' ),
			'training' => array( 'quiero hablar con un asesor', 'me comunica con un vendedor', 'hablar con una persona', 'necesito un agente' ),
		),
	),

	// Plurales y palabras de apoyo para las respuestas.
	'words' => array(
		'option'   => 'opción',
		'options'  => 'opciones',
		'and'      => 'y',
		'morning'  => 'Buenos días',
		'afternoon' => 'Buenas tardes',
		'evening'  => 'Buenas noches',
		'in'       => 'en',
		'more_chip' => 'Ver más opciones',
		'or'        => 'o',
		'price'     => 'Precio',
		'brand'     => 'Marca',
		'category'  => 'Categoría',
		'code'      => 'Código',
		'versus'    => 'vs',
		'among'     => 'en',
		'fact_of'   => 'de',
	),

	// Respuestas. Se elige una variante al azar evitando las usadas hace poco.
	'responses' => array(
		'greeting' => array(
			'¡{saludo}! Soy el asistente de {site}. ¿Qué estás buscando hoy?',
			'¡Hola! Cuéntame qué necesitas y te ayudo a encontrarlo.',
			'¡{saludo}! ¿Buscas algún producto o información en particular?',
			'¡Hola, qué gusto saludarte! Escríbeme lo que buscas con tus palabras y lo buscamos juntos.',
			'¡{saludo}! Estoy aquí para ayudarte a encontrar lo que necesitas{en_pais}. ¿Por dónde empezamos?',
		),
		'greeting_prefix' => array( '¡Hola!', '¡{saludo}!', '¡Hola, qué gusto!', '¡{saludo}, bienvenido!' ),
		'thanks' => array(
			'¡Con mucho gusto! ¿Te ayudo con algo más?',
			'¡Para eso estoy! Si necesitas algo más, aquí sigo.',
			'¡De nada! Cuando quieras seguimos buscando.',
			'¡Un placer ayudarte! ¿Hay algo más que quieras ver?',
		),
		'goodbye' => array(
			'¡Hasta pronto! Que tengas un excelente día.',
			'¡Gracias por visitarnos! Aquí estaré cuando me necesites.',
			'¡Nos vemos! Fue un gusto ayudarte.',
		),
		'help' => array(
			'Puedo ayudarte a encontrar productos, servicios e información de {site}{en_pais}. Escríbeme lo que buscas con tus palabras; por ejemplo: {facets}.',
			'Soy el asistente de {site}. Busco por ti en todo el sitio{en_pais}: productos, servicios, contacto, sucursales y más. Prueba con algo como {facets}.',
		),
		'results' => array(
			'¡Claro! Encontré {n} de {label}{en_pais}.',
			'Mira, tengo {n} de {label}{en_pais}.',
			'Aquí tienes {n} relacionadas con {label}.',
			'Buenas noticias: hay {n} de {label}{en_pais}.',
			'Estas son {n} que encajan con lo que buscas.',
			'Te encontré {n} de {label}.',
		),
		'advice_open' => array(
			'Para {summary} te recomiendo {options}.',
			'Pensando en {summary}, lo que mejor te queda es {options}.',
			'Para {summary} yo me iría por {options}.',
		),
		'advice_open_generic' => array(
			'Te recomiendo {options}.',
			'Lo que mejor te queda es {options}.',
		),
		'advice_in_budget' => array(
			'Todas entran en tu presupuesto {budget}.',
			'Te muestro solo las que caben en un presupuesto {budget}.',
		),
		'advice_over_budget' => array(
			'Siendo sincero, con un presupuesto {budget} lo que te recomiendo queda un poco alto: arranca en {min}. Te muestro las más accesibles; si puedes estirar un poco el presupuesto, son la mejor opción para ti. ¿O prefieres que busque algo más sencillo dentro de tu presupuesto?',
			'Con {budget} lo que más te conviene se pasa un poco: lo más accesible está en {min}. ¿Te muestro alternativas dentro de tu presupuesto o te sirven estas?',
		),
		'advice_closing' => array(
			'¿Te interesa alguna? Te puedo dar más detalles, compararlas o calcularte las cuotas.',
			'Si alguna te llama la atención, dime cuál y te cuento más o te calculo las cuotas.',
		),
		'ask_budget' => array(
			'¿Cuánto tienes de presupuesto aproximado? Así te muestro lo que mejor te queda.',
			'Para afinar: ¿más o menos cuánto quieres invertir?',
		),
		'ask_profile' => array(
			'¡Con gusto te ayudo a elegir! Cuéntame un poco: ¿cuántos van a viajar normalmente y para qué lo vas a usar (ciudad, carretera, trabajo, finca)?',
			'Claro. Para recomendarte bien, ¿cuántas personas van a usarlo y para qué: ciudad, viajes, trabajo o caminos de tierra?',
		),
		'advice_none' => array(
			'Para {summary} no tengo en este momento algo que cumpla todo.',
			'Ahora mismo no tengo opciones que le queden bien a {summary}.',
		),
		'advice_none_followup' => array(
			'Nuestros asesores te pueden avisar cuando ingrese algo así. ¿Te dejo el contacto?',
		),
		'budget_chip' => array( 'Hasta {max}' ),
		'similar_ref_traits' => array(
			'El {ref} es {describe}; se caracteriza por {traits}.',
			'El {ref} se caracteriza por {traits}: es {describe}.',
		),
		'similar_ref' => array(
			'El {ref} es {describe}.',
		),
		'similar_reco_why' => array(
			'Si buscas algo parecido, te recomiendo {options}: también son {why}.',
			'Parecidas a esa te recomiendo {options}, que también son {why}.',
		),
		'similar_reco' => array(
			'Si buscas algo parecido, te recomiendo {options}.',
		),
		'similar_prices'   => array( 'Los precios: {prices}.', 'En precio: {prices}.' ),
		'similar_price'    => array( '{title} desde {price}' ),
		'similar_no_price' => array( 'precio a consultar' ),
		'similar_have_ref' => array( 'Y si te interesa el {ref}, también lo tenemos desde {price}.', 'Del {ref} también tenemos, desde {price}.' ),
		'similar_none'     => array( 'Ahora mismo no tengo otro parecido al {ref}.' ),
		'similar_closing'  => array( '¿Quieres que te las compare lado a lado?', 'Si quieres te las comparo, o te calculo las cuotas de la que te guste.' ),
		'similar_unknown'  => array( 'No conozco bien el “{ref}”. ¿Qué tipo de vehículo es: pickup, SUV o sedán? Así te busco algo parecido.' ),
		'action_ask_quote'   => array(
			'¡Con gusto te preparo la cotización del {title}! Solo necesito tu nombre y tu correo (y un teléfono si quieres que un asesor te llame).',
			'¡Excelente elección! Para enviarte la cotización del {title}, ¿me compartes tu nombre y tu correo? Si quieres, también un teléfono.',
		),
		'action_ask_compare' => array(
			'Te preparo la comparación de {titles} en PDF. ¿Me compartes tu nombre y tu correo para enviártela?',
		),
		'action_consent'     => array( 'Con estos datos un asesor de {site} podrá contactarte. Si prefieres no seguir, escribe “cancelar”.' ),
		'action_ask_email'   => array( 'Gracias, {name}. ¿A qué correo te la envío?', 'Perfecto, {name}. ¿Cuál es tu correo?' ),
		'action_ask_name'    => array( '¿A nombre de quién la preparo?', '¿Me dices tu nombre para la cotización?' ),
		'action_which'       => array( '¿Cuál te interesa?', '¿De cuál te preparo la cotización?' ),
		'action_cancelled'   => array( 'Listo, lo dejamos ahí. Si quieres retomarlo, solo dímelo.' ),
		'action_done_quote'  => array( '¡Listo, {name}! Aquí tienes la cotización del {title}.', '¡Hecho, {name}! Esta es tu cotización del {title}.' ),
		'action_done_compare' => array( '¡Listo, {name}! Aquí tienes la comparación de {titles}.' ),
		'action_emailed'     => array( 'También te la envié a {email}.', 'Te mandé una copia a {email}.' ),
		'action_not_emailed' => array( 'No pude enviarla a tu correo ahora mismo, pero la puedes descargar aquí.' ),
		'action_test_note'   => array( '(Modo prueba: el aviso a ventas fue solo al correo de prueba.)' ),
		'action_failed'      => array( 'Uy, no pude generar el documento en este momento. Lo puedes pedir desde la página del vehículo:' ),
		'action_limit'       => array( 'Ya generé varios documentos seguidos; dame unos minutos o pídelo desde la página del vehículo:' ),
		'action_after_quote' => array( '¿Quieres que te calcule las cuotas o te muestro otras opciones parecidas?' ),
		'action_after_compare' => array( '¿Te ayudo a decidir o te preparo la cotización de alguno?' ),
		'compare_offer_pdf'  => array( '¿Quieres que te mande esta comparación en PDF a tu correo?' ),
		'file_label_quote'   => array( 'Cotización (PDF)' ),
		'file_label_compare' => array( 'Comparación (PDF)' ),
		'file_open'          => array( 'Ver' ),
		'file_download'      => array( 'Descargar' ),
		'finance_table'      => array( 'Para el {title} ({price}), con un enganche de {down} ({pct}), estas serían las cuotas aproximadas:' ),
		'finance_single'     => array( 'Para el {title} ({price}), con un enganche de {down} ({pct}), la cuota aproximada a {term} sería de {payment}.' ),
		'finance_term'       => array( '{n} meses' ),
		'finance_head_term'  => array( 'Plazo' ),
		'finance_head_payment' => array( 'Cuota mensual' ),
		'finance_down_min'   => array( 'El enganche mínimo es del {pct}, así que lo calculé con {down}.' ),
		'finance_down_max'   => array( 'El enganche máximo es del {pct}, así que lo calculé con {down}.' ),
		'finance_offer_quote' => array( 'Tasa de referencia del {rate} anual; el valor final lo confirma un asesor. ¿Te preparo la cotización en PDF y te la envío por correo?' ),
		'finance_disclaimer' => array( 'Tasa de referencia del {rate} anual; el valor final lo confirma un asesor.' ),
		'action_ask_quote_many' => array( '¡Con gusto te preparo la cotización de {titles}! Solo necesito tu nombre y tu correo (y un teléfono si quieres que un asesor te llame).' ),
		'action_done_quote_many' => array( '¡Listo, {name}! Aquí tienes la cotización de {titles}.' ),
		'advice_more' => array( 'Claro, aquí van otras opciones para {summary}: {options}.', 'Te muestro más: para {summary} también te pueden servir {options}.' ),
		'results_budget' => array(
			'Con un presupuesto {budget} tengo {n}. Te muestro primero las que más aprovechan tu presupuesto.',
			'Para un presupuesto {budget} hay {n}. Empiezo por las más completas dentro de ese monto.',
		),
		'results_budget_attrs' => array(
			'Con un presupuesto {budget} tengo {n} {wanted}. Primero las que más aprovechan tu presupuesto.',
		),
		'results_attrs' => array(
			'Encontré {n} {wanted}. Empiezo por los modelos más nuevos.',
			'Tengo {n} {wanted}; te muestro primero las más recientes.',
		),
		'budget_between' => array( 'entre {min} y {max}' ),
		'budget_max'     => array( 'de hasta {max}' ),
		'budget_min'     => array( 'desde {min}' ),
		'results_single' => array(
			'Encontré justo una opción de {label}{en_pais}:',
			'Hay una opción que encaja con lo que buscas:',
			'Tengo esta opción de {label}:',
		),
		'results_named' => array(
			'¡Aquí está {title}!',
			'Encontré {title}:',
			'Claro, este es {title}:',
		),
		'results_partial' => array(
			'No encontré algo exacto para “{query}”, pero estas opciones se acercan bastante:',
			'Con todo lo que pediste no tengo una coincidencia exacta; te muestro lo más parecido:',
			'No hay una opción que cumpla todo al pie de la letra, pero estas se acercan:',
		),
		'results_refined' => array(
			'Perfecto, lo afiné con “{refine}”: tengo {n}.',
			'Listo, filtré por {refine}. Tengo {n}.',
			'Hecho: con {refine} encontré {n}.',
		),
		'refine_empty' => array(
			'Con “{refine}” no me quedó ninguna opción, así que busqué solo eso:',
			'No hay coincidencias sumando “{refine}” a lo anterior; te muestro lo que tengo de {refine}:',
		),
		'more' => array(
			'Aquí van {n} más:',
			'Te muestro otras {n}:',
			'Seguimos: estas también te pueden interesar.',
			'Mira estas otras {n}:',
		),
		'no_more' => array(
			'Ya te mostré todas las opciones que tengo para esa búsqueda. ¿Probamos con algo diferente?',
			'Eso es todo lo que encontré para esa búsqueda. Si quieres, afinamos con otras palabras.',
		),
		'none' => array(
			'Mmm, no encontré nada para “{query}”{en_pais}.',
			'Por ahora no tengo resultados para “{query}”{en_pais}.',
			'Busqué “{query}” y no encontré coincidencias.',
		),
		'none_followup' => array(
			'¿Quieres intentar con otra palabra? También puedes ver:',
			'Prueba con otras palabras o revisa alguna de estas líneas:',
			'Si me lo describes de otra forma, lo intento de nuevo. Mientras, tengo:',
		),
		'none_contact' => array(
			'Si no lo encuentras, escríbenos y un asesor te ayuda:',
			'También puedes contactarnos directamente y te orientamos:',
		),
		'closing' => array(
			'¿Quieres que te muestre más o prefieres afinar la búsqueda?',
			'Si me das un detalle más (marca, tipo, color…), lo filtro mejor.',
			'¿Alguna te interesa? Te cuento más de cualquiera.',
			'Puedes pedirme más opciones o darme otro detalle para afinar.',
			'',
			'',
		),
		'closing_single' => array(
			'¿Quieres que te cuente más de esta opción?',
			'Si te interesa, te doy más detalles o te ayudo a cotizarla.',
			'Si no es lo que buscas, dame otro detalle y sigo buscando.',
			'',
		),
		'clarify' => array(
			'Tengo {n} de {label}. Para mostrarte lo justo, ¿buscas {facets}?',
			'Hay {n} de {label}. ¿Te interesa más {facets}?',
			'Encontré {n} de {label} en varias líneas. ¿Cuál prefieres: {facets}?',
		),
		'clarify_brand' => array(
			'Tengo {n} de {label} de varias marcas. ¿Prefieres {facets}?',
			'Hay {n} de {label}. ¿Tienes alguna marca en mente: {facets}?',
		),
		'excluded' => array( 'Dejé fuera {excluded}.', 'Ya quité {excluded}.', 'Sin incluir {excluded}.' ),
		'learned' => array( '¡Claro! Esto es lo que buscas:', 'Mira, esto es lo que tengo para eso:', 'Aquí lo tienes:' ),

		// Datos de un producto.
		'fact' => array( 'Según su ficha, {title} tiene {facts}.', '{title} tiene {facts}, según su ficha.', 'Te confirmo: {title} tiene {facts}.' ),
		'fact_price' => array( '{title} tiene un precio de {value}.', 'El precio de {title} es {value}.', '{title} está en {value}.' ),
		'fact_brand' => array( '{title} es de la marca {value}.', 'Es un producto de {value}.' ),
		'fact_category' => array( '{title} está en nuestra línea de {value}.', '{title} forma parte de {value}.' ),
		'fact_code' => array( 'El código de {title} es {value}.', '{title} tiene el código {value}.' ),
		'fact_text' => array( 'Esto dice la ficha de {title} sobre eso:', 'En la descripción de {title} encontré esto:', 'Sobre eso, la ficha de {title} dice:' ),
		'fact_missing' => array( 'No encontré ese dato en la ficha de {title}. Puedes revisarla completa o preguntarnos directamente:', 'La ficha de {title} no menciona eso; si nos escribes, te lo confirmamos:' ),
		'fact_price_missing' => array( '{title} no tiene precio publicado; te lo damos en una cotización:', 'El precio de {title} te lo confirmamos con una cotización; puedes pedirla aquí:' ),
		'fact_followup' => array( '¿Quieres saber algo más de este producto?', '¿Te ayudo con otro dato o lo comparamos con otra opción?', '' ),

		// Descripción armada con los datos de la ficha.
		'describe_brand_category' => array( 'Es de la marca {brand}, dentro de {category}.', 'Pertenece a {category} y es de {brand}.' ),
		'describe_brand' => array( 'Es de la marca {brand}.', 'Es un producto de {brand}.' ),
		'describe_category' => array( 'Está en nuestra línea de {category}.', 'Forma parte de {category}.' ),
		'describe_facts' => array( 'Algunos datos: {facts}.', 'Lo más importante: {facts}.', 'En resumen: {facts}.' ),
		'describe_price' => array( 'Su precio es {price}.', 'Está en {price}.' ),

		// Comparar.
		'compare' => array( 'Te comparo {a} y {b}:', 'Así se ven {a} y {b} lado a lado:', 'Comparemos {a} con {b}:' ),
		'compare_diff' => array( 'La diferencia principal está en {label}: {va} contra {vb}.', 'Donde más cambian es en {label}: {va} frente a {vb}.' ),
		'compare_cheaper' => array( '{title} es el más económico de los dos.', 'En precio, {title} es la opción más económica.' ),
		'compare_same' => array( 'Con los datos que tengo se ven muy parecidos; en sus fichas están todos los detalles.' ),
		'compare_missing' => array( '¿Cuáles quieres comparar? Dime los dos nombres, por ejemplo: “compara el primero con el segundo”.', 'Para comparar necesito saber cuáles: dime sus nombres o “el primero y el segundo”.' ),

		// El más barato o el más caro.
		'cheapest' => array( 'El más económico{en_label} es {title}, a {price}.', 'La opción de menor precio{en_label} es {title}: {price}.' ),
		'priciest' => array( 'El de mayor precio{en_label} es {title}, a {price}.', 'El más caro{en_label} es {title}: {price}.' ),
		'sorted_more' => array( 'Te dejo también las siguientes, ordenadas por precio:', 'Estas le siguen en precio:' ),
		'prices_missing' => array( 'No tengo precios publicados{en_label}; con una cotización te damos el precio exacto:', 'Los precios{en_label} se dan por cotización; puedes pedirla aquí:' ),

		// Respuesta con la frase exacta de la página.
		'info_quote' => array(
			'contact'   => array( 'Así puedes contactarnos{en_pais}:', 'Estos son nuestros datos de contacto{en_pais}:' ),
			'hours'     => array( 'Te comparto nuestro horario{en_pais}:', 'Este es el horario de atención{en_pais}:' ),
			'location'  => array( 'Aquí estamos{en_pais}:', 'Esta es nuestra dirección{en_pais}:' ),
			'about'     => array( 'Te cuento un poco de nosotros:', 'Esto es {site}:' ),
			'quote'     => array( 'Para cotizar:', 'Así funciona la cotización:' ),
			'financing' => array( 'Sobre financiamiento:', 'Esto es lo que ofrecemos en financiamiento:' ),
			'jobs'      => array( 'Si quieres trabajar con nosotros:', 'Sobre oportunidades de empleo:' ),
			'human'     => array( 'Con gusto te comunicamos con un asesor:', 'Un asesor puede ayudarte directamente:' ),
			'page'      => array( 'Esto dice nuestra página sobre eso:', 'Encontré esto:', 'Te comparto lo que dice el sitio:' ),
		),
		'info_quote_more' => array( 'Más detalles aquí:', 'Aquí puedes ver toda la información:', 'La página completa está aquí:' ),
		'price_note' => array(
			'Los precios pueden variar; si quieres una cotización exacta, escríbenos aquí:',
			'Para darte un precio exacto lo mejor es una cotización; puedes pedirla aquí:',
		),
		'detail' => array(
			'Te cuento sobre {title}:',
			'Esto es lo que tengo de {title}:',
			'Claro, sobre {title}:',
		),
		'detail_buy' => array(
			'Si te gusta, en su ficha puedes ver todos los detalles y comprarlo.',
			'Desde su ficha puedes elegir las opciones que prefieras y agregarlo al carrito.',
		),
		'detail_cta' => array(
			'Si te interesa, puedes cotizarlo o pedir más información aquí:',
			'¿Te gustaría cotizarlo? Puedes escribirnos aquí:',
		),
		'reference_missing' => array(
			'¿A cuál te refieres? Dime su nombre o su número en la lista.',
			'No estoy seguro de cuál opción me dices; ¿me das el nombre o el número?',
		),
		'info' => array(
			'contact'   => array( 'Claro, aquí tienes cómo contactarnos{en_pais}:', 'Puedes comunicarte con nosotros desde aquí:' ),
			'location'  => array( 'Aquí puedes ver dónde estamos{en_pais}:', 'Estas son nuestras ubicaciones{en_pais}:' ),
			'location_specific' => array( 'Esta es la ubicación que buscas:', 'Aquí tienes la información de esa sucursal:', 'Sí, tenemos esta ubicación:' ),
			'location_list'     => array( 'Estas son todas nuestras ubicaciones{en_pais}:', 'Aquí tienes nuestras tiendas y puntos{en_pais}:' ),
			'location_none'     => array( 'No encontré una ubicación en “{query}”, pero estas son todas las que tenemos:', 'No tengo una sucursal en “{query}”; te muestro todas nuestras ubicaciones:' ),
			'location_page'     => array( 'También puedes ver el mapa y todos los detalles aquí:', 'Todas las ubicaciones con su mapa están aquí:' ),
			'hours'     => array( 'Los horarios de atención los encuentras aquí:', 'Aquí está la información de horarios{en_pais}:' ),
			'about'     => array( 'Te cuento un poco de nosotros aquí:', 'Aquí puedes conocer más sobre {site}:' ),
			'quote'     => array( 'Con gusto. Puedes pedir tu cotización aquí:', '¡Claro! Solicita tu cotización desde aquí:' ),
			'financing' => array( 'Sobre financiamiento, aquí tienes la información:', 'Estas son las opciones de financiamiento:' ),
			'jobs'      => array( 'Si quieres trabajar con nosotros, aquí están las oportunidades:', 'Aquí puedes ver las vacantes y enviar tu información:' ),
			'human'     => array( 'Con gusto te comunico con un asesor. Escríbenos o llámanos desde aquí:', 'Un asesor puede ayudarte directamente; aquí están los datos:' ),
			'page'      => array( 'Encontré esta información que te puede servir:', 'Esto te puede ayudar:', 'Mira, aquí hablamos de eso:' ),
		),
		'info_missing' => array(
			'No encontré una página específica para eso, pero desde aquí puedes contactarnos y te ayudamos:',
			'No tengo esa información a mano; escríbenos y con gusto te respondemos:',
		),
		'language_hint' => array( 'Veo que escribes en {other_language}; también tenemos el sitio en ese idioma:' ),
		'also_info'     => array( 'También te puede servir:', 'Además, mira esta información:' ),
		'closing_similar' => array( '¿Alguno de estos es lo que buscas? Si no, dame otro detalle y sigo buscando.', 'Si ninguno es lo que necesitas, cuéntame un poco más y lo busco de nuevo.', '¿Te sirve alguno? Si no, dime marca, tipo o uso y afino la búsqueda.' ),
		'similar'       => array( 'También te pueden interesar estos similares:', 'Otras opciones parecidas que podrían servirte:', 'Te dejo también estos productos similares:' ),
		'corrected'     => array( 'Supongo que te refieres a “{fixed}”.', 'Busqué “{fixed}”, que creo que es lo que quisiste escribir.', 'Lo busqué como “{fixed}”.' ),
	),

	// Textos de la interfaz del chat.
	'ui' => array(
		'page_title'       => 'Asistente',
		'page_slug'        => 'asistente',
		'placeholder'      => 'Escribe lo que buscas…',
		'send'             => 'Enviar',
		'new'              => 'Nueva conversación',
		'typing'           => 'Escribiendo…',
		'error'            => 'No pude responder en este momento. Intenta de nuevo en unos segundos.',
		'rate_limited'     => 'Vas muy rápido. Espera un momento y vuelve a intentarlo.',
		'code'             => 'Código',
		'view'             => 'Ver',
		'noscript'         => 'Activa JavaScript para usar el asistente.',
		'choice_title'     => '¿Cómo quieres buscar?',
		'choice_assistant' => 'Preguntar al asistente',
		'choice_search'    => 'Ver resultados de búsqueda',
		'you'              => 'Tú',
		'view_place'       => 'Ver ubicación',
	),

	'language_names' => array( 'es' => 'español', 'en' => 'inglés', 'pt' => 'portugués', 'fr' => 'francés' ),
);

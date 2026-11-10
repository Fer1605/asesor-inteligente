# Asesor Inteligente

Asesor de ventas conversacional para WordPress, **sin servicios externos de IA**: ni APIs de pago ni modelos en la nube. Toda la "inteligencia" es propia: PHP sobre los datos del sitio.

> *Conversational sales assistant for WordPress with no external AI services. Spanish and English.*

## Qué hace

- **Descubre solo el sitio**: tipos de contenido, taxonomías, campos ACF (incluido JSON guardado en meta), WooCommerce, Elementor, Polylang/WPML con sus países e idiomas. Los ajustes del panel sirven para corregir, nunca son obligatorios.
- **Conversa**: clasificador de intención (anclas + Bayes ingenuo), memoria de la conversación ("el segundo", "¿y más barato?"), respuestas con plantillas que no se repiten, corrector ortográfico con el vocabulario del propio sitio.
- **Busca con fidelidad**: índice propio con BM25 y puntuación por campo; si pides algo concreto, solo muestra lo que lo cumple.
- **Se adapta a cada sitio** con tres capas:

```
asesor-inteligente/
├── includes/     core: igual en todos los sitios
├── packs/        conocimiento por giro de negocio (autos, tienda…)
└── conectores/   integraciones con las acciones de cada sitio (cotizar, comparar…)
```

Un **pack** se activa solo si el sitio tiene el tipo de contenido que describe. Un **conector** se activa solo si encuentra en el sitio las funciones que necesita. Si ninguno aplica, el chat sigue funcionando y manda al formulario normal del sitio. Otros plugins pueden sumar los suyos con los filtros `asi_packs` y `asi_connectors`.

## En desarrollo (0.1.0-dev)

- [x] Núcleo de búsqueda y diálogo, panel, chat, aprendizaje
- [x] Registro de packs y conectores
- [x] Precio por moneda: presupuestos en cualquier moneda, conversión a la moneda base, tipo de cambio en el panel
- [x] Ficha de atributos por producto (pack autos: carrocería, plazas, tracción, combustible, transmisión, año, km)
- [ ] Semejanza ("parecido a…")
- [ ] Perfil del cliente → recomendación, con preguntas de vuelta
- [ ] Acciones desde el chat: PDF en la conversación + envío por correo + registro del lead

## Uso

1. Subir la carpeta a `wp-content/plugins/` y activar. Entra en modo **solo administradores**.
2. Menú **Asesor inteligente**: revisar lo descubierto, la apariencia y probar la conversación.
3. Shortcode: `[asesor_inteligente]`. REST: `POST /wp-json/asi/v1/chat`. WP-CLI: `wp asesor status | discover | reindex | show <id>`.

## Pruebas

```bash
php tests/run.php
```

Prueban la lógica pura sin WordPress.

## Licencia

GPL-2.0-or-later · Fer Santizo

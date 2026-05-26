# Gateway de Bancard para WooCommerce

[![Version](https://img.shields.io/badge/version-0.3.3-blue.svg)](https://github.com/marioungui/woocommerce-bancard/releases)
[![WooCommerce](https://img.shields.io/badge/WooCommerce-compatible-96588a.svg)](https://woocommerce.com/)
[![PHP](https://img.shields.io/badge/PHP-%3E%3D7.2-777bb4.svg)](https://www.php.net/)
[![License: GPL v2+](https://img.shields.io/badge/license-GPLv2%2B-green.svg)](https://www.gnu.org/licenses/gpl-2.0.html)
[![CodeFactor](https://www.codefactor.io/repository/github/marioungui/woocommerce-bancard/badge/main)](https://www.codefactor.io/repository/github/marioungui/woocommerce-bancard/overview/main)

Plugin para integrar Bancard con WooCommerce usando la API eCommerce v0.3.

## Version 0.3.3

Esta version consolida el flujo moderno del plugin:

- Compra simple embebida con Bancard Checkout.
- Pagos con tarjetas guardadas mediante alias token.
- Registro y eliminacion de tarjetas desde Mi cuenta.
- Secure3D para pagos con token cuando Bancard devuelve `confirmation.process_id`.
- Zimple.
- Preautorizaciones y confirmacion manual desde el pedido.
- Factura electronica via bloque `billing`.
- Cobros recurrentes con WooCommerce Subscriptions usando tarjetas tokenizadas.
- Deteccion explicita de renovaciones que requieren 3DS para que se cobren manualmente.
- Metabox administrativo con datos Bancard del pedido.
- Logs sanitizados opcionales con `WC_Logger`.
- Idempotencia del callback para evitar notas o cambios duplicados si Bancard reintenta la notificacion.

## Requisitos

- WordPress con WooCommerce activo.
- PHP 7.2 o superior.
- Credenciales de comercio Bancard.
- WooCommerce Subscriptions solo si se van a usar pagos recurrentes.

## Configuracion

1. Instalar el plugin en `wp-content/plugins/woocommerce-bancard`.
2. Activarlo desde el panel de WordPress.
3. Ir a WooCommerce > Ajustes > Pagos.
4. Configurar los gateways Bancard que correspondan.
5. Cargar llave publica, llave privada y ambiente.
6. Confirmar en el portal de comercios que la URL de confirmacion sea:

```text
https://{tu-dominio}/wc-api/wc_gateway_bancard
```

El plugin crea la pagina `bancard-payment` durante la activacion. Si no existe, el panel administrativo muestra una advertencia.

## Pagos Con Token

Las tarjetas se registran desde Mi cuenta > Mis tarjetas > Agregar nueva tarjeta. Bancard devuelve el formulario seguro y el plugin sincroniza las tarjetas catastradas con los tokens nativos de WooCommerce.

Para pagos con token, el cliente selecciona una tarjeta guardada en checkout. Si Bancard exige Secure3D, el plugin redirige a la pagina embebida correspondiente y espera la confirmacion final por callback.

## Recurrentes

El gateway `Bancard Tokens` soporta renovaciones automaticas de WooCommerce Subscriptions usando el `alias_token` guardado.

El plugin persiste el token en la orden inicial, la suscripcion y las ordenes de renovacion. Tambien soporta cambio de metodo de pago desde cliente/admin y sincroniza el token cuando una renovacion fallida se paga manualmente.

Si una renovacion automatica requiere Secure3D, el plugin marca la renovacion como fallida con una nota clara, porque no hay cliente presente para completar la autenticacion.

## Administracion

En pedidos pagados con Bancard se muestra un metabox con:

- Process ID.
- Modo de proceso.
- Numero de autorizacion.
- Ticket.
- Codigo de respuesta.
- Tipo de tarjeta.
- Cuotas.
- Riesgo.
- Pais de tarjeta.
- IP del cliente.
- Estado 3DS requerido.

Tambien se pueden usar las acciones del pedido para consultar confirmacion, confirmar preautorizacion o cancelar factura electronica cuando aplique.

## Logs

Cada gateway incluye la opcion "Logs de depuracion". Cuando esta activa, el plugin registra requests y responses sanitizados en WooCommerce > Estado > Registros usando la fuente `woocommerce-bancard`.

No se guardan llaves privadas, tokens completos ni alias tokens completos.

## Desarrollo

Los archivos principales son:

- `includes/class-wc-gateway-bancard.php`: compra simple, callback, refunds y operaciones administrativas.
- `includes/class-wc-gateway-bancard-tokens.php`: tarjetas guardadas, pagos con token, 3DS y recurrentes.
- `includes/class-wc-gateway-bancard-zimple.php`: pagos Zimple.
- `includes/class-wc-gateway-bancard-api.php`: cliente API Bancard.
- `includes/class-wc-gateway-bancard-base.php`: helpers compartidos.
- `includes/class-wc-gateway-bancard-hooks.php`: hooks WooCommerce, acciones admin y metabox.
- `templates/bancard-payment-page.php`: pagina embebida de Checkout, Zimple y Charge3DS.

## Licencia

GPLv2 or later.

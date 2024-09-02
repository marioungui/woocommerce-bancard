# WooCommerce Bancard Payment Gateway Plugin

Este es un plugin para WooCommerce que integra la pasarela de pagos Bancard para permitir a los usuarios realizar pagos con tarjeta de crédito y débito a través de la plataforma Bancard.

## Funcionalidades

### 1. Procesamiento de Pagos
- **Single Buy**: El plugin permite a los usuarios realizar compras únicas redirigiendo a la página de pago de Bancard.
- **Pagos Recurrentes**: Soporte para pagos de suscripciones mediante el uso de tokens.
- **Gestión de Tokens**: Las tarjetas de los usuarios se pueden registrar y almacenar de manera segura como tokens en WooCommerce, permitiendo pagos rápidos en futuras transacciones.
  
### 2. Gestión de Tarjetas Guardadas
- **Listado de Tarjetas**: Los usuarios pueden ver una lista de sus tarjetas guardadas en la sección "Mi cuenta".
- **Eliminación de Tarjetas**: Los usuarios pueden eliminar tarjetas guardadas con la confirmación previa de la acción.
- **Tokenización**: Implementación del proceso de tokenización de tarjetas para realizar pagos de manera segura.

### 3. Interacción con la API de Bancard
- **Registro de Tarjetas**: El plugin permite a los usuarios registrar nuevas tarjetas utilizando la API de Bancard.
- **Consulta de Tarjetas Catastradas**: Consulta de tarjetas guardadas para un usuario mediante la API de Bancard.

### 4. Integración con WooCommerce
- **Compatibilidad Total**: El plugin se integra completamente con el sistema de tokens de WooCommerce, permitiendo que las tarjetas guardadas se gestionen de la misma manera que con otros métodos de pago.

## Estructura del Código

El plugin está estructurado en las siguientes clases y archivos:

### 1. `class-wc-gateway-bancard.php`
Esta es la clase principal del plugin que maneja la mayoría de las funcionalidades relacionadas con la pasarela de pagos Bancard. Algunas de sus funciones clave incluyen:

- **`process_payment`**: Maneja el proceso de pago para compras únicas.
- **`process_subscription_payment`**: Maneja los pagos recurrentes para suscripciones.
- **`check_response`**: Procesa las notificaciones y confirmaciones de la API de Bancard.
- **`process_refund`**: Gestiona los reembolsos a través de la API de Bancard.
- **`bancard_payment_template`**: Carga el template de la página de pago de Bancard desde el plugin.
- **`is_available`**: Verifica si la pasarela de pagos está disponible.

### 2. `class-wc-gateway-bancard-tokens.php`
Esta clase extiende las funcionalidades de `WC_Gateway_Bancard` para manejar la tokenización y gestión de tarjetas guardadas. Algunas de sus funciones clave incluyen:

- **`add_payment_method`**: Registra una nueva tarjeta para un usuario y la almacena como un token.
- **`list_payment_methods`**: Consulta y lista las tarjetas guardadas de un usuario.
- **`generate_card_id`**: Genera un ID único para cada tarjeta registrada.
- **`save_card_id`**: Almacena los `card_id` generados en el `usermeta` del usuario.

### 3. Plantillas
- **`bancard-payment-page.php`**: Plantilla para la página de pago de Bancard.
- **`bancard-card-tokenization.php`**: Plantilla para la página de tokenización de tarjetas.

### 4. Manejo de URLs Personalizadas
- **Eliminación de Tarjetas**: Implementación de una URL personalizada para manejar la eliminación de tarjetas, con confirmación previa.

## En Progreso

### 1. **Mejora en la Gestión de Tokens**
- **Integración Completa con el Sistema de Tokens de WooCommerce**: Asegurar que todas las tarjetas catastradas se gestionen mediante el sistema de tokens nativo de WooCommerce.

### 2. **Manejo de Resultados de Tokenización**
- **Manejo Detallado de Resultados en `bancard-tokenization-result`**: Implementar un manejo más robusto de los resultados de tokenización para confirmar el éxito o fallo del registro de tarjetas.

### 3. **Documentación Adicional**
- **Instrucciones de Instalación y Configuración**: Proporcionar una guía detallada para la instalación y configuración del plugin en entornos de producción y pruebas.

## Requisitos

- WooCommerce 4.0 o superior
- PHP 7.2 o superior

## Instalación

1. Clona este repositorio en tu directorio de plugins de WordPress:  
   `git clone https://github.com/tu-usuario/woocommerce-bancard.git`
2. Activa el plugin desde el panel de administración de WordPress.
3. Configura el plugin desde **WooCommerce > Ajustes > Pagos > Bancard**.

## Contribuir

Las contribuciones son bienvenidas. Si encuentras un error o tienes una sugerencia de mejora, por favor abre un [issue](https://github.com/tu-usuario/woocommerce-bancard/issues) o envía un pull request.

## Licencia

Este proyecto está licenciado bajo la [Licencia MIT](https://opensource.org/licenses/MIT).

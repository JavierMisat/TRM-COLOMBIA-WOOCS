# TRM Colombia for WooCommerce (Custom & Improved)

**Versión:** 2.0
**Autor:** Javier Misat 
**Requiere WordPress:** 5.0 o superior
**Probado hasta WordPress:** 6.5
**Requiere WooCommerce:** 3.0 o superior
**Requiere WOOCS - WooCommerce Currency Switcher:** Sí
**Requiere PHP:** 7.2 o superior (recomendado 7.4+)
**Requiere Extensión SOAP PHP:** Sí

Actualiza la tasa de cambio USD/COP diariamente usando la TRM oficial de la Superintendencia Financiera de Colombia. Este plugin se integra como un **agregador de tasas de cambio** seleccionable dentro del plugin WOOCS - WooCommerce Currency Switcher.

## Características Principales

* **Agregador Dedicado para WOOCS:** Se registra como "TRM Colombia (Superfinanciera)" en las opciones de agregadores de WOOCS.
* **Actualización Automática de la TRM:** Obtiene la TRM oficial de Colombia (COP por USD) diariamente desde el servicio web de la Superintendencia Financiera.
* **Cálculo Correcto para WOOCS:** Si la moneda base de tu tienda es COP, la tasa para USD se establece directamente al valor de la TRM.
* **Manejo de Errores Avanzado:**
    * Verifica la disponibilidad de la extensión SOAP.
    * Maneja errores de conexión y respuestas inesperadas del servicio web.
    * Utiliza `WP_Error` para un manejo robusto de fallos.
    * Registra errores detallados en el log de PHP de WordPress.
* **Optimización con Transients:** Almacena la TRM en un transient de WordPress (por defecto 12 horas) para mejorar el rendimiento y evitar solicitudes excesivas.
* **Notificaciones Administrativas:** Informa si WOOCS o la extensión SOAP no están activos.
* **Código Moderno y Organizado:** Escrito con PHP orientado a objetos y siguiendo estándares de WordPress.
* **Internacionalización:** Preparado para traducción (dominio de texto: `trm-colombia-woocs`).

## ¿Cómo Funciona?

1.  **Activación y Verificación:** Al activar, el plugin verifica si WOOCS y la extensión SOAP de PHP están activos.
2.  **Registro como Agregador:** El plugin se registra con WOOCS bajo el nombre "TRM Colombia (Superfinanciera)".
3.  **Selección del Agregador:** Debes ir a `WooCommerce` -> `Ajustes` -> `Currency (WOOCS)` -> `Advanced` y seleccionar "TRM Colombia (Superfinanciera)" en la opción "Currency Aggregator". Guarda los cambios.
4.  **Obtención de Tasa:**
    * Cuando WOOCS necesita actualizar las tasas (y nuestro agregador está seleccionado), el plugin se activa para la moneda USD.
    * Intenta obtener la TRM almacenada en un transient de WordPress (`trm_colombia_rate_v2`).
    * Si el transient no existe, ha expirado, o su valor no es válido:
        * Realiza una solicitud SOAP al servicio web de la Superintendencia Financiera de Colombia (`https://www.superfinanciera.gov.co/SuperfinancieraWebServiceTRM/TCRMServicesWebService/TCRMServicesWebService?WSDL`) para obtener la TRM del día actual (COP por USD).
        * Si la solicitud es exitosa y el valor es válido, almacena la TRM en el transient.
        * Si la solicitud falla o devuelve un error, se registra un error detallado y WOOCS utilizará la tasa previamente configurada o la última tasa válida conocida.
5.  **Aplicación de la Tasa:** La tasa obtenida (ej. 4000, significando 1 USD = 4000 COP) se pasa a WOOCS para la moneda USD.

## Requisitos

* WordPress 5.0 o superior.
* WooCommerce 3.0 o superior.
* **WOOCS - WooCommerce Currency Switcher** plugin instalado y activado. ([Plugin en WordPress.org](https://wordpress.org/plugins/woocommerce-currency-switcher/))
* **La moneda base de WooCommerce debe estar configurada en COP.**
* **USD debe estar agregada como una moneda adicional en WOOCS.**
* **Extensión SOAP de PHP habilitada en tu servidor.** Contacta a tu proveedor de hosting si no está seguro.
* PHP 7.2 o superior.

## Instalación

1.  Descarga el archivo `.zip` del plugin.
2.  Ve a tu panel de administración de WordPress -> `Plugins` -> `Añadir nuevo`.
3.  Haz clic en `Subir plugin` y selecciona el archivo `.zip` que descargaste.
4.  Activa el plugin a través del menú 'Plugins' en WordPress.
5.  **Configuración en WOOCS:**
    * Ve a `WooCommerce` -> `Ajustes`.
    * Haz clic en la pestaña `Currency (WOOCS)`.
    * Ve a la sub-pestaña `Advanced`.
    * En la opción `Currency Aggregator`, selecciona **"TRM Colombia (Superfinanciera)"** del menú desplegable.
    * Guarda los cambios.

¡Eso es todo! El plugin comenzará a actualizar la tasa USD/COP automáticamente según la TRM oficial cuando WOOCS lo requiera.

## Solución de Problemas

* **No aparece "TRM Colombia (Superfinanciera)" en WOOCS:**
    * Asegúrate de que tanto este plugin como WOOCS estén activados.
    * Verifica que no haya errores de PHP fatales que impidan la carga completa del plugin (revisa el log de errores de PHP de tu servidor o activa `WP_DEBUG`).
    * Intenta desactivar y reactivar este plugin.
* **La tasa no se actualiza:**
    * Verifica que "TRM Colombia (Superfinanciera)" esté seleccionado como el agregador en WOOCS.
    * Asegúrate de que la extensión SOAP de PHP esté habilitada en tu servidor. El plugin mostrará un aviso si no lo está.
    * Revisa los logs de errores de PHP de tu servidor para mensajes de este plugin (buscar por "TRM Colombia WOOCS Error" o "TRM Colombia WOOCS Debug").
    * El servicio de la Superfinanciera podría estar temporalmente inaccesible. El plugin intentará de nuevo cuando el transient expire.
* **Error "SOAP extension is not enabled":** Contacta a tu proveedor de hosting para que habiliten la extensión SOAP en tu servidor PHP.

## Estructura del Código Fuente

El plugin está estructurado de la siguiente manera:

* `trm-colombia-woocommerce.php`: Archivo principal del plugin que carga la clase principal.
* Contiene la clase `TRM_Colombia_WOOCS_Plugin` que encapsula toda la funcionalidad:
    * `__construct()`: Constructor privado para el patrón Singleton.
    * `get_instance()`: Método estático para obtener la instancia del plugin.
    * `init()`: Inicializa hooks y chequeos.
    * `is_woocs_active()`: Verifica si WOOCS está activo.
    * `show_woocs_not_active_notice()`: Muestra aviso si WOOCS no está activo.
    * `show_soap_not_active_notice()`: Muestra aviso si SOAP no está activo.
    * `announce_custom_aggregator()`: Registra el agregador con WOOCS.
    * `filter_woocs_currency_rates()`: Hook principal para modificar las tasas de WOOCS.
    * `fetch_trm_from_superfinanciera()`: Lógica para obtener la TRM vía SOAP.
* `trm_colombia_woocs_plugin()`: Función global para acceder a la instancia del plugin.

## Contribuciones

Las contribuciones son bienvenidas. Por favor, abre un issue o un pull request en el repositorio de GitHub (si está disponible) o contacta al autor.

---

*Este es un plugin personalizado y no está afiliado ni respaldado por la Superintendencia Financiera de Colombia ni por los desarrolladores del plugin WOOCS.*

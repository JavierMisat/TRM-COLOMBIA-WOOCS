# TRM Colombia for WooCommerce (Custom)

**Actualiza la tasa de cambio USD/COP diariamente usando la TRM oficial de la Superintendencia Financiera de Colombia para el plugin WOOCS.**

Este plugin para WordPress se integra con WooCommerce y el plugin [WOOCS - WooCommerce Currency Switcher](https://wordpress.org/plugins/woocommerce-currency-switcher/) para proporcionar tasas de cambio actualizadas automáticamente entre el dólar estadounidense (USD) y el peso colombiano (COP). Utiliza el servicio web oficial de la Superintendencia Financiera de Colombia para obtener la Tasa Representativa del Mercado (TRM) más reciente.

## Características

*   **Actualización Automática de la TRM:** Obtiene la TRM oficial de Colombia diariamente.
*   **Integración con WOOCS:** Modifica la tasa de cambio USD/COP directamente en el plugin WOOCS.
*   **Manejo de Errores:** Si la obtención de la TRM falla, se mantiene la tasa anterior para evitar interrupciones.
*   **Optimización con Transients:** Almacena la TRM en un transient de WordPress para mejorar el rendimiento y evitar solicitudes excesivas al servicio web. El transient se actualiza cada 12 horas.
*   **Fácil de Usar:** Simplemente instala y activa el plugin. No requiere configuración adicional.

## ¿Cómo Funciona?

1.  El plugin se engancha al filtro `woocs_currency_rates_custom` proporcionado por el plugin WOOCS.
2.  Cuando WOOCS necesita las tasas de cambio, este plugin verifica si la moneda actual es 'USD'.
3.  Intenta obtener la TRM almacenada en un transient de WordPress (`trm_colombia_rate`).
4.  Si el transient no existe o ha expirado:
    *   Realiza una solicitud SOAP al servicio web de la Superintendencia Financiera de Colombia (`https://www.superfinanciera.gov.co/SuperfinancieraWebServiceTRM/TCRMServicesWebService/TCRMServicesWebService?WSDL`) para obtener la TRM del día actual.
    *   Si la solicitud es exitosa, almacena el valor de la TRM en el transient con una duración de 12 horas.
    *   Si la solicitud falla, se registra un error y el plugin utiliza la tasa de cambio previamente configurada en WOOCS para evitar problemas.
5.  Calcula la tasa de cambio para WOOCS como `1 / TRM` (ya que WOOCS espera la tasa de la moneda base, que se asume es COP en este contexto, respecto a USD).
6.  Devuelve las tasas actualizadas a WOOCS.

## Requisitos

*   WordPress
*   WooCommerce
*   WOOCS - WooCommerce Currency Switcher plugin instalado y activado.
*   La moneda base de WooCommerce debe estar configurada en COP y USD debe estar agregada como una moneda adicional en WOOCS.
*   Extensión SOAP de PHP habilitada en tu servidor.

## Instalación

1.  Descarga el archivo `.zip` del plugin.
2.  Ve a tu panel de administración de WordPress -> Plugins -> Añadir nuevo.
3.  Haz clic en "Subir plugin" y selecciona el archivo `.zip` que descargaste.
4.  Activa el plugin a través del menú 'Plugins' en WordPress.

¡Eso es todo! El plugin comenzará a actualizar la tasa USD/COP automáticamente.

## Autor

Javier Misat

## Contribuciones

Las contribuciones son bienvenidas. Por favor, abre un issue o un pull request en el repositorio de GitHub.

---

*Este es un plugin personalizado y no está afiliado ni respaldado por la Superintendencia Financiera de Colombia ni por los desarrolladores del plugin WOOCS.*

<?php
/**
 * Plugin Name: TRM Colombia for WooCommerce (Custom & Improved)
 * Description: Actualiza la tasa de cambio USD/COP diariamente usando la TRM oficial de la Superintendencia Financiera de Colombia. Se integra como un agregador en WOOCS.
 * Version: 2.0
 * Author: Javier Misat (Mejorado por Asistente AI)
 * Author URI: https://github.com/jmisat
 * Text Domain: trm-colombia-woocs
 * License: GPL-2.0-or-later
 * License URI: http://www.gnu.org/licenses/gpl-2.0.html
 * WC requires at least: 3.0
 * WC tested up to: 8.8
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Salir si se accede directamente.
}

define( 'TRM_COLOMBIA_WOOCS_PATH', plugin_dir_path( __FILE__ ) );
define( 'TRM_COLOMBIA_WOOCS_URL', plugin_dir_url( __FILE__ ) );
define( 'TRM_COLOMBIA_WOOCS_AGGREGATOR_ID', 'trm_colombia_superfinanciera' );
define( 'TRM_COLOMBIA_WOOCS_TRANSIENT_KEY', 'trm_colombia_rate_v2' );
define( 'TRM_COLOMBIA_WOOCS_TRANSIENT_EXPIRATION', 12 * HOUR_IN_SECONDS );

/**
 * Clase principal del plugin TRM Colombia WOOCS
 */
final class TRM_Colombia_WOOCS_Plugin {

    /**
     * Instancia única de la clase.
     * @var TRM_Colombia_WOOCS_Plugin
     */
    private static $instance;

    /**
     * Constructor privado para evitar la creación directa de objetos.
     */
    private function __construct() {
        add_action( 'plugins_loaded', array( $this, 'init' ) );
    }

    /**
     * Obtiene la instancia única de la clase.
     * @return TRM_Colombia_WOOCS_Plugin
     */
    public static function get_instance(): TRM_Colombia_WOOCS_Plugin {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Inicializa el plugin.
     * Carga el text domain y verifica las dependencias.
     */
    public function init(): void {
        load_plugin_textdomain( 'trm-colombia-woocs', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );

        if ( ! $this->is_woocs_active() ) {
            add_action( 'admin_notices', array( $this, 'show_woocs_not_active_notice' ) );
            return;
        }
        
        if ( ! class_exists( 'SoapClient' ) ) {
            add_action( 'admin_notices', array( $this, 'show_soap_not_active_notice' ) );
            // No retornamos aquí, ya que el admin necesita ver el aviso.
            // La funcionalidad de obtención de TRM fallará y se registrará.
        }

        // Anunciar este plugin como un agregador de tasas para WOOCS
        add_action( 'woocs_announce_aggregator', array( $this, 'announce_custom_aggregator' ) );

        // Filtrar las tasas de WOOCS si nuestro agregador está seleccionado
        add_filter( 'woocs_currency_rates_custom', array( $this, 'filter_woocs_currency_rates' ), 10, 2 );
    }

    /**
     * Verifica si el plugin WOOCS - WooCommerce Currency Switcher está activo.
     * @return bool True si WOOCS está activo, false en caso contrario.
     */
    private function is_woocs_active(): bool {
        if ( ! function_exists( 'is_plugin_active' ) ) {
            include_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        // Comprobar varias posibles rutas del plugin WOOCS
        $possible_woocs_paths = array(
            'woocommerce-currency-switcher/index.php', // Ruta común
            'currency-switcher/index.php', // Otra posible ruta si el slug es diferente
        );
        foreach ($possible_woocs_paths as $path) {
            if (is_plugin_active($path)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Muestra un aviso en el panel de administración si WOOCS no está activo.
     */
    public function show_woocs_not_active_notice(): void {
        ?>
        <div class="notice notice-error is-dismissible">
            <p>
                <?php
                printf(
                    /* translators: %s: Plugin name */
                    esc_html__( 'El plugin "%s" requiere que el plugin WOOCS - WooCommerce Currency Switcher esté activo y configurado.', 'trm-colombia-woocs' ),
                    '<strong>TRM Colombia for WooCommerce (Custom & Improved)</strong>'
                );
                ?>
            </p>
        </div>
        <?php
    }
    
    /**
     * Muestra un aviso en el panel de administración si la extensión SOAP no está activa.
     */
    public function show_soap_not_active_notice(): void {
        ?>
        <div class="notice notice-error is-dismissible">
            <p>
                <?php
                printf(
                    /* translators: %s: Plugin name */
                    esc_html__( 'El plugin "%s" requiere que la extensión SOAP de PHP esté habilitada en su servidor para funcionar correctamente.', 'trm-colombia-woocs' ),
                    '<strong>TRM Colombia for WooCommerce (Custom & Improved)</strong>'
                );
                ?>
            </p>
        </div>
        <?php
    }


    /**
     * Anuncia este plugin como un agregador de tasas personalizado a WOOCS.
     * @param array $aggregators Array de agregadores existentes.
     * @return array Array de agregadores modificado.
     */
    public function announce_custom_aggregator( array $aggregators ): array {
        global $WOOCS;
        if (is_object($WOOCS)) {
             // El método para añadir agregadores puede variar ligeramente entre versiones de WOOCS.
             // Usualmente es algo como add_aggregator_processor o directamente modificar la propiedad.
             // Esta es una forma común de hacerlo:
            $WOOCS->add_aggregator_processor(TRM_COLOMBIA_WOOCS_AGGREGATOR_ID, __('TRM Colombia (Superfinanciera)', 'trm-colombia-woocs'));
        }
        // En versiones más antiguas o si el método anterior no existe, podrías necesitar esto:
        // $aggregators[TRM_COLOMBIA_WOOCS_AGGREGATOR_ID] = __('TRM Colombia (Superfinanciera)', 'trm-colombia-woocs');
        // return $aggregators; 
        // Sin embargo, woocs_announce_aggregator es una acción, no un filtro, por lo que no devolvemos $aggregators.
        // La función add_aggregator_processor se encarga de registrarlo.
        return $aggregators; // Aunque es una acción, algunas versiones de WOOCS podrían esperar esto. Mejor ser compatible.
    }

    /**
     * Filtra las tasas de cambio de WOOCS si nuestro agregador está seleccionado.
     * @param array  $rates    Tasas de cambio existentes.
     * @param string $currency Código de la moneda actual (ej. 'USD').
     * @return array|float Tasas de cambio modificadas o la tasa específica.
     */
    public function filter_woocs_currency_rates( array $rates, string $currency_code_being_processed ) {
        global $WOOCS;

        // Verificar si nuestro agregador está seleccionado en la configuración de WOOCS
        if ( ! is_object( $WOOCS ) || $WOOCS->current_currency_aggregator !== TRM_COLOMBIA_WOOCS_AGGREGATOR_ID ) {
            return $rates; // No hacer nada si no es nuestro agregador
        }
        
        // Este filtro se llama por moneda. Solo nos interesa USD.
        // WOOCS espera que la tasa sea cuántas unidades de la moneda base equivalen a 1 unidad de $currency_code_being_processed.
        // Ejemplo: Si la base es COP y $currency_code_being_processed es USD, y TRM es 4000, la tasa es 4000.
        if ( 'USD' === $currency_code_being_processed ) {
            $trm = get_transient( TRM_COLOMBIA_WOOCS_TRANSIENT_KEY );

            if ( false === $trm ) {
                $fetched_trm = $this->fetch_trm_from_superfinanciera();
                if ( is_wp_error( $fetched_trm ) ) {
                    error_log( sprintf(
                        'TRM Colombia WOOCS Error: No se pudo obtener la TRM. Código: %s, Mensaje: %s',
                        $fetched_trm->get_error_code(),
                        $fetched_trm->get_error_message()
                    ) );
                    // Si falla, WOOCS usará la última tasa conocida o la tasa manual.
                    // Devolver $rates sin modificar para la moneda USD podría ser una opción,
                    // o si $rates['USD'] ya tiene un valor, mantenerlo.
                    // Si queremos forzar a que no se actualice y se use la tasa anterior de WOOCS,
                    // simplemente retornamos $rates. Si $rates está vacío para USD, WOOCS podría no mostrarla.
                    // Es más seguro devolver $rates tal cual.
                    return $rates; 
                }
                
                $trm = $fetched_trm;
                set_transient( TRM_COLOMBIA_WOOCS_TRANSIENT_KEY, $trm, TRM_COLOMBIA_WOOCS_TRANSIENT_EXPIRATION );
            }
            
            // Si la moneda base es COP, y la TRM es (COP por USD), la tasa para USD es directamente la TRM.
            // $rates es un array de tasas, pero este filtro se llama por moneda.
            // La documentación de `woocs_currency_rates_custom` indica que puede devolver
            // el array completo de tasas o solo la tasa para la moneda actual.
            // Para ser más precisos con el propósito del filtro por moneda:
            return floatval( $trm ); // Devolvemos solo la tasa para USD.
        }

        return $rates; // Para otras monedas, devolver las tasas sin cambios.
    }

    /**
     * Obtiene la TRM desde el servicio web de la Superintendencia Financiera de Colombia.
     * @return float|WP_Error La TRM como float en caso de éxito, o WP_Error en caso de fallo.
     */
    private function fetch_trm_from_superfinanciera() {
        if ( ! class_exists( 'SoapClient' ) ) {
            return new WP_Error(
                'soap_client_missing',
                __( 'La extensión SOAP de PHP no está habilitada en el servidor.', 'trm-colombia-woocs' )
            );
        }

        $wsdl_url = 'https://www.superfinanciera.gov.co/SuperfinancieraWebServiceTRM/TCRMServicesWebService/TCRMServicesWebService?WSDL';
        // La fecha para la TRM. Usualmente es la TRM vigente para el día consultado.
        // El servicio de la Superfinanciera devuelve la TRM vigente para la fecha consultada.
        // Si se consulta un sábado, domingo o festivo, devuelve la del último día hábil.
        $query_date = date( 'Y-m-d' ); 

        $soap_options = array(
            'trace' => true, // Permite depurar errores
            'exceptions' => true, // Lanza excepciones en errores SOAP
            'connection_timeout' => 10, // Timeout de conexión en segundos
            'cache_wsdl' => WSDL_CACHE_MEMORY, // Cachear WSDL en memoria
        );

        try {
            $soap_client = new SoapClient( $wsdl_url, $soap_options );
            $params      = array( 'tcrmQueryAssociatedDate' => $query_date );
            $response    = $soap_client->__soapCall( 'queryTCRM', array( $params ) );

            if ( isset( $response->return, $response->return->value ) && is_numeric( $response->return->value ) ) {
                $trm_value = floatval( $response->return->value );
                if ($trm_value > 0) {
                    return $trm_value;
                } else {
                     return new WP_Error(
                        'invalid_trm_value_received',
                        sprintf(
                            /* translators: %s: Valor TRM recibido. */
                            __( 'Se recibió un valor TRM no positivo o inválido: %s', 'trm-colombia-woocs' ),
                            esc_html( strval($response->return->value) )
                        )
                    );
                }
            } elseif (isset($response->return, $response->return->message) && !empty($response->return->message)) {
                // A veces la Superfinanciera devuelve un mensaje de error en la estructura 'return->message'
                // cuando no hay TRM para la fecha (ej. un futuro muy lejano o fecha inválida).
                return new WP_Error(
                    'superfinanciera_api_message',
                    sprintf(
                        /* translators: %s: Mensaje de la API de Superfinanciera. */
                        __( 'La API de Superfinanciera devolvió un mensaje: %s', 'trm-colombia-woocs' ),
                        esc_html( $response->return->message )
                    )
                );
            } else {
                 // Log detallado de la respuesta para depuración
                $response_dump = print_r($response, true);
                error_log('TRM Colombia WOOCS Debug: Respuesta inesperada de Superfinanciera: ' . $response_dump);
                return new WP_Error(
                    'invalid_superfinanciera_response',
                    __( 'La respuesta del servicio de Superfinanciera no tuvo el formato esperado o el valor de la TRM no fue encontrado.', 'trm-colombia-woocs' )
                );
            }
        } catch ( SoapFault $e ) {
            // Captura excepciones específicas de SOAP
            return new WP_Error(
                'soap_fault',
                sprintf(
                    /* translators: 1: Código de error SOAP, 2: Mensaje de error SOAP. */
                    __( 'Error SOAP al contactar el servicio de Superfinanciera. Código: %1$s - Mensaje: %2$s', 'trm-colombia-woocs' ),
                    $e->faultcode,
                    $e->getMessage()
                )
            );
        } catch ( Exception $e ) {
            // Captura otras excepciones generales
            return new WP_Error(
                'generic_fetch_error',
                sprintf(
                    /* translators: %s: Mensaje de error. */
                    __( 'Error general al obtener la TRM: %s', 'trm-colombia-woocs' ),
                    $e->getMessage()
                )
            );
        }
    }
}

/**
 * Función para obtener la instancia principal del plugin.
 * Es la forma correcta de acceder a la instancia del plugin desde fuera de la clase.
 */
function trm_colombia_woocs_plugin(): TRM_Colombia_WOOCS_Plugin {
    return TRM_Colombia_WOOCS_Plugin::get_instance();
}

// Iniciar el plugin
trm_colombia_woocs_plugin();


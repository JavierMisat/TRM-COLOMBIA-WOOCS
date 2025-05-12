<?php
/**
 * Plugin Name: TRM Colombia for WooCommerce (Custom & Improved)
 * Description: Actualiza la tasa de cambio USD/COP diariamente usando la TRM oficial de la Superintendencia Financiera de Colombia. Se integra como un agregador en WOOCS.
 * Version: 2.0.1
 * Author: Javier Misat
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
define( 'TRM_COLOMBIA_WOOCS_TRANSIENT_KEY', 'trm_colombia_rate_v2' ); // Se mantiene v2 para compatibilidad de transients existentes.
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
        add_action( 'plugins_loaded', array( $this, 'init' ), 20 ); // Prioridad 20 para asegurar que WOOCS pueda estar cargado.
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

        // Registrar nuestro agregador con WOOCS usando el filtro apropiado.
        add_filter( 'woocs_currency_aggregators', array( $this, 'add_trm_aggregator_to_list' ) );

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
            'currency-switcher/index.php', 
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
     * Añade el agregador TRM Colombia a la lista de agregadores de WOOCS.
     * @param array $aggregators Array de agregadores existentes.
     * @return array Array de agregadores modificado.
     */
    public function add_trm_aggregator_to_list( array $aggregators ): array {
        $aggregators[TRM_COLOMBIA_WOOCS_AGGREGATOR_ID] = __( 'TRM Colombia (Superfinanciera)', 'trm-colombia-woocs' );
        return $aggregators;
    }

    /**
     * Filtra las tasas de cambio de WOOCS si nuestro agregador está seleccionado.
     * @param array|float $rates Tasas de cambio existentes o tasa de la moneda anterior.
     * @param string $currency_code_being_processed Código de la moneda actual (ej. 'USD').
     * @return array|float Tasas de cambio modificadas o la tasa específica.
     */
    public function filter_woocs_currency_rates( $rates, string $currency_code_being_processed ) {
        global $WOOCS;

        if ( ! is_object( $WOOCS ) || 
             ! isset( $WOOCS->current_currency_aggregator ) ||
             $WOOCS->current_currency_aggregator !== TRM_COLOMBIA_WOOCS_AGGREGATOR_ID ) {
            return $rates; 
        }
        
        if ( 'USD' === $currency_code_being_processed ) {
            $trm_value = get_transient( TRM_COLOMBIA_WOOCS_TRANSIENT_KEY );

            if ( false === $trm_value ) {
                $fetched_trm = $this->fetch_trm_from_superfinanciera();

                if ( is_wp_error( $fetched_trm ) ) {
                    error_log( sprintf(
                        'TRM Colombia WOOCS Error: No se pudo obtener la TRM. Código: %s, Mensaje: %s',
                        $fetched_trm->get_error_code(),
                        $fetched_trm->get_error_message()
                    ) );
                    
                    // Si $rates es un array y contiene la tasa para USD, devolverla.
                    if (is_array($rates) && isset($rates[$currency_code_being_processed])) {
                        return $rates[$currency_code_being_processed];
                    }
                    // Si $rates es un float (porque WOOCS a veces pasa la tasa de la moneda anterior),
                    // y no es para USD, no podemos usarla.
                    // Devolver 0 o una tasa por defecto si no se puede obtener y no hay valor previo.
                    return 0; 
                }
                
                $trm_value = $fetched_trm;
                set_transient( TRM_COLOMBIA_WOOCS_TRANSIENT_KEY, $trm_value, TRM_COLOMBIA_WOOCS_TRANSIENT_EXPIRATION );
            }
            
            return floatval( $trm_value ); 
        }

        // Manejo para otras monedas o cuando $rates es un array.
        if (is_array($rates)) {
            // Si $rates es un array, devolver la tasa para la moneda actual si existe,
            // o el array completo si este filtro se usa para obtener todas las tasas.
            // WOOCS puede llamar a este filtro de forma que espera el array completo modificado.
            return $rates[$currency_code_being_processed] ?? $rates; 
        }
        // Si $rates no es un array, se asume que es la tasa de una moneda anterior,
        // y como no es USD, la devolvemos sin cambios.
        return $rates; 
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
        $query_date = date( 'Y-m-d' ); 

        $soap_options = array(
            'trace' => true, 
            'exceptions' => true, 
            'connection_timeout' => 15, 
            'cache_wsdl' => WSDL_CACHE_MEMORY, 
            'user_agent' => 'WordPress TRM Colombia Plugin/' . $this->get_plugin_version(), 
        );

        try {
            $soap_client = new SoapClient( $wsdl_url, $soap_options );
            $call_params = array( 'tcrmQueryAssociatedDate' => $query_date );
            $response    = $soap_client->__soapCall( 'queryTCRM', array( $call_params ) );

            $trm_value_candidate = null;
            if (isset($response->return, $response->return->value)) {
                $trm_value_candidate = $response->return->value;
            } elseif (isset($response->queryTCRMReturn, $response->queryTCRMReturn->value)) { 
                $trm_value_candidate = $response->queryTCRMReturn->value;
            }

            if ( null !== $trm_value_candidate && is_numeric( $trm_value_candidate ) ) {
                $trm_value = floatval( $trm_value_candidate );
                if ($trm_value > 0) {
                    return $trm_value;
                } else {
                     return new WP_Error(
                        'invalid_trm_value_received',
                        sprintf(
                            __( 'Se recibió un valor TRM no positivo o inválido: %s', 'trm-colombia-woocs' ),
                            esc_html( strval($trm_value_candidate) )
                        )
                    );
                }
            } elseif (isset($response->return, $response->return->message) && !empty($response->return->message)) {
                return new WP_Error(
                    'superfinanciera_api_message',
                    sprintf(
                        __( 'La API de Superfinanciera devolvió un mensaje: %s', 'trm-colombia-woocs' ),
                        esc_html( $response->return->message )
                    )
                );
            } else {
                $response_dump = print_r($response, true);
                error_log('TRM Colombia WOOCS Debug: Respuesta inesperada de Superfinanciera: ' . $response_dump);
                return new WP_Error(
                    'invalid_superfinanciera_response',
                    __( 'La respuesta del servicio de Superfinanciera no tuvo el formato esperado o el valor de la TRM no fue encontrado.', 'trm-colombia-woocs' )
                );
            }
        } catch ( SoapFault $e ) {
            error_log( 'TRM Colombia WOOCS SoapFault: ' . $e->getMessage() . ' | Trace: ' . $e->getTraceAsString() );
            return new WP_Error(
                'soap_fault',
                sprintf(
                    __( 'Error SOAP al contactar el servicio de Superfinanciera. Código: %1$s - Mensaje: %2$s', 'trm-colombia-woocs' ),
                    $e->faultcode,
                    $e->getMessage()
                )
            );
        } catch ( Exception $e ) {
            error_log( 'TRM Colombia WOOCS Exception: ' . $e->getMessage() . ' | Trace: ' . $e->getTraceAsString() );
            return new WP_Error(
                'generic_fetch_error',
                sprintf(
                    __( 'Error general al obtener la TRM: %s', 'trm-colombia-woocs' ),
                    $e->getMessage()
                )
            );
        }
    }
    
    /**
     * Obtiene la versión del plugin desde la cabecera del archivo.
     * @return string Versión del plugin.
     */
    private function get_plugin_version(): string {
        if ( ! function_exists( 'get_plugin_data' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        // Asegúrate de que el plugin principal es __FILE__ dentro de esta clase.
        $plugin_file = TRM_COLOMBIA_WOOCS_PATH . basename(dirname(TRM_COLOMBIA_WOOCS_PATH)) . '.php'; // Asume que el archivo principal está un nivel arriba o tiene un nombre específico
        // Para ser más robusto, si el archivo principal es este mismo:
        $plugin_file_path = __FILE__; 
        
        $plugin_data = get_plugin_data( $plugin_file_path );
        return $plugin_data['Version'] ?? '1.0.0'; // Fallback a una versión por defecto si no se puede leer
    }
}

/**
 * Función para obtener la instancia principal del plugin.
 */
function trm_colombia_woocs_plugin(): TRM_Colombia_WOOCS_Plugin {
    return TRM_Colombia_WOOCS_Plugin::get_instance();
}

// Iniciar el plugin
trm_colombia_woocs_plugin();


<?php
/**
 * Plugin Name: TRM Colombia for WooCommerce (Custom & Improved)
 * Description: Actualiza la tasa de cambio USD/COP diariamente usando la TRM oficial de la Superintendencia Financiera de Colombia. Se integra como un agregador en WOOCS.
 * Version: 2.0.6
 * Author: Javier Misat 
 * Author URI: https://github.com/javiermisat
 * Text Domain: trm-colombia-woocs
 * License: GPL-2.0-or-later
 * License URI: http://www.gnu.org/licenses/gpl-2.0.html
 * WC requires at least: 3.0
 * WC tested up to: 8.8
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Salir si se accede directamente.
}

/**
 * Declara la compatibilidad con High-Performance Order Storage (HPOS).
 */
add_action( 'before_woocommerce_init', function() {
    if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
    }
} );

define( 'TRM_COLOMBIA_WOOCS_PATH', plugin_dir_path( __FILE__ ) );
define( 'TRM_COLOMBIA_WOOCS_URL', plugin_dir_url( __FILE__ ) );
define( 'TRM_COLOMBIA_WOOCS_AGGREGATOR_ID', 'trm_colombia_superfinanciera' );
// El textdomain se cargará en 'plugins_loaded' con prioridad 8, antes de que esta constante se use efectivamente.
define( 'TRM_COLOMBIA_WOOCS_AGGREGATOR_NAME', __( 'TRM Colombia (Superfinanciera)', 'trm-colombia-woocs' ) );
define( 'TRM_COLOMBIA_WOOCS_TRANSIENT_KEY', 'trm_colombia_rate_v2' );
define( 'TRM_COLOMBIA_WOOCS_TRANSIENT_EXPIRATION', 12 * HOUR_IN_SECONDS );

/**
 * Clase principal del plugin TRM Colombia WOOCS
 */
final class TRM_Colombia_WOOCS_Plugin {

    private static $instance;

    private function __construct() {
        // Cargar el textdomain en 'plugins_loaded' con prioridad 8 (antes de plugin_setup).
        add_action( 'plugins_loaded', array( $this, 'load_textdomain_action' ), 8 );
        
        // Inicializar el resto de la funcionalidad del plugin en 'plugins_loaded' con prioridad 9.
        add_action( 'plugins_loaded', array( $this, 'plugin_setup' ), 9 );
    }

    public static function get_instance(): TRM_Colombia_WOOCS_Plugin {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Carga el textdomain del plugin.
     * Se engancha al hook 'plugins_loaded' de WordPress con prioridad 8.
     */
    public function load_textdomain_action(): void {
        load_plugin_textdomain( 'trm-colombia-woocs', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
        error_log('TRM Colombia WOOCS: Textdomain loaded at plugins_loaded priority 8.');
    }

    /**
     * Configuración principal del plugin después de que todos los plugins estén cargados.
     * Se engancha a 'plugins_loaded' con prioridad 9.
     */
    public function plugin_setup(): void {
        error_log('TRM Colombia WOOCS: plugin_setup called at plugins_loaded priority 9.');

        if ( ! $this->is_woocs_active() ) {
            error_log('TRM Colombia WOOCS: WOOCS plugin not detected as active in plugin_setup. TRM plugin will not initialize fully.');
            add_action( 'admin_notices', array( $this, 'show_woocs_not_active_notice' ) );
            return;
        }
        
        error_log('TRM Colombia WOOCS: WOOCS plugin detected in plugin_setup. Initializing TRM hooks.');

        if ( ! class_exists( 'SoapClient' ) ) {
            add_action( 'admin_notices', array( $this, 'show_soap_not_active_notice' ) );
        }

        // Registrar el agregador usando el filtro 'woocs_announce_aggregator'
        add_filter( 'woocs_announce_aggregator', array( $this, 'add_custom_aggregator_to_list' ) );
        error_log('TRM Colombia WOOCS: woocs_announce_aggregator filter ADDED in plugin_setup.');


        // Filtrar las tasas de WOOCS si nuestro agregador está seleccionado
        add_filter( 'woocs_currency_rates_custom', array( $this, 'filter_woocs_currency_rates' ), 10, 2 );
    }

    private function is_woocs_active(): bool {
        if ( ! function_exists( 'is_plugin_active' ) ) {
            include_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $possible_woocs_paths = array(
            'woocommerce-currency-switcher/index.php', 
            'currency-switcher/index.php',             
        );
        foreach ($possible_woocs_paths as $path) {
            if (is_plugin_active($path)) {
                error_log('TRM Colombia WOOCS: Detected WOOCS active with path: ' . $path . ' in is_woocs_active.');
                return true;
            }
        }
        error_log('TRM Colombia WOOCS: WOOCS plugin not found active with checked paths in is_woocs_active: ' . implode(', ', $possible_woocs_paths));
        return false;
    }

    public function show_woocs_not_active_notice(): void {
        ?>
        <div class="notice notice-error is-dismissible">
            <p>
                <?php
                printf(
                    esc_html__( 'El plugin "%s" requiere que el plugin WOOCS - WooCommerce Currency Switcher esté activo y configurado.', 'trm-colombia-woocs' ),
                    '<strong>TRM Colombia for WooCommerce (Custom & Improved)</strong>'
                );
                ?>
            </p>
        </div>
        <?php
    }
    
    public function show_soap_not_active_notice(): void {
        ?>
        <div class="notice notice-error is-dismissible">
            <p>
                <?php
                printf(
                    esc_html__( 'El plugin "%s" requiere que la extensión SOAP de PHP esté habilitada en su servidor para funcionar correctamente.', 'trm-colombia-woocs' ),
                    '<strong>TRM Colombia for WooCommerce (Custom & Improved)</strong>'
                );
                ?>
            </p>
        </div>
        <?php
    }

    public function add_custom_aggregator_to_list( array $aggregators ): array {
        error_log('TRM Colombia WOOCS: add_custom_aggregator_to_list filter CALLBACK EXECUTED (using woocs_announce_aggregator).'); 
        
        $aggregators[TRM_COLOMBIA_WOOCS_AGGREGATOR_ID] = TRM_COLOMBIA_WOOCS_AGGREGATOR_NAME; 
        
        error_log('TRM Colombia WOOCS: Aggregators list after adding custom one: ' . print_r($aggregators, true));
        return $aggregators;
    }

    public function filter_woocs_currency_rates( $rates, string $currency_code_being_processed ) {
        global $WOOCS;

        if ( ! is_object( $WOOCS ) || ! isset($WOOCS->current_currency_aggregator) || $WOOCS->current_currency_aggregator !== TRM_COLOMBIA_WOOCS_AGGREGATOR_ID ) {
            return $rates; 
        }
        
        error_log('TRM Colombia WOOCS: filter_woocs_currency_rates called for currency: ' . $currency_code_being_processed . ' with selected aggregator.');

        if ( 'USD' === $currency_code_being_processed ) {
            $trm = get_transient( TRM_COLOMBIA_WOOCS_TRANSIENT_KEY );

            if ( false === $trm || ! is_numeric( $trm ) ) { 
                error_log('TRM Colombia WOOCS: TRM not in transient or invalid. Fetching from Superfinanciera.');
                $fetched_trm = $this->fetch_trm_from_superfinanciera();
                if ( is_wp_error( $fetched_trm ) ) {
                    error_log( sprintf(
                        'TRM Colombia WOOCS Error: No se pudo obtener la TRM. Código: %s, Mensaje: %s',
                        $fetched_trm->get_error_code(),
                        $fetched_trm->get_error_message()
                    ) );
                    return $rates; 
                }
                
                $trm = $fetched_trm;
                set_transient( TRM_COLOMBIA_WOOCS_TRANSIENT_KEY, $trm, TRM_COLOMBIA_WOOCS_TRANSIENT_EXPIRATION );
                error_log('TRM Colombia WOOCS: TRM fetched and set in transient: ' . $trm);
            } else {
                error_log('TRM Colombia WOOCS: TRM retrieved from transient: ' . $trm);
            }
            
            return floatval( $trm );
        }

        return $rates;
    }

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
        );

        try {
            $soap_client = new SoapClient( $wsdl_url, $soap_options );
            $params      = array( 'tcrmQueryAssociatedDate' => $query_date );
            $response    = $soap_client->__soapCall( 'queryTCRM', array( $params ) );

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
            error_log( 'TRM Colombia WOOCS SoapFault: ' . $e->getMessage() . ' | WSDL: ' . $wsdl_url );
            if (isset($soap_client)) {
                error_log( 'TRM Colombia WOOCS Last SOAP Request Headers: ' . print_r($soap_client->__getLastRequestHeaders(), true) );
                error_log( 'TRM Colombia WOOCS Last SOAP Request: ' . print_r($soap_client->__getLastRequest(), true) );
                error_log( 'TRM Colombia WOOCS Last SOAP Response Headers: ' . print_r($soap_client->__getLastResponseHeaders(), true) );
                error_log( 'TRM Colombia WOOCS Last SOAP Response: ' . print_r($soap_client->__getLastResponse(), true) );
            }
            return new WP_Error(
                'soap_fault',
                sprintf(
                    __( 'Error SOAP al contactar el servicio de Superfinanciera. Código: %1$s - Mensaje: %2$s', 'trm-colombia-woocs' ),
                    $e->faultcode,
                    $e->getMessage()
                )
            );
        } catch ( Exception $e ) {
            error_log( 'TRM Colombia WOOCS Generic Exception: ' . $e->getMessage() );
            return new WP_Error(
                'generic_fetch_error',
                sprintf(
                    __( 'Error general al obtener la TRM: %s', 'trm-colombia-woocs' ),
                    $e->getMessage()
                )
            );
        }
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


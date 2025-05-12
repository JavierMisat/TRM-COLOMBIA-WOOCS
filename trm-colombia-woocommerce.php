<?php
/**
 * Plugin Name: TRM Colombia for WooCommerce (Custom)
 * Description: Actualiza la tasa de cambio USD/COP diariamente usando la TRM oficial de la Superintendencia Financiera de Colombia para el plugin WOOCS.
 * Version: 1.0
 * Author: Javier Misat
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

add_filter( 'woocs_currency_rates_custom', 'custom_trm_colombia_rate', 10, 2 );

function custom_trm_colombia_rate( $rates, $currency ) {
    if ( $currency === 'USD' ) {
        $trm = get_transient( 'trm_colombia_rate' );

        if ( false === $trm ) {
            $trm = fetch_trm_from_superfinanciera();
            if ( $trm ) {
                set_transient( 'trm_colombia_rate', $trm, 12 * HOUR_IN_SECONDS );
            } else {
                return $rates; // Fall back to previous rate if fetch fails
            }
        }

        $rates = 1 / floatval( $trm );
    }

    return $rates;
}

function fetch_trm_from_superfinanciera() {
    $wsdl = 'https://www.superfinanciera.gov.co/SuperfinancieraWebServiceTRM/TCRMServicesWebService/TCRMServicesWebService?WSDL';
    $soap = new SoapClient( $wsdl, array( 'trace' => true, 'exceptions' => true ) );

    $params = array( 'tcrmQueryAssociatedDate' => date( 'Y-m-d' ) );

    try {
        $result = $soap->__soapCall( 'queryTCRM', array( $params ) );

        if ( isset( $result->queryTCRMReturn->value ) ) {
            return floatval( $result->queryTCRMReturn->value );
        }
    } catch ( Exception $e ) {
        error_log( 'TRM fetch error: ' . $e->getMessage() );
    }

    return false;
}

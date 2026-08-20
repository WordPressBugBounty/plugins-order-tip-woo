<?php
/**
 * ShipDay Compatibility Layer
 *
 * @package Order Tip for WooCommerce
 * @author  Adrian Emil Tudorache
 * @license GPL-2.0+
 * @link    https://www.tudorache.me/
 * @since   1.6.1
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WOO_Order_Tip_ShipDay_Compatibility {

    /**
     * Plugin settings
     * @var array
     */
    private $settings;

    /**
     * Constructor
     * Hooks into WooCommerce order creation process
     */
    public function __construct() {
        $this->settings = WOO_Order_Tip_Service::get_settings();

        add_action( 'woocommerce_checkout_create_order', array( $this, 'save_tip_to_order_meta' ), 20, 2 );

        add_action( 'woocommerce_checkout_update_order_meta', array( $this, 'save_tip_to_order_meta_on_update' ), 20, 1 );

        add_action( 'woocommerce_process_shop_order_meta', array( $this, 'save_tip_to_order_meta_admin' ), 20, 1 );

        add_action( 'woocommerce_update_order', array( $this, 'save_tip_to_order_meta_hpos' ), 20, 1 );
    }

    /**
     * Save tip amount to order meta data for ShipDay compatibility
     * Called during checkout order creation
     *
     * @param WC_Order $order The order object being created
     * @param array    $data  The checkout posted data
     */
    public function save_tip_to_order_meta( $order, $data ) {
        
        $tip_data = WOO_Order_Tip_Service::get_tip_data();

        if ( empty( $tip_data ) || ! isset( $tip_data['tip_amount'] ) || $tip_data['tip_amount'] <= 0 ) {
            return;
        }

        $this->add_tip_meta_to_order( $order, $tip_data );
    }

    /**
     * Save tip to order meta when order meta is updated
     * Backup hook in case create_order doesn't fire
     *
     * @param int $order_id The order ID
     */
    public function save_tip_to_order_meta_on_update( $order_id ) {
        $order = wc_get_order( $order_id );

        if ( ! $order ) {
            return;
        }

        $existing_tip = $order->get_meta( '_tip_amount', true );
        if ( ! empty( $existing_tip ) ) {
            return;
        }

        $tip_data = WOO_Order_Tip_Service::get_tip_data();

        if ( empty( $tip_data ) || ! isset( $tip_data['tip_amount'] ) || $tip_data['tip_amount'] <= 0 ) {
            $this->extract_tip_from_fees( $order );
            return;
        }

        $this->add_tip_meta_to_order( $order, $tip_data );
    }

    /**
     * Save tip to order meta for manually created admin orders
     * Extracts tip amount from order fees
     *
     * @param int $order_id The order ID
     */
    public function save_tip_to_order_meta_admin( $order_id ) {
        $order = wc_get_order( $order_id );

        if ( ! $order ) {
            return;
        }

        $this->extract_tip_from_fees( $order );
    }

    /**
     * HPOS compatibility - save tip meta when order is updated
     *
     * @param int $order_id The order ID
     */
    public function save_tip_to_order_meta_hpos( $order_id ) {
        $order = wc_get_order( $order_id );

        if ( ! $order ) {
            return;
        }

        $existing_tip = $order->get_meta( '_tip_amount', true );
        if ( ! empty( $existing_tip ) ) {
            return;
        }

        $this->extract_tip_from_fees( $order );
    }

    /**
     * Add tip meta data to order object
     * Saves in ShipDay-compatible format
     *
     * @param WC_Order $order    The order object
     * @param array    $tip_data The tip data array from session
     */
    private function add_tip_meta_to_order( $order, $tip_data ) {
        $tip_amount = floatval( $tip_data['tip_amount'] );

        if ( $tip_amount <= 0 ) {
            return;
        }

        $order->update_meta_data( '_tip_amount', $tip_amount );
        $order->update_meta_data( '_customer_tip', $tip_amount );

        if ( isset( $tip_data['tip_label'] ) ) {
            $order->update_meta_data( '_tip_label', sanitize_text_field( $tip_data['tip_label'] ) );
        }

        $wc_session = WC()->session;
        $tip_session = $wc_session ? $wc_session->get( 'tip' ) : array();

        if ( ! empty( $tip_session ) ) {
            if ( isset( $tip_session['tip_type'] ) ) {
                $order->update_meta_data( '_tip_type', sanitize_text_field( $tip_session['tip_type'] ) );
            }

            if ( isset( $tip_session['tip'] ) ) {
                $order->update_meta_data( '_tip_original_value', sanitize_text_field( $tip_session['tip'] ) );
            }

            if ( isset( $tip_session['tip_cash'] ) && $tip_session['tip_cash'] ) {
                $order->update_meta_data( '_tip_payment_method', 'cash' );
            } else {
                $order->update_meta_data( '_tip_payment_method', 'card' );
            }

            if ( isset( $tip_session['tip_custom'] ) && $tip_session['tip_custom'] ) {
                $order->update_meta_data( '_tip_is_custom', 'yes' );
            }
        }

        $order->update_meta_data( '_tip_added_date', current_time( 'mysql' ) );

        if ( method_exists( $order, 'save' ) ) {
            $order->save();
        }
    }

    /**
     * Extract tip amount from order fees
     * Fallback method when session data is not available
     *
     * @param WC_Order $order The order object
     * @return bool True if tip was found and saved, false otherwise
     */
    private function extract_tip_from_fees( $order ) {
        
        $tip_fee_name = get_option( 'wc_order_tip_fee_name', 'Tip' );

        if ( empty( $tip_fee_name ) ) {
            $tip_fee_name = 'Tip';
        }

        $fees = $order->get_fees();

        if ( empty( $fees ) ) {
            return false;
        }

        $tip_found = false;

        foreach ( $fees as $fee_id => $fee ) {
            $fee_name = $fee->get_name();

            if ( strpos( $fee_name, $tip_fee_name ) !== false ) {
                $tip_amount = floatval( $fee->get_total() );

                if ( $tip_amount > 0 ) {
                    $order->update_meta_data( '_tip_amount', $tip_amount );
                    $order->update_meta_data( '_customer_tip', $tip_amount );
                    $order->update_meta_data( '_tip_label', sanitize_text_field( $fee_name ) );
                    $order->update_meta_data( '_tip_fee_id', $fee_id );
                    $order->update_meta_data( '_tip_added_date', current_time( 'mysql' ) );

                    $fee_tax = floatval( $fee->get_total_tax() );
                    if ( $fee_tax > 0 ) {
                        $order->update_meta_data( '_tip_is_taxable', 'yes' );
                        $order->update_meta_data( '_tip_tax_amount', $fee_tax );
                    } else {
                        $order->update_meta_data( '_tip_is_taxable', 'no' );
                    }

                    $order->save();
                    $tip_found = true;

                    break;
                }
            }
        }

        return $tip_found;
    }

    /**
     * Get tip amount from order (helper method for external use)
     * Can be used by other plugins/themes to retrieve tip
     *
     * @param int|WC_Order $order Order ID or order object
     * @return float Tip amount or 0 if not found
     */
    public static function get_order_tip_amount( $order ) {
        if ( is_numeric( $order ) ) {
            $order = wc_get_order( $order );
        }

        if ( ! $order ) {
            return 0;
        }

        $tip_amount = $order->get_meta( '_tip_amount', true );

        if ( ! empty( $tip_amount ) && is_numeric( $tip_amount ) ) {
            return floatval( $tip_amount );
        }

        $tip_amount = $order->get_meta( '_customer_tip', true );

        if ( ! empty( $tip_amount ) && is_numeric( $tip_amount ) ) {
            return floatval( $tip_amount );
        }

        return 0;
    }

}
?>

<?php

defined( 'ABSPATH' ) || exit;

/**
 * Class WC_Montonio_Shipping_Item_Manager for handling Montonio Shipping V2 shipping method items
 * @since 7.0.0
 */
class WC_Montonio_Shipping_Item_Manager {
    /**
     * Sync shipping method items in the database
     *
     * @since 7.0.0
     * @param string $carrier Carrier code
     * @param string $country Country code (ISO 3166-1 alpha-2)
     * @param string $type Shipping method type
     * @return void
     */
    public static function sync_method_items( $carrier, $country, $type ) {
        global $wpdb;

        $montonio_shipping_api = new WC_Montonio_Shipping_API();

        if ( $type === 'courier' ) {
            $response = $montonio_shipping_api->get_courier_services( $carrier, $country );
            $type_key = 'courierServices';
        } else {
            $response = $montonio_shipping_api->get_pickup_points( $carrier, $country );
            $type_key = 'pickupPoints';
        }

        // Handle invalid or empty response
        if ( empty( $response ) ) {
            return;
        }

        $data = json_decode( $response, true );

        // Check if essential data is present in the data
        if ( empty( $data[$type_key] ) || empty( $data['countryCode'] ) ) {
            return;
        }

        $table_name = $wpdb->prefix . 'montonio_shipping_method_items';
        $country    = $data['countryCode'];

        // Start transaction for data integrity
        $wpdb->query( 'START TRANSACTION' );

        // Prepare a list of IDs for deletion query
        $shipping_item_ids = array_column( $data[$type_key], 'id' );

        // Delete pickup points not present in the updated list
        $placeholders = implode( ',', array_fill( 0, count( $shipping_item_ids ), '%s' ) );
        $sql          = "DELETE FROM $table_name WHERE item_id NOT IN ($placeholders) AND country_code = %s AND carrier_code = %s AND method_type = %s";
        $params       = array_merge( $shipping_item_ids, [$country, $carrier, $type] );

        $wpdb->query( $wpdb->prepare( $sql, $params ) );

        // Insert or update records
        foreach ( $data[$type_key] as $shipping_item_data ) {
            $mapped_data = array(
                'item_id'        => $shipping_item_data['id'] ?? null,
                'item_name'      => $shipping_item_data['name'] ?? null,
                'item_type'      => $shipping_item_data['type'] ?? null,
                'method_type'    => $type,
                'street_address' => $shipping_item_data['streetAddress'] ?? null,
                'locality'       => $shipping_item_data['locality'] ?? null,
                'postal_code'    => $shipping_item_data['postalCode'] ?? null,
                'carrier_code'   => $shipping_item_data['carrierCode'] ?? null,
                'country_code'   => $country
            );

            // Use REPLACE INTO for insert or update operation
            $wpdb->replace( $table_name, $mapped_data );
        }

        // Commit the transaction
        $wpdb->query( 'COMMIT' );
    }

    /**
     * Get single shipping method item by ID
     *
     * @since 7.0.0
     * @param int $id Shipping method item ID
     * @return array|object|null Database query results.
     */
    public static function get_shipping_method_item( $id ) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'montonio_shipping_method_items';
        $sql        = "SELECT * FROM $table_name WHERE item_id = %s";
        $params     = [$id];

        return $wpdb->get_results( $wpdb->prepare( $sql, $params ) );
    }

    /**
     * Get shipping method items by country, carrier and type
     *
     * @since 7.0.0
     * @param string $country Country code (ISO 3166-1 alpha-2)
     * @param string $carrier Carrier code
     * @param string $type Shipping method type (parcelShop, pickupPoint, postOffice)
     * @return array|object|null Database query results.
     */
    public static function get_shipping_method_items( $country, $carrier, $type ) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'montonio_shipping_method_items';
        $sql        = "SELECT * FROM $table_name WHERE country_code = %s AND carrier_code = %s AND item_type = %s";
        $params     = [$country, $carrier, $type];

        return $wpdb->get_results( $wpdb->prepare( $sql, $params ) );
    }

    /**
     * Get courier ID by country and carrier
     *
     * @since 7.0.0
     * @param string $country Country code (ISO 3166-1 alpha-2)
     * @param string $carrier Carrier code
     * @return int|null Database query result.
     */
    public static function get_courier_id( $country, $carrier ) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'montonio_shipping_method_items';
        $sql        = "SELECT DISTINCT item_id FROM $table_name WHERE country_code = %s AND carrier_code = %s AND item_type = 'courier'";
        $params     = [$country, $carrier];

        return $wpdb->get_var( $wpdb->prepare( $sql, $params ) );
    }

    /**
     * Get shipping method type available countries by carrier code and item type
     *
     * @since 7.0.0
     * @param string $carrier Carrier code
     * @param string $type Shipping method type (parcelShop, pickupPoint, postOffice, courier)
     * @return array List of country codes.
     */
    public static function get_shipping_method_countries( $carrier, $type ) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'montonio_shipping_method_items';
        $sql        = "SELECT DISTINCT country_code FROM $table_name WHERE carrier_code = %s AND item_type = %s";
        $params     = [$carrier, $type];

        $results = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );

        $country_codes = array_column( $results, 'country_code' );

        return $country_codes;
    }

    /**
     * Check if shipping method items exist
     *
     * @since 7.0.0
     * @param string $country Country code (ISO 3166-1 alpha-2)
     * @param string $carrier Carrier code
     * @param string $type Shipping method type (parcelShop, pickupPoint, postOffice, courier)
     * @return bool True if items exist, false otherwise.
     */
    public static function shipping_method_items_exist( $country, $carrier, $type ) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'montonio_shipping_method_items';
        $sql        = "SELECT 1 FROM $table_name WHERE country_code = %s AND carrier_code = %s AND item_type = %s LIMIT 1";
        $params     = [$country, $carrier, $type];

        $result = $wpdb->get_var( $wpdb->prepare( $sql, $params ) );

        return $result !== null;
    }

    /**
     * Fetch and group pickup points by locality.
     * 
     * @since 7.0.0
     * @param string $country Country code (ISO 3166-1 alpha-2)
     * @param string $carrier Carrier code
     * @param string $type Shipping method type (parcelShop, pickupPoint, postOffice)
     * @return array Grouped pickup points by locality
     */
    public static function fetch_and_group_pickup_points( $country, $carrier, $type ) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'montonio_shipping_method_items';
        $sql        = "SELECT * FROM $table_name WHERE country_code = %s AND carrier_code = %s AND item_type = %s ORDER BY item_name ASC";
        $params     = [$country, $carrier, $type];

        $results = $wpdb->get_results( $wpdb->prepare( $sql, $params ) );

        $grouped_localities = [];
        foreach ( $results as $pickup_point ) {
            $locality                        = $pickup_point->locality;
            $grouped_localities[$locality][] = [
                'id'      => $pickup_point->item_id,
                'name'    => $pickup_point->item_name,
                'type'    => $pickup_point->item_type,
                'address' => $pickup_point->street_address
            ];
        }

        // Optionally sort the locality arrays based on the number of pickup points
        uasort( $grouped_localities, function ( $a, $b ) {
            return count( $b ) - count( $a );
        } );

        return $grouped_localities;
    }
}
<?php
/**
 * Plugin Name: Give Stripe Extended
 * Plugin URI: https://rippleffect.tech
 * Description: Extention for GiveWp Stripe plugin
 * Version: 1.2.0
 * Author: Djamel Kadi - RipplEffect
 * Author URI: https://rippleffect.tech
 * Domain Path: /languages/
 * Requires at least: 5.0
 * Requires PHP: 7.0
 * WC requires at least: 4.0
 * WC tested up to: 5.8.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

define( 'WPS_GIVE_STRIPE_EXTENDED_VERSION', '1.0.0' );
define( 'WPS_GIVE_STRIPE_EXTENDED_FILE', __FILE__ );
define( 'WPS_GIVE_STRIPE_EXTENDED_SLUG', 'wps-give-stripe-extended' );
define( 'WPS_GIVE_STRIPE_EXTENDED_BASENAME', plugin_basename( __FILE__ ) );
define( 'WPS_GIVE_STRIPE_EXTENDED_URL', untrailingslashit( plugin_dir_url( __FILE__ ) ) );
define( 'WPS_GIVE_STRIPE_EXTENDED_DIR', untrailingslashit( plugin_dir_path( __FILE__ ) ) );

require_once WPS_GIVE_STRIPE_EXTENDED_DIR . '/functions.php';

class Give_Stripe_Extended {

	protected static $instance;

	public function __construct() {
		// add_action( 'wp_loaded', array( $this, 'maybe_debug' ), 20 );
		add_filter( 'give_get_option__give_stripe_default_account', array( $this, 'filter_give_stripe_default_account' ), 20, 3 );
		add_filter( 'give_get_option__give_stripe_get_all_accounts', array( $this, 'filter_give_stripe_get_all_accounts' ), 20, 3 );
		add_filter( 'give_stripe_create_checkout_session_args', array( $this, 'filter_checkout_session_args' ), 20, 1 );
		add_filter( 'give__create_charge_args', array( $this, 'filter_create_charge_args' ), 20, 1 );
		add_filter( 'give_stripe_create_intent_args', array( $this, 'filter_create_intent_args' ), 20, 1 );
		add_filter( 'give_stripe_sepa_create_charge_args', array( $this, 'filter_sepa_create_charge_args' ), 20, 1 );
		add_filter( 'give_stripe_register_groups', array( $this, 'filter_stripe_register_groups' ) );
		add_filter( 'give_stripe_add_after_general_fields', array( $this, 'filter_after_general_fields' ), 20, 1 );
		add_filter( 'give_stripe_get_application_name', array( $this, 'filter_application_name' ), 20, 1 );
		add_filter( 'give_stripe_customer_args', array( $this, 'filter_stripe_customer_args' ), 20, 1 );
		add_action( 'give_init', array( $this, 'give_init' ), 20 );
		add_action( 'give_update_payment_status', array( $this, 'give_stripe_process_refund' ), 100, 3 );
	}

	public function give_init() {
		remove_action( 'give_update_payment_status', 'give_stripe_process_refund', 200 );
	}

	public function give_stripe_process_refund( $donation_id, $new_status, $old_status ) {
		$stripe_opt_refund_value = ! empty( $_POST['give_stripe_opt_refund'] ) ? give_clean( $_POST['give_stripe_opt_refund'] ) : '';
		$can_process_refund      = ! empty( $stripe_opt_refund_value ) ? $stripe_opt_refund_value : false;

		// Only move forward if refund requested.
		if ( ! $can_process_refund ) {
			return;
		}

		// Verify statuses.
		$should_process_refund = 'publish' !== $old_status ? false : true;
		$should_process_refund = apply_filters( 'give_stripe_should_process_refund', $should_process_refund, $donation_id, $new_status, $old_status );

		if ( false === $should_process_refund ) {
			return;
		}

		if ( 'refunded' !== $new_status ) {
			return;
		}

		$charge_id = give_get_payment_transaction_id( $donation_id );

		// If no charge ID, look in the payment notes.
		if ( empty( $charge_id ) || $charge_id == $donation_id ) {
			$charge_id = give_stripe_get_payment_txn_id_fallback( $donation_id );
		}

		// Bail if no charge ID was found.
		if ( empty( $charge_id ) ) {
			return;
		}

		// Get Form ID.
		$form_id = give_get_payment_form_id( $donation_id );

		// Set App Info.
		give_stripe_set_app_info( $form_id );

		try {
			$args = array(
				'refund_application_fee' => false,
				'charge'                 => $charge_id,
			);

			// If the donation is processed with payment intent then refund using payment intent.
			if ( give_stripe_is_source_type( $charge_id, 'pi' ) ) {
				$args = array(
					'refund_application_fee' => false,
					'payment_intent'         => $charge_id,
				);
			}

			$refund = \Stripe\Refund::create( $args, array( 'stripe_account' => give_get_option( 'connected_account_id' ) ) );

			if ( isset( $refund->id ) ) {
				give_insert_payment_note(
					$donation_id,
					sprintf(
						/* translators: 1. Refund ID */
						esc_html__( 'Charge refunded in Stripe: %s', 'give' ),
						$refund->id
					)
				);
			}
		} catch ( \Stripe\Error\Base $e ) {
			// Refund issue occurred.
			$log_message  = __( 'The Stripe payment gateway returned an error while refunding a donation.', 'give' ) . '<br><br>';
			$log_message .= sprintf( esc_html__( 'Message: %s', 'give' ), $e->getMessage() ) . '<br><br>';
			$log_message .= sprintf( esc_html__( 'Code: %s', 'give' ), $e->getCode() );

			// Log it with DB.
			give_record_gateway_error( __( 'Stripe Error', 'give' ), $log_message );

		} catch ( Exception $e ) {

			// some sort of other error.
			$body = $e->getJsonBody();
			$err  = $body['error'];

			if ( isset( $err['message'] ) ) {
				$error = $err['message'];
			} else {
				$error = esc_html__( 'Something went wrong while refunding the charge in Stripe.', 'give' );
			}

			wp_die(
				$error,
				esc_html__( 'Error', 'give' ),
				array(
					'response' => 400,
				)
			);

		} // End try().

		do_action( 'give_stripe_donation_refunded', $donation_id );
	}

	public function filter_stripe_customer_args( $args ) {
		if ( isset( $args['name'] ) ) {
			$args['description'] = $args['name'];
		} else {
			unset( $args['description'] );
		}

		return $args;
	}

	public function filter_application_name( $application_name ) {
		global $current_screen;

		if ( is_admin() && $current_screen && isset( $current_screen->id ) && $current_screen->id === 'give_forms_page_give-settings' ) {
			return $application_name;
		}

		if ( 'give_stripe_add_manual_account' === sipost( 'action' ) ) {
			return $application_name;
		}

		if ( empty( give_get_option( 'connected_account_id' ) ) ) {
			return $application_name;
		}

		try {
			\Stripe\Stripe::setAccountId( give_get_option( 'connected_account_id' ) );
		} catch ( Exception $e ) {

			give_record_gateway_error(
				__( 'Stripe Error', 'give' ),
				sprintf(
					/* translators: %s Exception Error Message */
					__( 'Unable to set application information to Stripe. Details: %s', 'give' ),
					$e->getMessage()
				)
			);

			give_set_error( 'stripe_app_info_error', __( 'Unable to set application information to Stripe. Please try again.', 'give' ) );
		} // End try().

		return $application_name;
	}

	public function filter_after_general_fields( $settings ) {
		// Connected.
		$settings['metadata'][] = array(
			'id'   => 'give_title_stripe_metadata',
			'type' => 'title',
		);

		$settings['metadata'][] = array(
			'name'          => esc_html__( 'Application Name', 'give' ),
			'id'            => 'stripe_metadata_application_name',
			'wrapper_class' => 'stripe-metadata-field',
			'default'       => '',
			'type'          => 'text',
		);

		$settings['metadata'][] = array(
			'name'          => esc_html__( 'Campaign Name', 'give' ),
			'id'            => 'stripe_metadata_campaign_name',
			'wrapper_class' => 'stripe-metadata-field',
			'default'       => '',
			'type'          => 'text',
		);

		// Stripe Admin Settings - Footer.
		$settings['metadata'][] = array(
			'id'   => 'give_title_stripe_metadata',
			'type' => 'sectionend',
		);

		// Connected.
		$settings['connected'][] = array(
			'id'   => 'give_title_stripe_connected',
			'type' => 'title',
		);

		$settings['connected'][] = array(
			'name'          => esc_html__( 'Connected account id', 'give' ),
			'id'            => 'connected_account_id',
			'wrapper_class' => 'stripe-connected-field',
			'default'       => '',
			'type'          => 'text',
		);

		$settings['connected'][] = array(
			'name'          => esc_html__( 'Connected account publishable key', 'give' ),
			'id'            => 'connected_publishable_key',
			'wrapper_class' => 'stripe-connected-field',
			'default'       => '',
			'type'          => 'text',
		);

		$settings['connected'][] = array(
			'name'          => esc_html__( 'Connected account stripe secret key', 'give' ),
			'id'            => 'connected_secret_key',
			'wrapper_class' => 'stripe-connected-field',
			'default'       => '',
			'type'          => 'password',
		);

		$settings['connected'][] = array(
			'name'          => esc_html__( 'Application fee for Connected account (in %)', 'give' ),
			'id'            => 'application_fee',
			'wrapper_class' => 'stripe-connected-field',
			'default'       => '',
			'type'          => 'text',
		);

		// Stripe Admin Settings - Footer.
		$settings['connected'][] = array(
			'id'   => 'give_title_stripe_connected',
			'type' => 'sectionend',
		);

		return $settings;
	}

	public function filter_stripe_register_groups( $groups ) {
		$new_groups = array();
		foreach ( $groups as $id => $name ) {
			$new_groups[ $id ] = $name;
			if ( 'general' === $id ) {
				$new_groups['metadata']  = __( 'Stripe Metadata', 'give' );
				$new_groups['connected'] = __( 'Connected Account Settings', 'give' );
			}
		}

		return $new_groups;
	}

	public function filter_checkout_session_args( $args ) {
		if ( isset( $args['payment_intent_data'] ) ) {
			$args['payment_intent_data']['application_fee_amount'] = (int) $args['line_items'][0]['amount'] * ( (float) give_get_option( 'application_fee' ) / 100 );
			$stripe_donation_id                                    = '';
			$donation_form_title                                   = '';
			if ( isset( $args['payment_intent_data']['metadata']['Donation Post ID'] ) ) {
				$stripe_donation_id  = ' - Donation ID #' . $args['payment_intent_data']['metadata']['Donation Post ID'];
				$form_id             = give_get_payment_form_id( $args['payment_intent_data']['metadata']['Donation Post ID'] );
				$donation            = $form_id ? new Give_Donate_Form( $form_id ) : false;
				$donation_form_title = $donation ? ' - ' . $donation->post_title : '';
			}
			$args['payment_intent_data']['description']                  = give_get_option( 'stripe_metadata_application_name' ) . ' - ' . give_get_option( 'stripe_metadata_campaign_name' ) . $donation_form_title . $stripe_donation_id;
			$args['payment_intent_data']['metadata']['Application Name'] = give_get_option( 'stripe_metadata_application_name' );
			$args['payment_intent_data']['metadata']['Campaign Name']    = give_get_option( 'stripe_metadata_campaign_name' );

		// Fetch the Market Center Number from the donation metadata.
        $meta = give_get_meta( $args['metadata']['Donation Post ID'] );
        $market_center = isset( $meta["give_market_center"][0] ) ? $meta["give_market_center"][0] : '';
        $market_center_no = isset( $meta["give_market_center_no"][0] ) ? $meta["give_market_center_no"][0] : '';
        $market_center_region = isset( $meta["give_market_center_region"][0] ) ? $meta["give_market_center_region"][0] : '';

        // Add the Market Center Number to the metadata.
        if($market_center || $market_center_no || $market_center_region){
	        $args['metadata']['Market Center'] = $market_center;
	        $args['metadata']['Market Center Number'] = $market_center_no;
	        $args['metadata']['Market Region'] = $market_center_region;        	
        }


		// Fetch the UTM Data 
		$utm_source   = isset( $meta["give_source"][0] ) ? $meta["give_source"][0] : '';
		$utm_medium   = isset( $meta["give_medium"][0] ) ? $meta["give_medium"][0] : '';
		$utm_campaign = isset( $meta["give_campaign"][0] ) ? $meta["give_campaign"][0] : '';
		$utm_term     = isset( $meta["give_term"][0] ) ? $meta["give_term"][0] : '';
		$utm_content  = isset( $meta["give_content"][0] ) ? $meta["give_content"][0] : '';

		// Add the UTM to the metadata.
		if($utm_source || $utm_medium || $utm_campaign || $utm_term || $utm_content){
	        $args['metadata']['UTM Source'] = $utm_source;
	        $args['metadata']['UTM Medium'] = $utm_medium;
	        $args['metadata']['UTM Campaign'] = $utm_campaign;
	        $args['metadata']['UTM Term'] = $utm_term;
	        $args['metadata']['UTM Content'] = $utm_content;
    	}

		$customer_email = isset( $meta['_give_payment_donor_email'][0] ) ? $meta['_give_payment_donor_email'][0] : ''; 
		$first_name = isset( $meta['_give_donor_billing_first_name'][0] ) ? $meta['_give_donor_billing_first_name'][0] : ''; 
		$last_name = isset( $meta['_give_donor_billing_last_name'][0] ) ? $meta['_give_donor_billing_last_name'][0] : ''; 
		$give_address1 = isset( $meta['give_address1'][0] ) ? $meta['give_address1'][0] : ''; 
		$give_address2 = isset( $meta['give_address2'][0] ) ? $meta['give_address2'][0] : ''; 

		if($give_address1 || $give_address2){
			$give_street = $give_address1.' '.$give_address2;
		}else{
			$give_street = isset( $meta['give_street'][0] ) ? $meta['give_street'][0] : ''; 
		}


		$give_city = isset( $meta['give_city'][0] ) ? $meta['give_city'][0] : ''; 
		$give_zipcode = isset( $meta['give_zipcode'][0] ) ? $meta['give_zipcode'][0] : '';
		$give_phone = isset( $meta['give_phone'][0] ) ? $meta['give_phone'][0] : '';

		$give_state = isset( $meta['give_state'][0] ) ? $meta['give_state'][0] : ''; 
		if(empty($give_state)){
			$give_state = isset( $meta['state'][0] ) ? $meta['state'][0] : ''; // since KC has input field name state instead of givewp
		}

		$give_countries = isset( $meta['give_countries'][0] ) ? $meta['give_countries'][0] : '';
		$give_receive_email = isset( $meta['give_receive_email'][0] ) ? $meta['give_receive_email'][0] : '';
		$donation_type = isset( $meta['rp_give_donation_type'][0] ) ? $meta['rp_give_donation_type'][0] : '';
		$rp_recurring = isset( $meta['rp_recurring'][0] ) ? $meta['rp_recurring'][0] : '';
		$form_title = isset( $meta['_give_payment_form_title'][0] ) ? $meta['_give_payment_form_title'][0] : '';

        $args['metadata']['First Name'] = $first_name;
        $args['metadata']['Last Name'] = $last_name;
        $args['metadata']['Phone'] =   $give_phone;
        $args['metadata']['Email'] =   $customer_email;

        $args['metadata']['Address'] = $give_street;
        $args['metadata']['Country'] = $give_countries;
        $args['metadata']['State']   = $give_state;
        $args['metadata']['City']    = $give_city;
        $args['metadata']['Zipcode'] = $give_zipcode;
        $args['metadata']['Donation type'] = $donation_type;
        $args['metadata']['Email Opt in'] = $give_receive_email;
        $args['metadata']['Recurring'] = $rp_recurring;
		$args['metadata']['Campaign'] = $form_title; 

		}
		return $args;
	}

	public function filter_create_charge_args( $charge_args ) {
		$charge_args['application_fee_amount'] = (int) ( $charge_args['amount'] * ( (float) give_get_option( 'application_fee' ) / 100 ) );
		$stripe_donation_id                    = '';
		$donation_form_title                   = '';
		if ( isset( $args['metadata']['Donation Post ID'] ) ) {
			$stripe_donation_id  = ' - Donation ID #' . $args['metadata']['Donation Post ID'];
			$form_id             = give_get_payment_form_id( $args['metadata']['Donation Post ID'] );
			$donation            = $form_id ? new Give_Donate_Form( $form_id ) : false;
			$donation_form_title = $donation ? ' - ' . $donation->post_title : '';
		}
		$charge_args['description']                  = give_get_option( 'stripe_metadata_application_name' ) . ' - ' . give_get_option( 'stripe_metadata_campaign_name' ) . $donation_form_title . $stripe_donation_id;
		$charge_args['metadata']['Application Name'] = give_get_option( 'stripe_metadata_application_name' );
		$charge_args['metadata']['Campaign Name']    = give_get_option( 'stripe_metadata_campaign_name' );

		// Fetch the Market Center Number from the donation metadata.
        $meta = give_get_meta( $args['metadata']['Donation Post ID'] );
        $market_center = isset( $meta["give_market_center"][0] ) ? $meta["give_market_center"][0] : '';
        $market_center_no = isset( $meta["give_market_center_no"][0] ) ? $meta["give_market_center_no"][0] : '';
        $market_center_region = isset( $meta["give_market_center_region"][0] ) ? $meta["give_market_center_region"][0] : '';

        // Add the Market Center Number to the metadata.
        if($market_center || $market_center_no || $market_center_region){
	        $charge_args['metadata']['Market Center'] = $market_center;
	        $charge_args['metadata']['Market Center Number'] = $market_center_no;
	        $charge_args['metadata']['Market Region'] = $market_center_region;        	
        }


		// Fetch the UTM Data 
		$utm_source   = isset( $meta["give_source"][0] ) ? $meta["give_source"][0] : '';
		$utm_medium   = isset( $meta["give_medium"][0] ) ? $meta["give_medium"][0] : '';
		$utm_campaign = isset( $meta["give_campaign"][0] ) ? $meta["give_campaign"][0] : '';
		$utm_term     = isset( $meta["give_term"][0] ) ? $meta["give_term"][0] : '';
		$utm_content  = isset( $meta["give_content"][0] ) ? $meta["give_content"][0] : '';

		// Add the UTM to the metadata.
		if($utm_source || $utm_medium || $utm_campaign || $utm_term || $utm_content){
	        $charge_args['metadata']['UTM Source'] = $utm_source;
	        $charge_args['metadata']['UTM Medium'] = $utm_medium;
	        $charge_args['metadata']['UTM Campaign'] = $utm_campaign;
	        $charge_args['metadata']['UTM Term'] = $utm_term;
	        $charge_args['metadata']['UTM Content'] = $utm_content;
    	}    

		$customer_email = isset( $meta['_give_payment_donor_email'][0] ) ? $meta['_give_payment_donor_email'][0] : ''; 
		$first_name = isset( $meta['_give_donor_billing_first_name'][0] ) ? $meta['_give_donor_billing_first_name'][0] : ''; 
		$last_name = isset( $meta['_give_donor_billing_last_name'][0] ) ? $meta['_give_donor_billing_last_name'][0] : ''; 
		$give_address1 = isset( $meta['give_address1'][0] ) ? $meta['give_address1'][0] : ''; 
		$give_address2 = isset( $meta['give_address2'][0] ) ? $meta['give_address2'][0] : ''; 

		if($give_address1 || $give_address2){
			$give_street = $give_address1.' '.$give_address2;
		}else{
			$give_street = isset( $meta['give_street'][0] ) ? $meta['give_street'][0] : ''; 
		}


		$give_city = isset( $meta['give_city'][0] ) ? $meta['give_city'][0] : ''; 
		$give_zipcode = isset( $meta['give_zipcode'][0] ) ? $meta['give_zipcode'][0] : '';
		$give_phone = isset( $meta['give_phone'][0] ) ? $meta['give_phone'][0] : '';

		$give_state = isset( $meta['give_state'][0] ) ? $meta['give_state'][0] : ''; 
		if(empty($give_state)){
			$give_state = isset( $meta['state'][0] ) ? $meta['state'][0] : ''; // since KC has input field name state instead of givewp
		}

		$give_countries = isset( $meta['give_countries'][0] ) ? $meta['give_countries'][0] : '';
		$give_receive_email = isset( $meta['give_receive_email'][0] ) ? $meta['give_receive_email'][0] : '';
		$donation_type = isset( $meta['rp_give_donation_type'][0] ) ? $meta['rp_give_donation_type'][0] : '';
		$rp_recurring = isset( $meta['rp_recurring'][0] ) ? $meta['rp_recurring'][0] : '';
		$form_title = isset( $meta['_give_payment_form_title'][0] ) ? $meta['_give_payment_form_title'][0] : '';

        $charge_args['metadata']['First Name'] = $first_name;
        $charge_args['metadata']['Last Name'] = $last_name;
        $charge_args['metadata']['Phone'] =   $give_phone;
        $charge_args['metadata']['Email'] =   $customer_email;

        $charge_args['metadata']['Address'] = $give_street;
        $charge_args['metadata']['Country'] = $give_countries;
        $charge_args['metadata']['State']   = $give_state;
        $charge_args['metadata']['City']    = $give_city;
        $charge_args['metadata']['Zipcode'] = $give_zipcode;
        $charge_args['metadata']['Donation type'] = $donation_type;
        $charge_args['metadata']['Email Opt in'] = $give_receive_email;
        $charge_args['metadata']['Recurring'] = $rp_recurring;
		$charge_args['metadata']['Campaign'] = $form_title;   	 

		return $charge_args;
	}

	public function filter_create_intent_args( $args ) {
		$args['application_fee_amount'] = (int) ( $args['amount'] * ( (float) give_get_option( 'application_fee' ) / 100 ) );
		$stripe_donation_id             = '';
		$donation_form_title            = '';
		if ( isset( $args['metadata']['Donation Post ID'] ) ) {
			$stripe_donation_id  = ' - Donation ID #' . $args['metadata']['Donation Post ID'];
			$form_id             = give_get_payment_form_id( $args['metadata']['Donation Post ID'] );
			$donation            = $form_id ? new Give_Donate_Form( $form_id ) : false;
			$donation_form_title = $donation ? ' - ' . $donation->post_title : '';
		}
		$args['description']                  = give_get_option( 'stripe_metadata_application_name' ) . ' - ' . give_get_option( 'stripe_metadata_campaign_name' ) . $donation_form_title . $stripe_donation_id;
		$args['metadata']['Application Name'] = give_get_option( 'stripe_metadata_application_name' );
		$args['metadata']['Campaign Name']    = give_get_option( 'stripe_metadata_campaign_name' );

		// Fetch the Market Center Number from the donation metadata.
        $meta = give_get_meta( $args['metadata']['Donation Post ID'] );
        $market_center = isset( $meta["give_market_center"][0] ) ? $meta["give_market_center"][0] : '';
        $market_center_no = isset( $meta["give_market_center_no"][0] ) ? $meta["give_market_center_no"][0] : '';
        $market_center_region = isset( $meta["give_market_center_region"][0] ) ? $meta["give_market_center_region"][0] : '';

        // Add the Market Center Number to the metadata.
        if($market_center || $market_center_no || $market_center_region){
	        $args['metadata']['Market Center'] = $market_center;
	        $args['metadata']['Market Center Number'] = $market_center_no;
	        $args['metadata']['Market Region'] = $market_center_region;        	
        }


		// Fetch the UTM Data 
		$utm_source   = isset( $meta["give_source"][0] ) ? $meta["give_source"][0] : '';
		$utm_medium   = isset( $meta["give_medium"][0] ) ? $meta["give_medium"][0] : '';
		$utm_campaign = isset( $meta["give_campaign"][0] ) ? $meta["give_campaign"][0] : '';
		$utm_term     = isset( $meta["give_term"][0] ) ? $meta["give_term"][0] : '';
		$utm_content  = isset( $meta["give_content"][0] ) ? $meta["give_content"][0] : '';

		// Add the UTM to the metadata.
		if($utm_source || $utm_medium || $utm_campaign || $utm_term || $utm_content){
	        $args['metadata']['UTM Source'] = $utm_source;
	        $args['metadata']['UTM Medium'] = $utm_medium;
	        $args['metadata']['UTM Campaign'] = $utm_campaign;
	        $args['metadata']['UTM Term'] = $utm_term;
	        $args['metadata']['UTM Content'] = $utm_content;
    	}
		$customer_email = isset( $meta['_give_payment_donor_email'][0] ) ? $meta['_give_payment_donor_email'][0] : ''; 
		$first_name = isset( $meta['_give_donor_billing_first_name'][0] ) ? $meta['_give_donor_billing_first_name'][0] : ''; 
		$last_name = isset( $meta['_give_donor_billing_last_name'][0] ) ? $meta['_give_donor_billing_last_name'][0] : ''; 
		$give_address1 = isset( $meta['give_address1'][0] ) ? $meta['give_address1'][0] : ''; 
		$give_address2 = isset( $meta['give_address2'][0] ) ? $meta['give_address2'][0] : ''; 

		if($give_address1 || $give_address2){
			$give_street = $give_address1.' '.$give_address2;
		}else{
			$give_street = isset( $meta['give_street'][0] ) ? $meta['give_street'][0] : ''; 
		}


		$give_city = isset( $meta['give_city'][0] ) ? $meta['give_city'][0] : ''; 
		$give_zipcode = isset( $meta['give_zipcode'][0] ) ? $meta['give_zipcode'][0] : '';
		$give_phone = isset( $meta['give_phone'][0] ) ? $meta['give_phone'][0] : '';

		$give_state = isset( $meta['give_state'][0] ) ? $meta['give_state'][0] : ''; 
		if(empty($give_state)){
			$give_state = isset( $meta['state'][0] ) ? $meta['state'][0] : ''; // since KC has input field name state instead of givewp
		}

		$give_countries = isset( $meta['give_countries'][0] ) ? $meta['give_countries'][0] : '';
		$give_receive_email = isset( $meta['give_receive_email'][0] ) ? $meta['give_receive_email'][0] : '';
		$donation_type = isset( $meta['rp_give_donation_type'][0] ) ? $meta['rp_give_donation_type'][0] : '';
		$rp_recurring = isset( $meta['rp_recurring'][0] ) ? $meta['rp_recurring'][0] : '';
		$form_title = isset( $meta['_give_payment_form_title'][0] ) ? $meta['_give_payment_form_title'][0] : '';

        $args['metadata']['First Name'] = $first_name;
        $args['metadata']['Last Name'] = $last_name;
        $args['metadata']['Phone'] =   $give_phone;
        $args['metadata']['Email'] =   $customer_email;

        $args['metadata']['Address'] = $give_street;
        $args['metadata']['Country'] = $give_countries;
        $args['metadata']['State']   = $give_state;
        $args['metadata']['City']    = $give_city;
        $args['metadata']['Zipcode'] = $give_zipcode;
        $args['metadata']['Donation type'] = $donation_type;
        $args['metadata']['Email Opt in'] = $give_receive_email;
        $args['metadata']['Recurring'] = $rp_recurring;
		$args['metadata']['Campaign'] = $form_title; 

		return $args;
	}

	public function filter_sepa_create_charge_args( $args ) {
		$args['application_fee_amount'] = (int) ( $args['amount'] * ( (float) give_get_option( 'application_fee' ) / 100 ) );
		$stripe_donation_id             = '';
		$donation_form_title            = '';
		if ( isset( $args['metadata']['Donation Post ID'] ) ) {
			$stripe_donation_id  = ' - Donation ID #' . $args['metadata']['Donation Post ID'];
			$form_id             = give_get_payment_form_id( $args['metadata']['Donation Post ID'] );
			$donation            = $form_id ? new Give_Donate_Form( $form_id ) : false;
			$donation_form_title = $donation ? ' - ' . $donation->post_title : '';
		}
		$args['description']                  = give_get_option( 'stripe_metadata_application_name' ) . ' - ' . give_get_option( 'stripe_metadata_campaign_name' ) . $donation_form_title . $stripe_donation_id;
		$args['metadata']['Application Name'] = give_get_option( 'stripe_metadata_application_name' );
		$args['metadata']['Campaign Name']    = give_get_option( 'stripe_metadata_campaign_name' );

		// Fetch the Market Center Number from the donation metadata.
        $meta = give_get_meta( $args['metadata']['Donation Post ID'] );
        $market_center = isset( $meta["give_market_center"][0] ) ? $meta["give_market_center"][0] : '';
        $market_center_no = isset( $meta["give_market_center_no"][0] ) ? $meta["give_market_center_no"][0] : '';
        $market_center_region = isset( $meta["give_market_center_region"][0] ) ? $meta["give_market_center_region"][0] : '';

        // Add the Market Center Number to the metadata.
        if($market_center || $market_center_no || $market_center_region){
	        $args['metadata']['Market Center'] = $market_center;
	        $args['metadata']['Market Center Number'] = $market_center_no;
	        $args['metadata']['Market Region'] = $market_center_region;        	
        }


		// Fetch the UTM Data 
		$utm_source   = isset( $meta["give_source"][0] ) ? $meta["give_source"][0] : '';
		$utm_medium   = isset( $meta["give_medium"][0] ) ? $meta["give_medium"][0] : '';
		$utm_campaign = isset( $meta["give_campaign"][0] ) ? $meta["give_campaign"][0] : '';
		$utm_term     = isset( $meta["give_term"][0] ) ? $meta["give_term"][0] : '';
		$utm_content  = isset( $meta["give_content"][0] ) ? $meta["give_content"][0] : '';

		// Add the UTM to the metadata.
		if($utm_source || $utm_medium || $utm_campaign || $utm_term || $utm_content){
	        $args['metadata']['UTM Source'] = $utm_source;
	        $args['metadata']['UTM Medium'] = $utm_medium;
	        $args['metadata']['UTM Campaign'] = $utm_campaign;
	        $args['metadata']['UTM Term'] = $utm_term;
	        $args['metadata']['UTM Content'] = $utm_content;
    	}
		$customer_email = isset( $meta['_give_payment_donor_email'][0] ) ? $meta['_give_payment_donor_email'][0] : ''; 
		$first_name = isset( $meta['_give_donor_billing_first_name'][0] ) ? $meta['_give_donor_billing_first_name'][0] : ''; 
		$last_name = isset( $meta['_give_donor_billing_last_name'][0] ) ? $meta['_give_donor_billing_last_name'][0] : ''; 
		$give_address1 = isset( $meta['give_address1'][0] ) ? $meta['give_address1'][0] : ''; 
		$give_address2 = isset( $meta['give_address2'][0] ) ? $meta['give_address2'][0] : ''; 

		if($give_address1 || $give_address2){
			$give_street = $give_address1.' '.$give_address2;
		}else{
			$give_street = isset( $meta['give_street'][0] ) ? $meta['give_street'][0] : ''; 
		}


		$give_city = isset( $meta['give_city'][0] ) ? $meta['give_city'][0] : ''; 
		$give_zipcode = isset( $meta['give_zipcode'][0] ) ? $meta['give_zipcode'][0] : '';
		$give_phone = isset( $meta['give_phone'][0] ) ? $meta['give_phone'][0] : '';

		$give_state = isset( $meta['give_state'][0] ) ? $meta['give_state'][0] : ''; 
		if(empty($give_state)){
			$give_state = isset( $meta['state'][0] ) ? $meta['state'][0] : ''; // since KC has input field name state instead of givewp
		}

		$give_countries = isset( $meta['give_countries'][0] ) ? $meta['give_countries'][0] : '';
		$give_receive_email = isset( $meta['give_receive_email'][0] ) ? $meta['give_receive_email'][0] : '';
		$donation_type = isset( $meta['rp_give_donation_type'][0] ) ? $meta['rp_give_donation_type'][0] : '';
		$rp_recurring = isset( $meta['rp_recurring'][0] ) ? $meta['rp_recurring'][0] : '';
		$form_title = isset( $meta['_give_payment_form_title'][0] ) ? $meta['_give_payment_form_title'][0] : '';

        $args['metadata']['First Name'] = $first_name;
        $args['metadata']['Last Name'] = $last_name;
        $args['metadata']['Phone'] =   $give_phone;
        $args['metadata']['Email'] =   $customer_email;

        $args['metadata']['Address'] = $give_street;
        $args['metadata']['Country'] = $give_countries;
        $args['metadata']['State']   = $give_state;
        $args['metadata']['City']    = $give_city;
        $args['metadata']['Zipcode'] = $give_zipcode;
        $args['metadata']['Donation type'] = $donation_type;
        $args['metadata']['Email Opt in'] = $give_receive_email;
        $args['metadata']['Recurring'] = $rp_recurring;
		$args['metadata']['Campaign'] = $form_title; 
		return $args;
	}

	public function filter_give_stripe_get_all_accounts( $value, $key = null, $default = null ) {
		global $current_screen;
		if ( is_admin() && $current_screen && isset( $current_screen->id ) && $current_screen->id === 'give_forms_page_give-settings' ) {
			if ( is_array( $value ) && $value ) {
				foreach ( $value as $account_slug => $account_info ) {
					if ( ! isset( $account_info['account_slug'] ) ) {
						unset( $value[ $account_slug ] );
					}
				}

				unset( $value[ give_get_option( 'connected_account_id' ) ] );
			}

			return $value;
		}

		if ( is_admin() && ! wp_doing_ajax() ) {
			if ( is_array( $value ) && $value ) {
				foreach ( $value as $account_slug => $account_info ) {
					if ( ! isset( $account_info['account_slug'] ) ) {
						unset( $value[ $account_slug ] );
					}
				}
				unset( $value[ give_get_option( 'connected_account_id' ) ] );
			}

			return $value;
		}

		if ( is_array( $value ) && $value ) {
			unset( $value[ give_get_option( 'connected_account_id' ) ] );
			$stripe_statement_descriptor = '';
			foreach ( $value as $account_slug => $account_info ) {
				if ( ! isset( $account_info['account_slug'] ) ) {
					continue;
				}

				unset( $value[ $value[ $account_slug ]['account_id'] ] );
				$value[ $account_slug ]['account_id']           = give_get_option( 'connected_account_id' );
				$value[ $account_slug ]['live_publishable_key'] = give_get_option( 'connected_publishable_key' );
				$value[ $account_slug ]['test_publishable_key'] = give_get_option( 'connected_publishable_key' );
				$stripe_statement_descriptor                    = $value[ $account_slug ]['statement_descriptor'];
			}

			$value[ give_get_option( 'connected_account_id' ) ] = array(
				'type'                 => 'manual',
				'account_id'           => give_get_option( 'connected_account_id' ),
				'account_slug'         => give_get_option( 'connected_account_id' ),
				'account_name'         => give_get_option( 'stripe_statement_descriptor', get_bloginfo( 'name' ) ),
				'account_country'      => '',
				'account_email'        => '',
				'live_secret_key'      => give_get_option( 'connected_secret_key' ),
				'test_secret_key'      => give_get_option( 'connected_secret_key' ),
				'live_publishable_key' => give_get_option( 'connected_publishable_key' ),
				'test_publishable_key' => give_get_option( 'connected_publishable_key' ),
				'statement_descriptor' => $stripe_statement_descriptor ? $stripe_statement_descriptor : give_get_option( 'stripe_statement_descriptor', get_bloginfo( 'name' ) ),
			);
		}

		return $value;
	}

	public function filter_give_stripe_default_account( $value, $key = null, $default = null ) {
		if ( is_admin() && ! wp_doing_ajax() ) {
			return $value;
		}

		$all_accounts = give_stripe_get_all_accounts();

		if ( ! isset( $all_accounts[ $value ] ) && $all_accounts ) {
			return array_key_first( $all_accounts );
		}

		return $value;
	}

	public function maybe_debug() {
		if ( ! isset( $_GET['_give_stripe'] ) ) {
			return;
		}

		die();
	}

	public function get_log_dir( string $handle ) {
		$upload_dir = wp_upload_dir();
		$log_dir    = $upload_dir['basedir'] . '/' . $handle . '-logs';
		wp_mkdir_p( $log_dir );
		return $log_dir;
	}

	public function get_log_file_name( string $handle ) {
		if ( function_exists( 'wp_hash' ) ) {
			$date_suffix = date( 'Y-m-d', time() );
			$hash_suffix = wp_hash( $handle );
			return $this->get_log_dir( $handle ) . '/' . sanitize_file_name( implode( '-', array( $handle, $date_suffix, $hash_suffix ) ) . '.log' );
		}

		return $this->get_log_dir( $handle ) . '/' . $handle . '-' . date( 'Y-m-d', time() ) . '.log';
	}

	public function log( $message ) {
		if ( function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->debug( print_r( $message, true ), array( 'source' => WPS_GIVE_STRIPE_EXTENDED_SLUG ) );
		} else {
			error_log( date( '[Y-m-d H:i:s e] ' ) . print_r( $message, true ) . PHP_EOL, 3, $this->get_log_file_name( WPS_GIVE_STRIPE_EXTENDED_SLUG ) );
		}
	}

	public static function get_instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}

		return self::$instance;
	}
}

function Give_Stripe_Extended() {
	return Give_Stripe_Extended::get_instance();
}

$GLOBALS[ WPS_GIVE_STRIPE_EXTENDED_SLUG ] = Give_Stripe_Extended();
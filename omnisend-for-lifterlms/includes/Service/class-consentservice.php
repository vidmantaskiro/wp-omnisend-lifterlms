<?php
/**
 * Omnisend Consent service
 *
 * @package OmnisendLifterLMSPlugin
 */

declare(strict_types=1);

namespace Omnisend\LifterLMSAddon\Service;

use LLMS_Order;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ConsentService
 *
 * @package Omnisend\OmnisendLifterLMSPlugin\Service
 */
class ConsentService {
	public function __construct() {
		$options = get_option( 'omnisend_lifterlms_options' );

		if ( isset( $options['filter_lms_consent_setting'] ) ) {
			add_filter( 'llms_get_form_html', array( $this, 'add_consent_llms_form_fields' ), 10, 2 );
		}

		add_action( 'lifterlms_user_registered', array( $this, 'omnisend_save_register_fields' ) );
		add_action( 'llms_before_user_account_update_submit', array( $this, 'omnisend_update_register_fields' ) );

		add_action( 'llms_user_enrolled_in_course', array( $this, 'omnisend_add_enrollment_data' ), 10, 2 );
		add_action( 'llms_user_removed_from_course', array( $this, 'user_removed_from_course' ), 10, 2 );

		add_action( 'llms_user_added_to_membership_level', array( $this, 'add_omnisend_memberships_data' ), 10, 2 );
		add_action( 'llms_user_removed_from_membership', array( $this, 'user_removed_from_membership' ), 10, 2 );

		add_action( 'lifterlms_new_pending_order', array( $this, 'omnisend_update_consent_by_order' ), 10, 1 );
	}

	/**
	 * Adds consent fields to checkout
	 *
	 * @param string $html
	 * @param string $location
	 *
	 * @return void
	 */
	public function add_consent_llms_form_fields( string $html, string $location ): string {
		$omnisend_api = new OmnisendApiService();

		try {
			$contract_data = $omnisend_api->get_omnisend_contact_consent();
		} catch ( Throwable $ex ) {
			$contract_data = array(
				'email' => '',
				'sms'   => '',
			);
		}

		$email_consent = '';

		if ( $contract_data['email'] == 'subscribed' ) {
			$email_consent = 'checked';
		}

		$sms_consent = '';

		if ( $contract_data['sms'] == 'subscribed' ) {
			$sms_consent = 'checked';
		}

		$custom_fields_email = '<div class="llms-form-field type-checkbox llms-cols-12 llms-cols-last">
			<label for="llmsconsentEmail">' . esc_html( __( 'Subscribe me to your mailing lists', 'omnisend-lifter_lms' ) ) . '</label>
			<input id="llmsconsentEmail" name="llmsconsentEmail" ' . esc_html( $email_consent ) . ' type="checkbox" class="input" value="1">
		</div>';
		$custom_fields_phone = '<div class="llms-form-field type-checkbox llms-cols-12 llms-cols-last">
			<label for="llmsconsentPhone">' . esc_html( __( 'Subscribe me to your SMS lists', 'omnisend-lifter_lms' ) ) . '</label>
			<input id="llmsconsentPhone" name="llmsconsentPhone" ' . esc_html( $sms_consent ) . ' type="checkbox" class="input" value="1">
		</div>';

		if ( $location !== 'checkout' ) {
			return $html . $custom_fields_email . $custom_fields_phone;
		}

		if ( $email_consent !== 'checked' ) {
			$html .= $custom_fields_email;
		}

		if ( $sms_consent !== 'checked' ) {
			$html .= $custom_fields_phone;
		}

		return $html;
	}


	/**
	 * Custom function to trigger when a user is removed from a course.
	 *
	 * @param int $user_id  The ID of the user being removed.
	 * @param int $course_id The ID of the course from which the user is being removed.
	 *
	 * @return void
	 */
	public function user_removed_from_course( int $user_id, int $course_id ): void {
		$user_info  = get_userdata( $user_id );
		$user_email = $user_info->data->user_email;

		$omnisend_api = new OmnisendApiService();
		$omnisend_api->update_omnisend_enrolment_data( $user_email, $course_id, 'remove' );
	}

	/**
	 * Custom function to trigger when a user is removed from a membership.
	 *
	 * @param int $user_id  The ID of the user being removed.
	 * @param int $course_id The ID of the course from which the user is being removed.
	 *
	 * @return void
	 */
	public function user_removed_from_membership( int $user_id, int $course_id ): void {
		$user_info  = get_userdata( $user_id );
		$user_email = $user_info->data->user_email;

		$omnisend_api = new OmnisendApiService();
		$omnisend_api->update_omnisend_memberships_data( $user_email, $course_id, 'remove' );
	}

	/**
	 * Custom function to trigger when a user is added to membership.
	 *
	 * @param int $user_id  The ID of the user being removed.
	 * @param int $membership_id The ID of the course from which the user is being removed.
	 *
	 * @return void
	 */
	public function add_omnisend_memberships_data( int $user_id, int $membership_id ): void {
		$user_info  = get_userdata( $user_id );
		$user_email = $user_info->data->user_email;

		$omnisend_api = new OmnisendApiService();
		$omnisend_api->update_omnisend_memberships_data( $user_email, $membership_id, 'add' );
	}

	/**
	 * phpcs:disable WordPress.Security.NonceVerification.Missing
	 */

	/**
	 * Custom function to trigger when a user just registered for first time.
	 *
	 * @param int $user_id  The ID of the user being removed.
	 *
	 * @return void
	 */
	public function omnisend_save_register_fields( int $user_id ): void {
		if ( ! isset( $user_id ) ) {
			return;
		}

		if ( isset( $_POST['_llms_checkout_nonce'] ) || isset( $_POST['_llms_register_person_nonce'] ) ) {
			$register_fields                           = array();
			$register_fields['email_address']          = sanitize_email( wp_unslash( $_POST['email_address'] ?? '' ) );
			$register_fields['llms_phone']             = sanitize_text_field( wp_unslash( $_POST['llms_phone'] ?? '' ) );
			$register_fields['first_name']             = sanitize_text_field( wp_unslash( $_POST['first_name'] ?? '' ) );
			$register_fields['last_name']              = sanitize_text_field( wp_unslash( $_POST['last_name'] ?? '' ) );
			$register_fields['llms_billing_zip']       = sanitize_text_field( wp_unslash( $_POST['llms_billing_zip'] ?? '' ) );
			$register_fields['llms_billing_address_1'] = sanitize_text_field( wp_unslash( $_POST['llms_billing_address_1'] ?? '' ) );
			$register_fields['llms_billing_address_2'] = sanitize_text_field( wp_unslash( $_POST['llms_billing_address_2'] ?? '' ) );
			$register_fields['llms_billing_state']     = sanitize_text_field( wp_unslash( $_POST['llms_billing_state'] ?? '' ) );
			$register_fields['llms_billing_country']   = sanitize_text_field( wp_unslash( $_POST['llms_billing_country'] ?? '' ) );
			$register_fields['llms_billing_city']      = sanitize_text_field( wp_unslash( $_POST['llms_billing_city'] ?? '' ) );

			if ( isset( $_POST['llmsconsentEmail'] ) ) {
				$register_fields['llmsconsentEmail'] = sanitize_text_field( wp_unslash( $_POST['llmsconsentEmail'] ) );
			}

			if ( isset( $_POST['llmsconsentPhone'] ) ) {
				$register_fields['llmsconsentPhone'] = sanitize_text_field( wp_unslash( $_POST['llmsconsentPhone'] ) );
			}

			$omnisend_api = new OmnisendApiService();
			$omnisend_api->create_omnisend_contact( $register_fields );
		}
	}

	/**
	 * Custom function to trigger when a user editing his profile.
	 *
	 * @param int $user_id The ID of the user profile.
	 *
	 * @return void
	 */
	public function omnisend_update_register_fields( int $user_id ): void {
		if ( ! isset( $user_id ) || ! isset( $_POST['_llms_update_person_nonce'] ) ) {
			return;
		}

		$update_register_fields                           = array();
		$update_register_fields['email_address']          = sanitize_email( wp_unslash( $_POST['email_address'] ?? '' ) );
		$update_register_fields['llms_phone']             = sanitize_text_field( wp_unslash( $_POST['llms_phone'] ?? '' ) );
		$update_register_fields['first_name']             = sanitize_text_field( wp_unslash( $_POST['first_name'] ?? '' ) );
		$update_register_fields['last_name']              = sanitize_text_field( wp_unslash( $_POST['last_name'] ?? '' ) );
		$update_register_fields['llms_billing_zip']       = sanitize_text_field( wp_unslash( $_POST['llms_billing_zip'] ?? '' ) );
		$update_register_fields['llms_billing_address_1'] = sanitize_text_field( wp_unslash( $_POST['llms_billing_address_1'] ?? '' ) );
		$update_register_fields['llms_billing_address_2'] = sanitize_text_field( wp_unslash( $_POST['llms_billing_address_2'] ?? '' ) );
		$update_register_fields['llms_billing_state']     = sanitize_text_field( wp_unslash( $_POST['llms_billing_state'] ?? '' ) );
		$update_register_fields['llms_billing_country']   = sanitize_text_field( wp_unslash( $_POST['llms_billing_country'] ?? '' ) );
		$update_register_fields['llms_billing_city']      = sanitize_text_field( wp_unslash( $_POST['llms_billing_city'] ?? '' ) );

		if ( isset( $_POST['llmsconsentEmail'] ) ) {
			$update_register_fields['llmsconsentEmail'] = sanitize_text_field( wp_unslash( $_POST['llmsconsentEmail'] ) );
		}

		if ( isset( $_POST['llmsconsentPhone'] ) ) {
			$update_register_fields['llmsconsentPhone'] = sanitize_text_field( wp_unslash( $_POST['llmsconsentPhone'] ) );
		}

		$omnisend_api = new OmnisendApiService();
		$omnisend_api->update_omnisend_contact( $update_register_fields );
	}

	/**
	 * phpcs:enable WordPress.Security.NonceVerification.Missing
	 */

	/**
	 * Custom function to trigger when a user just enrolled to course.
	 *
	 * @param int $user_id  The ID of the user being enrolled.
	 * @param int $course_id The ID of the course where user is enrolled.
	 *
	 * @return void
	 */
	public function omnisend_add_enrollment_data( int $user_id, int $course_id ): void {
		$user_info  = get_userdata( $user_id );
		$user_email = $user_info->data->user_email;

		if ( isset( $user_email ) && isset( $course_id ) ) {
			$omnisend_api = new OmnisendApiService();
			$omnisend_api->update_omnisend_enrolment_data( $user_email, $course_id );
		}
	}


	/**
     * phpcs:disable WordPress.Security.NonceVerification.Missing
	 */

	/**
	 * Update an Omnisend contact consent data from order.
	 *
	 * @param LLMS_Order $order Order data
	 *
	 * @return void
	 */
	public function omnisend_update_consent_by_order( LLMS_Order $order ): void {
		$options           = get_option( 'omnisend_lifterlms_options' );
		$bypass_validation = false;

		if ( ! isset( $options['filter_lms_consent_setting'] ) ) {
			$bypass_validation = true;
		}

		if ( ( (float) $order->get( 'total' ) ) <= 0 || ! isset( $_POST['_llms_checkout_nonce'] ) ) {
			if ( ! $bypass_validation ) {
				return;
			}
		}

		$update_fields = array();

		if ( $bypass_validation || ( isset( $_POST['llmsconsentPhone'] ) && $_POST['llmsconsentPhone'] == 1 ) ) {
			$update_fields['llmsconsentPhone'] = 1;
			$update_fields['llms_phone']       = $order->get( 'billing_phone' );
		}

		if ( $bypass_validation || ( isset( $_POST['llmsconsentEmail'] ) && $_POST['llmsconsentEmail'] == 1 ) ) {
			$update_fields['llmsconsentEmail'] = 1;
		}

		$omnisend_api = new OmnisendApiService();
		$omnisend_api->update_consent( $update_fields, $order->get( 'billing_email' ) );
	}
}

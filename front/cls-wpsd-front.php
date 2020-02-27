<?php

/**
 *	Front CLass
 */
class Wpsd_Front
{
	private $wpsd_version;

	public function __construct($version)
	{
		$this->wpsd_version = $version;
		$this->wpsd_assets_prefix = substr(WPSD_PRFX, 0, -1) . '-';
	}

	public function wpsd_front_assets()
	{
		wp_enqueue_style(
			$this->wpsd_assets_prefix . 'front-style',
			WPSD_ASSETS . 'css/' . $this->wpsd_assets_prefix . 'front-style.css',
			array(),
			$this->wpsd_version,
			FALSE
		);
		if (!wp_script_is('jquery')) {
			wp_enqueue_script('jquery');
		}
		wp_enqueue_script(
			$this->wpsd_assets_prefix . 'front-script',
			WPSD_ASSETS . 'js/' . $this->wpsd_assets_prefix . 'front-script.js',
			array('jquery'),
			$this->wpsd_version,
			TRUE
		);
		wp_enqueue_script('checkout-stripe-js', '//checkout.stripe.com/checkout.js');
		$wpsdKeySettings = stripslashes_deep(unserialize(get_option('wpsd_key_settings')));
		if (is_array($wpsdKeySettings)) {
			$wpsdPrimaryKey = !empty($wpsdKeySettings['wpsd_private_key']) ? $wpsdKeySettings['wpsd_private_key'] : "";
			$wpsdSecretKey = !empty($wpsdKeySettings['wpsd_secret_key']) ? $wpsdKeySettings['wpsd_secret_key'] : "";
		} else {
			$wpsdPrimaryKey = "";
			$wpsdSecretKey = "";
		}
		$wpsdGeneralSettings = stripslashes_deep(unserialize(get_option('wpsd_general_settings')));
		if (is_array($wpsdGeneralSettings)) {
			$wpsdPaymentTitle = !empty($wpsdGeneralSettings['wpsd_payment_title']) ? $wpsdGeneralSettings['wpsd_payment_title'] : "Donate Us";
			$wpsdPaymentLogo = !empty($wpsdGeneralSettings['wpsd_payment_logo']) ? $wpsdGeneralSettings['wpsd_payment_logo'] : "";
		} else {
			$wpsdPaymentTitle = "Donate Us";
			$wpsdPaymentLogo = "";
		}
		$wpsdImage = array();
		$image = "";
		if (intval($wpsdPaymentLogo) > 0) {
			$wpsdImage = wp_get_attachment_image_src($wpsdPaymentLogo, 'thumbnail', false);
			$image = $wpsdImage[0];
		} else {
			$image = WPSD_ASSETS . 'img/stripe-default-logo.png';
		}
		$wpsdAdminArray = array(
			'stripePKey'	=> $wpsdPrimaryKey,
			'stripeSKey'	=> $wpsdSecretKey,
			'image'			=> $image,
			'ajaxurl' 		=> admin_url('admin-ajax.php'),
			'title'			=> $wpsdPaymentTitle
		);
		wp_localize_script($this->wpsd_assets_prefix . 'front-script', 'wpsdAdminScriptObj', $wpsdAdminArray);
	}

	public function wpsd_load_shortcode()
	{
		add_shortcode('wp_stripe_donation', array($this, 'wpsd_load_shortcode_view'));
	}

	public function wpsd_load_shortcode_view()
	{
		$output = '';
		ob_start();
		include(plugin_dir_path(__FILE__) . '/view/wpsd-front-view.php');
		$output .= ob_get_clean();
		return $output;
	}

	function wpsd_donation_handler()
	{
		global $wpdb;
		$tableData = WPSD_TABLE;
		/*
		* Validation all required fields
		*/
		if (
			!empty($_POST['token']) && !empty($_POST['wpsdSecretKey']) && !empty($_POST['email']) && !empty($_POST['amount']) && !empty($_POST['name']) &&
			!empty($_POST['phone']) && !empty($_POST['donation_for'])
		) {

			$wpsdDonationFor = sanitize_text_field($_POST['donation_for']);
			$wpsdName = sanitize_text_field($_POST['name']);
			$wpsdEmail = sanitize_email($_POST['email']);
			$wpsdPhone = intval($_POST['phone']);
			$wpsdAmount = intval($_POST['amount']);

			require_once "Stripe/Stripe.php";
			include(HMSD_PATH . '/Stripe/Stripe.php');
			$stripe_key = sanitize_text_field($_POST['wpsdSecretKey']);
			Stripe::setApiKey($stripe_key);

			// Credit card details
			$token = sanitize_text_field($_POST['token']);

			// Transaction starting
			try {
				$charge = Stripe_Charge::create(
					array(
						"amount" => $wpsdAmount,
						"currency" => "usd",
						"card" => $token,
						"description" => $wpsdName . " (" . $wpsdPhone . ") donated for " . $wpsdDonationFor
					)
				);

				$wpsdGeneralSettings = stripslashes_deep(unserialize(get_option('wpsd_general_settings')));
				if (is_array($wpsdGeneralSettings)) {
					$wpsdDonationEmail = !empty($wpsdGeneralSettings['wpsd_donation_email']) ? $wpsdGeneralSettings['wpsd_donation_email'] : "";
				}
				// Send the email if the charge successful.
				$wpsdEmailTo = $wpsdDonationEmail;
				$wpsdEmailSubject = $wpsdName . "(" . $wpsdPhone . ") donated for " . $wpsdDonationFor;
				$wpsdEmailMessage = "Name: " . $wpsdName . "<br>Phone: " . $wpsdPhone . "<br>Email: " . $wpsdEmail . "<br>Amount: $" . substr($wpsdAmount, 0, -2) . "<br>For: " . $wpsdDonationFor;
				$headers = array('Content-Type: text/html; charset=UTF-8');

				wp_mail($wpsdEmailTo, $wpsdEmailSubject, $wpsdEmailMessage, $headers);
				$wpdb->query('INSERT INTO ' . $tableData . ' (
															wpsd_donation_for,
															wpsd_donator_name,
															wpsd_donator_email,
															wpsd_donator_phone,
															wpsd_donated_amount,
															wpsd_donation_datetime
														)
												VALUES(
															"' . $wpsdDonationFor . '",
															"' . $wpsdName . '",
															"' . $wpsdEmail . '",
															"' . $wpsdPhone . '",
															"' . substr($wpsdAmount, 0, -2) . '",
															"' . date('Y-m-d h:i:s') . '"
													)
												');

				// Upon Successful transaction, reply an Success message
				die(json_encode(array(
					"status" => "success",
					"message" => "Thank you for your donation"
				)));
			} catch (Stripe_CardError $e) {

				// Upon unsuccessful transaction/rejection, reply an Error message
				die(json_encode(array(
					"status" => "error",
					"message" => $e
				)));
			}
		}
	}
}
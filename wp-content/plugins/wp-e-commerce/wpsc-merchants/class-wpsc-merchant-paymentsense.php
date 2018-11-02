<?php
/**
 * WP eCommerce Paymentsense Gateway
 *
 * @package WP eCommerce Paymentsense
 * @version 3.10.0
 */

/**
 * Paymentsense Gateway Transaction Result Codes
 */
define( 'PS_TRX_RESULT_SUCCESS', '0' );
define( 'PS_TRX_RESULT_INCOMPLETE', '3' );
define( 'PS_TRX_RESULT_REFERRED', '4' );
define( 'PS_TRX_RESULT_DECLINED', '5' );
define( 'PS_TRX_RESULT_DUPLICATE', '20' );
define( 'PS_TRX_RESULT_FAILED', '30' );

if ( isset( $num ) ) {
	$nzshpcrt_gateways[ $num ] = array(
		'name'                   => 'Paymentsense Hosted v3.10.0',
		'api_version'            => 2.0,
		'class_name'             => 'wpsc_merchant_paymentsense',
		'image'                  => WPSC_URL . '/images/paymentsense.png',
		'has_recurring_billing'  => false,
		'wp_admin_cannot_cancel' => true,
		'requirements'           => array(
			'php_version'   => 4.3,    // So that you can restrict merchant modules to PHP 5, if you use PHP 5 features.
			'extra_modules' => array(), // For modules that may not be present, like curl.
		),
		'internalname'           => 'paymentsense', // Not legacy, still required.
		// All array members below here are legacy.
		'form'                   => 'wpsc_merchant_paymentsense_settings_form',
		'submit_function'        => 'wpsc_merchant_paymentsense_settings_form_submit',
		'payment_type'           => 'paymentsense',
	);
}

/**
 * Calls the method in the submit_paymentsense_settings_form class.
 * At the moment this is called as a function and cannot be called as a method of a class.
 */
function wpsc_merchant_paymentsense_settings_form_submit() {
	return wpsc_merchant_paymentsense::submit_paymentsense_settings_form();
}

/**
 * Calls the method in the paymentsense_settings_form class.
 * At the moment this is called as a function and cannot be called as a method of a class.
 */
function wpsc_merchant_paymentsense_settings_form() {
	return wpsc_merchant_paymentsense::paymentsense_settings_form();
}

/**
 * Integration for the Paymentsense payment gateway.
 */
// @codingStandardsIgnoreLine
class wpsc_merchant_paymentsense extends wpsc_merchant {
	/**
	 * Name
	 *
	 * @var string
	 */
	public $name = 'Paymentsense';

	/**
	 * Data sent to the Hosted Payment Form
	 *
	 * @var array
	 */
	public $request_data = array();

	/**
	 * Builds the fields for the Hosted Payment Form as an associative array
	 */
	public function construct_value_array() {
		$fields = array(
			'Amount'                    => $this->cart_data['total_price'] * 100,
			'CurrencyCode'              => $this->get_currency_iso_code( $this->cart_data['store_currency'] ),
			'OrderID'                   => $this->purchase_id,
			'TransactionType'           => 'SALE',
			'TransactionDateTime'       => date( 'Y-m-d H:i:s P' ),
			'CallbackURL'               => add_query_arg( 'gateway', 'paymentsense', $this->cart_data['notification_url'] ),
			'OrderDescription'          => 'Order ID: ' . $this->purchase_id . ' - ' . $this->cart_data['session_id'],
			'CustomerName'              => $this->cart_data['billing_address']['first_name'] . ' ' . $this->cart_data['billing_address']['last_name'],
			'Address1'                  => trim( implode( '&#10;', explode( "\n\r", $this->cart_data['billing_address']['address'] ) ), '&#10;' ),
			'Address2'                  => '',
			'Address3'                  => '',
			'Address4'                  => '',
			'City'                      => $this->cart_data['billing_address']['city'],
			'State'                     => $this->cart_data['billing_address']['state'],
			'PostCode'                  => $this->cart_data['billing_address']['post_code'],
			'CountryCode'               => $this->get_country_iso_code( $this->cart_data['billing_address']['country'] ),
			'EmailAddress'              => $this->cart_data['email_address'],
			'PhoneNumber'               => $this->cart_data['billing_address']['phone'],
			'EmailAddressEditable'      => 'true',
			'PhoneNumberEditable'       => 'true',
			'CV2Mandatory'              => 'true',
			'Address1Mandatory'         => 'true',
			'CityMandatory'             => 'true',
			'PostCodeMandatory'         => 'true',
			'StateMandatory'            => 'true',
			'CountryMandatory'          => 'true',
			'ResultDeliveryMethod'      => 'POST',
			'ServerResultURL'           => '',
			'PaymentFormDisplaysResult' => 'false',
		);

		$data  = 'MerchantID=' . get_option( 'paymentsense_id' );
		$data .= '&Password=' . get_option( 'paymentsense_password' );

		foreach ( $fields as $key => $value ) {
			$data .= '&' . $key . '=' . $value;
		};

		$additional_fields = array(
			'HashDigest' => $this->calculate_hash_digest( $data, 'SHA1', get_option( 'paymentsense_preshared_key' ) ),
			'MerchantID' => get_option( 'paymentsense_id' ),
		);

		$this->request_data = array_merge( $additional_fields, $fields );
	}

	/**
	 * Performs the redirect to the Hosted Payment Form
	 */
	public function submit() {
		$name_value_pairs = array();
		foreach ( $this->request_data as $key => $value ) {
			$name_value_pairs[] = $key . '=' . rawurlencode( $value );
		}

		$url    = 'https://mms.paymentsensegateway.com/Pages/PublicPages/PaymentForm.aspx';
		$params = implode( '&', $name_value_pairs );
		header( 'Location: ' . $url . '?' . $params, false );
		exit();
	}

	/**
	 * Receives data from the payment gateway
	 */
	public function parse_gateway_notification() {
		if ( ! $this->is_hash_digest_valid() ) {
			wp_die( 'Unexpected response from the payment gateway. Invalid HashDigest. Please contact support.',
				'Unexpected response from the payment gateway',
				array( 'response' => 400 )
			);
		}
	}

	/**
	 * Receives data from the payment gateway
	 */
	public function process_gateway_notification() {
		$order_id         = self::get_post_var( 'OrderID' );
		$message          = self::get_post_var( 'Message' );
		$status_code      = self::get_post_var( 'StatusCode' );
		$prev_status_code = self::get_post_var( 'PreviousStatusCode' );
		$session_id       = explode( ' ', self::get_post_var( 'OrderDescription' ) );

		if ( is_numeric( $status_code ) ) {
			$processed = WPSC_Purchase_Log::INCOMPLETE_SALE;
			switch ( $status_code ) {
				case PS_TRX_RESULT_SUCCESS:
					$processed = WPSC_Purchase_Log::ACCEPTED_PAYMENT;
					break;
				case PS_TRX_RESULT_REFERRED:
					$processed = WPSC_Purchase_Log::PAYMENT_DECLINED;
					break;
				case PS_TRX_RESULT_DECLINED:
					$processed = WPSC_Purchase_Log::PAYMENT_DECLINED;
					break;
				case PS_TRX_RESULT_DUPLICATE:
					$processed = ( PS_TRX_RESULT_SUCCESS === $prev_status_code )
						? WPSC_Purchase_Log::ACCEPTED_PAYMENT
						: WPSC_Purchase_Log::PAYMENT_DECLINED;
					break;
				case PS_TRX_RESULT_FAILED:
					$processed = WPSC_Purchase_Log::INCOMPLETE_SALE;
					break;
				default:
					wp_die( 'Unsupported StatusCode. Please contact support.',
						'Unsupported StatusCode',
						array( 'response' => 400 )
					);
					break;
			}

			$data = array(
				'processed'  => $processed,
				'transactid' => $order_id,
				'notes'      => $message,
				'date'       => time(),
			);
			wpsc_update_purchase_log_details( $session_id[4], $data, 'sessionid' );

			switch ( $processed ) {
				case WPSC_Purchase_Log::ACCEPTED_PAYMENT:
					transaction_results( $session_id, false, $order_id );
					// Thank you for purchasing.
					$this->go_to_transaction_results( $session_id[4] );
					break;
				case WPSC_Purchase_Log::PAYMENT_DECLINED:
					// Sorry, your transaction was not accepted.
					$this->go_to_transaction_results( null );
					break;
				default:
					// Thank you, your purchase is pending.
					$this->go_to_transaction_results( $session_id[4] );
					break;
			}
		} else {
			wp_die( 'Unexpected response from the payment gateway. Please contact support.',
				'Unexpected response from the payment gateway',
				array( 'response' => 400 )
			);
		}
	}

	/**
	 * Gets the output for the settings form
	 *
	 * @return string Settings form output
	 */
	public static function paymentsense_settings_form() {
		$output = "
    	<tr class='firstrowth'>
    		<td style='border-bottom: medium none;' colspan='2'>
    			<strong class='form_group'>Paymentsense Gateway Settings</strong><br>
    		</td>
    	</tr>	
    	<tr>
    		<td>Merchant ID:</td>
    		<td>
    		<input type='text' size='40' value='" . get_option( 'paymentsense_id' ) . "' name='paymentsense_id' />
    		</td>
    	</tr>
    	<tr>
    		<td>Merchant Password:</td>
    		<td>
    		<input type='password' size='28' value='" . get_option( 'paymentsense_password' ) . "' name='paymentsense_password' />
    		</td>
    	</tr>
    	<tr>
    		<td>Pre-shared Key:</td>
    		<td>
    		<input type='text' size='28' value='" . get_option( 'paymentsense_preshared_key' ) . "' name='paymentsense_preshared_key' />
    		</td>
    	</tr>
    	<tr>
    		<td>&nbsp;</td>
    		<td>&nbsp;</td>
    	</tr>
    	<tr class='firstrowth'>
    		<td colspan='2'>
    			<strong class='form_group'>Paymentsense Links</strong>
    		</td>
    	</tr>
    	<tr>
    		<td colspan=\"2\"><a href=\"https://mms.paymentsensegateway.com\">Paymentsense Merchant Management System (MMS)</a></td>
    	</tr>
    	<tr>
    		<td colspan=\"2\"><a href=\"http://www.paymentsense.com/\">Paymentsense website</a></td>
    	</tr>
    	";
		return $output;
	}

	/**
	 * Processes the settings form for the Paymentsense settings.
	 *
	 * @return bool
	 */
	public static function submit_paymentsense_settings_form() {
		$input_fields = array(
			'paymentsense_id',
			'paymentsense_password',
			'paymentsense_preshared_key',
		);

		foreach ( $input_fields as $field ) {
			update_option( $field, self::get_post_var( $field ) );
		}

		return true;
	}

	/**
	 * Gets country ISO 3166-1 code
	 *
	 * @param  string $country_code Country 3166-1 code.
	 * @return string
	 *
	 * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
	 */
	protected function get_country_iso_code( $country_code ) {
		$result    = '';
		$iso_codes = array(
			'AL' => '8',
			'DZ' => '12',
			'AS' => '16',
			'AD' => '20',
			'AO' => '24',
			'AI' => '660',
			'AG' => '28',
			'AR' => '32',
			'AM' => '51',
			'AW' => '533',
			'AU' => '36',
			'AT' => '40',
			'AZ' => '31',
			'BS' => '44',
			'BH' => '48',
			'BD' => '50',
			'BB' => '52',
			'BY' => '112',
			'BE' => '56',
			'BZ' => '84',
			'BJ' => '204',
			'BM' => '60',
			'BT' => '64',
			'BO' => '68',
			'BA' => '70',
			'BW' => '72',
			'BR' => '76',
			'BN' => '96',
			'BG' => '100',
			'BF' => '854',
			'BI' => '108',
			'KH' => '116',
			'CM' => '120',
			'CA' => '124',
			'CV' => '132',
			'KY' => '136',
			'CF' => '140',
			'TD' => '148',
			'CL' => '152',
			'CN' => '156',
			'CO' => '170',
			'KM' => '174',
			'CG' => '178',
			'CD' => '180',
			'CK' => '184',
			'CR' => '188',
			'CI' => '384',
			'HR' => '191',
			'CU' => '192',
			'CY' => '196',
			'CZ' => '203',
			'DK' => '208',
			'DJ' => '262',
			'DM' => '212',
			'DO' => '214',
			'EC' => '218',
			'EG' => '818',
			'SV' => '222',
			'GQ' => '226',
			'ER' => '232',
			'EE' => '233',
			'ET' => '231',
			'FK' => '238',
			'FO' => '234',
			'FJ' => '242',
			'FI' => '246',
			'FR' => '250',
			'GF' => '254',
			'PF' => '258',
			'GA' => '266',
			'GM' => '270',
			'GE' => '268',
			'DE' => '276',
			'GH' => '288',
			'GI' => '292',
			'GR' => '300',
			'GL' => '304',
			'GD' => '308',
			'GP' => '312',
			'GU' => '316',
			'GT' => '320',
			'GN' => '324',
			'GW' => '624',
			'GY' => '328',
			'HT' => '332',
			'VA' => '336',
			'HN' => '340',
			'HK' => '344',
			'HU' => '348',
			'IS' => '352',
			'IN' => '356',
			'ID' => '360',
			'IR' => '364',
			'IQ' => '368',
			'IE' => '372',
			'IL' => '376',
			'IT' => '380',
			'JM' => '388',
			'JP' => '392',
			'JO' => '400',
			'KZ' => '398',
			'KE' => '404',
			'KI' => '296',
			'KP' => '408',
			'KR' => '410',
			'KW' => '414',
			'KG' => '417',
			'LA' => '418',
			'LV' => '428',
			'LB' => '422',
			'LS' => '426',
			'LR' => '430',
			'LY' => '434',
			'LI' => '438',
			'LT' => '440',
			'LU' => '442',
			'MO' => '446',
			'MK' => '807',
			'MG' => '450',
			'MW' => '454',
			'MY' => '458',
			'MV' => '462',
			'ML' => '466',
			'MT' => '470',
			'MH' => '584',
			'MQ' => '474',
			'MR' => '478',
			'MU' => '480',
			'MX' => '484',
			'FM' => '583',
			'MD' => '498',
			'MC' => '492',
			'MN' => '496',
			'MS' => '500',
			'MA' => '504',
			'MZ' => '508',
			'MM' => '104',
			'NA' => '516',
			'NR' => '520',
			'NP' => '524',
			'NL' => '528',
			'AN' => '530',
			'NC' => '540',
			'NZ' => '554',
			'NI' => '558',
			'NE' => '562',
			'NG' => '566',
			'NU' => '570',
			'NF' => '574',
			'MP' => '580',
			'NO' => '578',
			'OM' => '512',
			'PK' => '586',
			'PW' => '585',
			'PA' => '591',
			'PG' => '598',
			'PY' => '600',
			'PE' => '604',
			'PH' => '608',
			'PN' => '612',
			'PL' => '616',
			'PT' => '620',
			'PR' => '630',
			'QA' => '634',
			'RE' => '638',
			'RO' => '642',
			'RU' => '643',
			'RW' => '646',
			'SH' => '654',
			'KN' => '659',
			'LC' => '662',
			'PM' => '666',
			'VC' => '670',
			'WS' => '882',
			'SM' => '674',
			'ST' => '678',
			'SA' => '682',
			'SN' => '686',
			'SC' => '690',
			'SL' => '694',
			'SG' => '702',
			'SK' => '703',
			'SI' => '705',
			'SB' => '90',
			'SO' => '706',
			'ZA' => '710',
			'ES' => '724',
			'LK' => '144',
			'SD' => '736',
			'SR' => '740',
			'SJ' => '744',
			'SZ' => '748',
			'SE' => '752',
			'CH' => '756',
			'SY' => '760',
			'TW' => '158',
			'TJ' => '762',
			'TZ' => '834',
			'TH' => '764',
			'TG' => '768',
			'TK' => '772',
			'TO' => '776',
			'TT' => '780',
			'TN' => '788',
			'TR' => '792',
			'TM' => '795',
			'TC' => '796',
			'TV' => '798',
			'UG' => '800',
			'UA' => '804',
			'AE' => '784',
			'GB' => '826',
			'US' => '840',
			'UY' => '858',
			'UZ' => '860',
			'VU' => '548',
			'VE' => '862',
			'VN' => '704',
			'VG' => '92',
			'VI' => '850',
			'WF' => '876',
			'EH' => '732',
			'YE' => '887',
			'ZM' => '894',
			'ZW' => '716',
		);
		if ( array_key_exists( $country_code, $iso_codes ) ) {
			$result = $iso_codes[ $country_code ];
		}
		return $result;
	}

	/**
	 * Gets currency ISO 4217 code
	 *
	 * @param string $currency_code Currency 4217 code.
	 * @param string $default_code Default currency code.
	 *
	 * @return string
	 */
	protected function get_currency_iso_code( $currency_code, $default_code = '826' ) {
		$result    = $default_code;
		$iso_codes = array(
			'GBP' => '826',
			'USD' => '840',
			'EUR' => '978',
		);
		if ( array_key_exists( $currency_code, $iso_codes ) ) {
			$result = $iso_codes[ $currency_code ];
		}
		return $result;
	}

	/**
	 * Gets the value of a POST variable
	 *
	 * @param string $field HTTP POST variable.
	 * @param string $default Default value.
	 * @return string
	 */
	protected static function get_post_var( $field, $default = '' ) {
		// @codingStandardsIgnoreStart
		return array_key_exists( $field, $_POST )
			? $_POST[ $field ]
			: $default;
		// @codingStandardsIgnoreEnd
	}

	/**
	 * Builds a string containing the variables for calculating the hash digest
	 *
	 * @return bool
	 */
	protected function build_variables_string() {
		$fields = array(
			'StatusCode',
			'Message',
			'PreviousStatusCode',
			'PreviousMessage',
			'CrossReference',
			'Amount',
			'CurrencyCode',
			'OrderID',
			'TransactionType',
			'TransactionDateTime',
			'OrderDescription',
			'CustomerName',
			'Address1',
			'Address2',
			'Address3',
			'Address4',
			'City',
			'State',
			'PostCode',
			'CountryCode',
			'EmailAddress',
			'PhoneNumber',
		);

		$result = 'MerchantID=' . get_option( 'paymentsense_id' ) .
			'&Password=' . get_option( 'paymentsense_password' );
		foreach ( $fields as $field ) {
			$result .= '&' . $field . '=' . self::get_post_var( $field );
		}

		return $result;
	}

	/**
	 * Checks whether the hash digest received from the gateway is valid
	 *
	 * @return bool
	 */
	protected function is_hash_digest_valid() {
		$result = false;
		$data   = $this->build_variables_string();
		if ( $data ) {
			$hash_digest_received   = self::get_post_var( 'HashDigest' );
			$hash_digest_calculated = $this->calculate_hash_digest( $data, 'SHA1', get_option( 'paymentsense_preshared_key' ) );
			$result                 = strToUpper( $hash_digest_received ) === strToUpper( $hash_digest_calculated );
		}
		return $result;
	}

	/**
	 * Calculates the hash digest.
	 * Supported hash methods: MD5, SHA1, HMACMD5, HMACSHA1
	 *
	 * @param string $data Data to be hashed.
	 * @param string $hash_method Hash method.
	 * @param string $key Secret key to use for generating the hash.
	 *
	 * @return string
	 */
	protected function calculate_hash_digest( $data, $hash_method, $key ) {
		$result = '';

		$include_key = in_array( $hash_method, array( 'MD5', 'SHA1' ), true );
		if ( $include_key ) {
			$data = 'PreSharedKey=' . $key . '&' . $data;
		}

		switch ( $hash_method ) {
			case 'MD5':
				$result = md5( $data );
				break;
			case 'SHA1':
				$result = sha1( $data );
				break;
			case 'HMACMD5':
				$result = hash_hmac( 'md5', $data, $key );
				break;
			case 'HMACSHA1':
				$result = hash_hmac( 'sha1', $data, $key );
				break;
		}

		return $result;
	}
}

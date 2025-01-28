<?php

/*
 * Plugin Name: WooCommerce HB Epay Payment Gateway 
 * Description: Take credit card payments on your store.
 * Author: Sprint Squads
 * Author URI: https://s10s.co
 * Developers: Sprint Squads
 * Version: 1.0.1
 */

add_action('plugins_loaded', 'hb_epay_init_gateway_class', 0);

function hb_epay_init_gateway_class() {
  class WC_HB_Epay_Gateway extends WC_Payment_Gateway {

    public function __construct() {
      $this->id = 'hb_epay';
      $this->icon = '';
      $this->has_fields = true;
      $this->method_title = 'HB ePay Gateway';
      $this->method_description = 'Оплата безналичными';

      $this->supports = array(
        'products'
      );

      $this->init_form_fields();
      $this->init_settings();

      $this->title = $this->get_option('title');
      $this->description = $this->get_option('description');
      $this->enabled = $this->get_option('enabled');
      $this->env = $this->get_option('testmode');
      $this->client_id = $this->get_option('client_id');
      $this->client_secret = $this->get_option('client_secret');
      $this->terminal = $this->get_option('terminal');

      add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
      add_action('woocommerce_receipt_' . $this->id, array( $this, 'receipt_page'));
      add_action('woocommerce_thankyou_' . $this->id, array($this, 'thankyou_page'));

    }

    public function init_form_fields() {
      $this->form_fields = array(
        'enabled' => array(
          'title'       => 'Enable/Disable',
          'label'       => 'Enable HBepay Gateway',
          'type'        => 'checkbox',
          'description' => '',
          'default'     => 'no'
        ),
        'title' => array(
          'title'       => 'Title',
          'type'        => 'text',
          'description' => 'This controls the title which the user sees during checkout.',
          'default'     => 'HB epay',
          'desc_tip'    => true,
        ),
        'description' => array(
          'title'       => 'Description',
          'type'        => 'textarea',
          'description' => 'This controls the description which the user sees during checkout.',
          'default'     => 'Pay with your credit card HB payment gateway.',
        ),
        'testmode' => array(
          'title'       => 'Test mode',
          'label'       => 'Enable Test Mode',
          'type'        => 'checkbox',
          'description' => 'Place the payment gateway in test mode using test API keys.',
          'default'     => 'no',
          'desc_tip'    => true,
        ),
        'client_id' => array(
          'title'       => 'Clien id',
          'type'        => 'text'
        ),
        'client_secret' => array(
          'title'       => 'Client secret',
          'type'        => 'text'
        ),
        'terminal' => array(
          'title'       => 'Terminal',
          'type'        => 'text'
        )
      );
    }


  public function generate_hbepay_form( $order_id ) {

    return '<form action="' . $this->pg_redirect($order_id) . '" method="post" id="hbepay_payment_form">
        <input type="submit" class="button-alt" id="submit_hbepay_payment_form" value="" /> 
        <script type="text/javascript">
          jQuery(function(){
            jQuery( "#submit_hbepay_payment_form" ).click();
          });
        </script>
      </form>';
  }

  public function pg_redirect($order_id) {

  $order = wc_get_order($order_id);

  $test_url = "https://testoauth.homebank.kz/epay2/oauth2/token";
  $prod_url = "https://epay-oauth.homebank.kz/oauth2/token";
  $test_page = "https://test-epay.homebank.kz/payform/payment-api.js";
  $prod_page = "https://epay.homebank.kz/payform/payment-api.js";

    
  $err_exist = false;
  $err = "";

  // initiate default variables
  $hbp_description = $this->description;
  $hbp_env = $this->env;
  $hbp_client_id = $this->client_id;
  $hbp_client_secret = $this->client_secret;
  $hbp_terminal = $this->terminal;
  $hbp_amount = $order->get_total();
  $hbp_invoice_id = '00000' . $order->get_id();
  $hbp_back_link = $this->get_return_url($order);
  $hbp_failure_back_link = $order->get_cancel_order_url();

  $hbp_account_id = "";
  $hbp_telephone = "";
  $hbp_email = "";
  $hbp_currency = 'KZT';
  $hbp_language = substr(get_bloginfo('language'), 0, 2);

  if ($hbp_env == 'yes') {
    $token_api_url = $test_url;
    $pay_page = $test_page;
  } else {
    $token_api_url = $prod_url;
    $pay_page = $prod_page;
  }
// initiate environment

  $fields = [
    'grant_type'      => 'client_credentials', 
    'scope'           => 'payment usermanagement',
    'client_id'       => $hbp_client_id,
    'client_secret'   => $hbp_client_secret,
    'invoiceID'       => $hbp_invoice_id,
    'amount'          => $hbp_amount,
    'currency'        => $hbp_currency,
    'terminal'        => $hbp_terminal,
    'postLink'        => '',
    'failurePostLink' => ''
  ];

  // build query for request
  $fields_string = http_build_query($fields);

  // open connection
  $ch = curl_init();

  // set the option
  curl_setopt($ch, CURLOPT_URL, $token_api_url);
  curl_setopt($ch, CURLOPT_POST, true);
  curl_setopt($ch, CURLOPT_POSTFIELDS, $fields_string);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); 

  // execute post
  $result = curl_exec($ch);

  $json_result = json_decode($result, true);
  if (!curl_errno($ch)) {
    switch ($http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE)) {
      case 200:
        $hbp_auth = (object) $json_result;

        $hbp_payment_object = (object) [
          "invoiceId" => $hbp_invoice_id,
          "backLink" => $hbp_back_link,
          "failureBackLink" => $hbp_failure_back_link,
          "postLink" => '',
          "failurePostLink" => '',
          "language" => $hbp_language,
          "description" => $hbp_description,
          "accountId" => $hbp_account_id,
          "terminal" => $hbp_terminal,
          "amount" => $hbp_amount,
          "currency" => $hbp_currency,
          "auth" => $hbp_auth,
          "phone" => $hbp_telephone,
          "email" => $hbp_email
        ];
        ?>
          <script src="<?=$pay_page?>"></script>
          <script>
            halyk.pay(<?= json_encode($hbp_payment_object) ?>);
          </script>
        <?php
        break;
      default:
        echo 'Неожиданный код HTTP: ', $http_code, "\n";
    }
  }
}

  public function process_payment($order_id) {
      
    $order = wc_get_order( $order_id );

    return array(
      'result'    => 'success',
      'redirect'  => $order->get_checkout_payment_url( true ),
    );
}

  public function thankyou_page($order_id)
    {
        $order = new WC_Order( $order_id );
        $order->update_status( 'completed' ); 
    return;
    }

  public function receipt_page( $order ) {
    echo $this->generate_hbepay_form( $order );
  }
}

function hb_epay_add_gateway_class($gateways) {
  $gateways[] = 'WC_HB_Epay_Gateway';
  return $gateways;
}

add_filter('woocommerce_payment_gateways', 'hb_epay_add_gateway_class');

    } 
  
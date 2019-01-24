<?php
/*
Plugin Name: Remita Payment Gateway for Easy Digital Downloads
Plugin URL: http://remita.net
Description: Easy Digital Downloads Plugin for accepting payment through Remita Payment Gateway.
Version: 1.0
Author: SystemSpecs Limited @ Oshadami MIke
Author URI: http://www.remita.net
Text Domain: edd-remita-payment-gateway
*/

// registers the gateway
function edd_remita_register_gateway( $gateways ) {
    remita_currency_valid_for_use();
    $gateways['remita'] = array(
        'admin_label' => 'Remita Payment Gateway',
        'checkout_label' => __( 'Remita Payment Gateway', 'edd-remita-payment-gateway' )
    );
    return $gateways;
}

add_filter( 'edd_payment_gateways', 'edd_remita_register_gateway' );
add_filter('edd_currencies', 'remita_edd_add_my_currency');
add_filter( 'edd_currency', 'remita_edd_add_ngn' );
add_filter('edd_currency_symbol', 'remita_edd_add_my_currency_symbol', 10, 2);
add_filter( 'edd_ngn_currency_filter_before', 'edd_ngn_currency_filter_before', 10, 3 );

function edd_remita_paymentchannel_cc_form() {
    global $edd_options;
    ob_start(); ?>
    <fieldset>
        <?php echo '<br /><br /><img src="' . plugins_url( 'images/remita-payment-options.png', __FILE__ ) . '" > '; ?>
        </p>

    </fieldset>
    <?php
    echo ob_get_clean();
}
add_action('edd_remita_cc_form', 'edd_remita_paymentchannel_cc_form');

function edd_remita_process_payment( $purchase_data ) {
    global $edd_options;

    $payment_data = array(
        'price' => $purchase_data['price'],
        'date' => $purchase_data['date'],
        'user_email' => $purchase_data['user_email'],
        'purchase_key' => $purchase_data['purchase_key'],
        'currency' => $edd_options['currency'],
        'downloads' => $purchase_data['downloads'],
        'user_info' => $purchase_data['user_info'],
        'cart_details' => $purchase_data['cart_details'],
        'status' => 'pending'
    );

    print_r( "......Please Wait");

    // record the pending payment
    $payment = edd_insert_payment( $payment_data );

    function getTransactionId(){
        $session    = edd_get_purchase_session();
        $purchase_id = edd_get_purchase_id_by_key( $session['purchase_key'] );
        $uniqueId = uniqid();
        $transactionId = $uniqueId. "_" .$purchase_id;

        return $transactionId;
    }


    if( $payment ) {
        // only send to Remita if the pending payment is created successfully
        $return_url = trailingslashit(home_url()).'?remita_response';
        $cart_summary = edd_get_purchase_summary( $purchase_data, false );
        $transactionId = getTransactionId();
        $formattedUrl = $return_url .'&transactionId=' .$transactionId;

        // one time payment
        if( $edd_options['edd_remita_environment'] == 'Test' ){
            $remita_gateway = 'https://remitademo.net/payment/v1/remita-pay-inline.bundle.js';
        }else if( $edd_options['edd_remita_environment'] == 'Live' ){
            $remita_gateway = 'https://login.remita.net/payment/v1/remita-pay-inline.bundle.js';
        }

        $remita_args_array = array();
        $remita_args = array(
            'publicKey' => $edd_options['edd_remita_primarykey'],
            'secretKey' => $edd_options['edd_remita_secretkey'],
            'description' => $cart_summary,
            'amt' => edd_sanitize_amount($purchase_data['price']),
            'orderId' => edd_sanitize_text_field($purchase_data['purchase_key']),
            'responseurl' => $return_url,
            'returnUrl' => $formattedUrl,
            'paymenttype' => edd_sanitize_text_field($purchase_data['post_data']['paymenttype']),
            'payerName' => edd_sanitize_text_field($purchase_data['user_info']['first_name']) . ' ' . edd_sanitize_text_field($purchase_data['user_info']['last_name']),
            'payerEmail' => edd_sanitize_text_field($purchase_data['user_email'])
        );

        foreach ($remita_args as $key => $value) {
            $remita_args_array[] = '<input type="hidden" name="'.esc_attr( $key ).'" value="'.esc_attr( $value ).'" />';
        }

        echo '<script src='.$remita_gateway.'></script>
        <script>
        
            var amt ="'. $remita_args['amt'].'";
            var key ="'. $remita_args['publicKey'].'";
            var email ="'. $remita_args['payerEmail'].'";
            var firstname ="'. $purchase_data['user_info']['first_name'].'";
            var lastname ="'. $purchase_data['user_info']['last_name'].'";
            var transactionId ="'. $transactionId .'";
            var returnUrl = "'. $remita_args['returnUrl'].'";

            var paymentEngine = RmPaymentEngine.init({
                key: key,
                customerId: email,
                firstName: firstname,
                lastName: lastname,
                transactionId: transactionId,
                narration: "bill pay",
                email: email,
                amount: amt,
                onSuccess: function (response) {
                 window.location.href= returnUrl;
                    console.log(\'callback Successful Response\', response);
                },
                onError: function (response) {
                    console.log(\'callback Error Response\', response);
                },
                onClose: function () {
                    console.log("closed");
                }
            });

            paymentEngine.showPaymentWidget();

        </script>';

    } else {
        // if errors are present, send the user back to the purchase page so they can be corrected
        edd_send_back_to_checkout( '?payment-mode=remita' );
    }


}
add_action( 'edd_gateway_remita', 'edd_remita_process_payment' );



function remita_edd_add_my_currency( $currencies ) {
    $currencies['NGN'] = __( 'Nigerian Naira (&#8358;)', 'edd-remita' );
    return $currencies;
}
function remita_edd_add_ngn($currency) {
    $currency = 'NGN';
    global $edd_options;
    $edd_options[ 'NGN' ] = 'NGN';
    return $currency;
}


function remita_edd_add_my_currency_symbol( $symbol, $currency ) {
    switch( $currency ) {
        case 'NGN': $symbol = '&#8358; '; break;
    }
    return $symbol;
}
function edd_ngn_currency_filter_before($formatted, $currency, $price)
{
    switch( $currency ) {
        case 'NGN':
            $formatted = '&#8358;' . $price;
            break;
    }
    return $formatted;
}

function remita_currency_valid_for_use(){
    //check if currency is not Nigeria naira
    if(  edd_get_currency() != 'NGN'  ){
        $dfdf = edd_get_currency();
        $this->warning = __('Remita doesn\'t support your store currency, set it  hto Nigerian Naira &#8358; <a href="' . get_bloginfo('wpurl') . '/wp-admin/edit.php?post_type=download&page=edd-settings&tab=general&section=currency">here</a>');
        return false;
    }
}
function remita_response_ipn() {
    global $edd_options;
    @ob_clean();
    if ( isset( $_GET['transactionId'] )) {
        do_action( 'check_remita_response',$_GET );
    }
}
function updatePaymentStatus($orderId,$response_code,$response_msg)	{
    $status = false;
    switch($response_code)
    {
        case "SUCCESSFUL":
            edd_update_payment_status($orderId, 'publish');
            edd_set_payment_transaction_id( $orderId);
            $status = true;
            break;
        default:
            //process a failed transaction
            edd_update_payment_status($orderId, 'failed' );
            edd_set_payment_transaction_id($orderId);
            edd_insert_payment_note( $orderId, __( 'Payment failed , Reason: '. $response_msg. 'easy-digital-downloads' ) );
            break;
    }

    return $status;
}
add_action( 'init', 'remita_response_ipn');

function process_remita_response($posted) {
    @ob_clean();
    global $edd_options;

    if( isset($posted['transactionId'] ) ){
        $orderId = edd_sanitize_text_field($posted['transactionId']);
        $order_details = explode('_', $orderId);
        $store_order_id = (int) $order_details[1];
        $response = remita_transaction_details($orderId);
        $response_code = $response['responseCode'];
        $response_msg = $response['responseMsg'];
        $paymentState = $response['responseData']['0']['paymentState'];
        $payment_amount = $response['responseData']['0']['amount'];
        $payment_state = $response['responseData']['0']['paymentReference'];
        $callUpdate = updatePaymentStatus($store_order_id, $paymentState , $response_msg);
        $transactionId =  $response['responseData']['0']['transactionId'];
        if($response_code == "34"){
            edd_set_error('error_transaction_failed', __('Transaction Failed ' . ' Reason: ' . $response_msg . ' .Please ensure you have valid credentials on your Admin Panel.', 'pw_edd'));
            edd_send_back_to_checkout('?payment-mode=remita');
        }else{
            if ($callUpdate) {
                edd_empty_cart();
                edd_send_to_success_page();
            } else {

                edd_set_error('error_transaction_failed', __('Transaction Failed ' . ' Reason: ' . $response_msg, 'pw_edd'));
                edd_send_back_to_checkout('?payment-mode=remita');
            }
        }


    }
}
add_action( 'check_remita_response', 'process_remita_response');

function remita_transaction_details($orderId){
    global $edd_options;
    $publickey =  $edd_options['edd_remita_primarykey'];
    $secretkey =  $edd_options['edd_remita_secretkey'];

    $txnHash = hash('sha512', $orderId . $secretkey);

    $header = array(
        'Content-Type: application/json',
        'publicKey:' . $publickey,
        'TXN_HASH:' . $txnHash
    );


    if( $edd_options['edd_remita_environment'] == 'Test' ){
        $query_url = 'https://remitademo.net/payment/v1/payment/query/';
    }
    if( $edd_options['edd_remita_environment'] == 'Live' ){
        $query_url = 'https://login.remita.net/payment/v1/payment/query/';
    }
    $url 	= $query_url . $orderId;

    //  Initiate curl
    $ch = curl_init();

    // Disable SSL verification
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

    // Will return the response, if false it print the response
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);


    // Set the url
    curl_setopt($ch, CURLOPT_URL, $url);

    // Set the header
    curl_setopt($ch, CURLOPT_HTTPHEADER, $header);


    // Execute
    $result = curl_exec($ch);

    // Closing
    curl_close($ch);

    // decode json
    $result_response = json_decode($result, true);

    return $result_response;
}
// adds the settings to the Payment Gateways section
function edd_remita_add_settings( $settings ) {
    global $edd_options;

    $remita_settings = array(
        array(
            'id' => 'edd_remita_settings',
            'name' => '<strong>' . __( 'Remita Settings', 'pw_edd' ) . '</strong>',
            'desc' => __( 'Configure the gateway settings', 'pw_edd' ),
            'type' => 'header'
        ),
        array(
            'id' => 'edd_remita_primarykey',
            'name' => __( 'Public Key', 'pw_edd' ),
            'desc' => __( 'Enter your public key', 'pw_edd' ),
            'type' => 'text',
            'size' => 'regular'
        ),
        array(
            'id' => 'edd_remita_secretkey',
            'name' => __( 'Secret Key', 'pw_edd' ),
            'desc' => __( 'Enter your secret key', 'pw_edd' ),
            'type' => 'text',
            'size' => 'regular'
        ),
        array(
            'id' => 'edd_remita_environment',
            'name' => __('Environment', 'pw_edd'),
            'desc' => __('Select Test or Live Environment.', 'pw_edd'),
            'type' => 'select',
            'options' => array(
                'Test' => 'Test',
                'Live' => 'Live'
            ),
            'size' => 'regular'
        )
    );

    return array_merge( $settings, $remita_settings );
}
add_filter( 'edd_settings_gateways', 'edd_remita_add_settings' );

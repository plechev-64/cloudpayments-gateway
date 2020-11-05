<?php

if (!is_admin()):
    add_action('wp_enqueue_scripts','rcl_add_cloudpayments_scripts',10);
endif;

function rcl_add_cloudpayments_scripts(){
    wp_enqueue_script( 'cloudpayments-script', 'https://widget.cloudpayments.ru/bundles/cloudpayments' );
}

if (class_exists('Rcl_Payment')) {

add_action('init','rcl_add_cloudpayments_payment');
function rcl_add_cloudpayments_payment(){
    $pm = new Cloudpayments_Payment();
    $pm->register_payment('cloudpayments');
}

class Cloudpayments_Payment extends Rcl_Payment{

    public $form_pay_id;

    function register_payment($form_pay_id){
        $this->form_pay_id = $form_pay_id;
        parent::add_payment($this->form_pay_id, array(
            'class'=>get_class($this),
            'request'=>'CardExpDate',
            'name'=>'Cloudpayments',
            'image'=>rcl_addon_url('assets/cloudpayments.jpg',__FILE__)
            ));
        if(is_admin()) $this->add_options();
    }

    function add_options(){
        add_filter('rcl_pay_option',(array($this,'options')));
        add_filter('rcl_pay_child_option',(array($this,'child_options')));
    }

    function options($options){
        $options[$this->form_pay_id] = 'Cloudpayments';
        return $options;
    }

    function child_options($child){
        global $rmag_options;

        $opt = new Rcl_Options();

        $options = array(
            array(
                'type' => 'text',
                'slug' => 'cp_public_id',
                'title' => __('Public ID'),
                'notice' => 'Возьмите из личного кабинета CloudPayments'
            ),
            array(
                'type' => 'text',
                'slug' => 'cp_api_pass',
                'title' => __('Пароль для API'),
                'notice' => 'Название магазина которое будет видимо при совершении платежа'
            )
        );

        $child .= $opt->child(
            array(
                'name'=>'connect_sale',
                'value'=>$this->form_pay_id
            ),
            array(
                $opt->options_box(
                    __('Настройки Cloudpayments"'), $options
                )
            )
        );

        return $child;
    }

    function pay_form($data){
        global $rmag_options,$user_ID;

        $public_id = $rmag_options['cp_public_id'];
        $currency = $rmag_options['primary_cur'];

        if($data->submit_value)
            $submit_value = $data->submit_value;
        else
            $submit_value = ($data->pay_type==1)? __('Confirm the operation','wp-recall'): __('Pay via','wp-recall').' "'.$data->connect['name'].'"';

        $background = (isset($data->connect['image']) && $data->merchant_icon)? 'style="background-image: url('.$data->connect['image'].');"': '';

        $class_icon = ($background)? 'exist-merchant-icon': '';

        $baggage_data = ($data->baggage_data)? $data->baggage_data: 'false';

        if($user_ID){

            $form_id = $data->pay_id.'_'.str_replace(array('.',','),'_',$data->pay_summ);

            $form = "<div class='rcl-pay-form'>"
                    . "<div class='rcl-pay-button'>"
                    . "<span class='rcl-connect-submit $class_icon' ".$background.">"
                        . "<input type='button' class='recall-button' id='cloudpayments-checkout-".$form_id."' value='$submit_value'>"
                    . "</span>"
                . "</div>
                <script>
                    this.cp_pay_".$form_id." = function () {
                        var widget = new cp.CloudPayments();
                        widget.charge({
                            publicId: '$public_id',
                            description: 'Оплата заказа $data->pay_id от ".get_the_author_meta('user_email',$data->user_id)."',
                            amount: $data->pay_summ,
                            currency: '$currency',
                            invoiceId: $data->pay_id,
                            accountId: '$data->user_id',
                            data: {
                                cp_type_pay: '$data->pay_type',
                                cp_baggage: '$baggage_data'
                            }},
                            function (options) {
                                window.location.replace('".get_permalink($rmag_options['page_successfully_pay'])."');
                            }
                        );
                    };
                    jQuery('#cloudpayments-checkout-".$form_id."').click(cp_pay_".$form_id.");
                </script>
                </div>";

        }else{

            $form = "<div class='rcl-pay-form'>"
                . "<div class='rcl-pay-button'>"
                . "<span class='rcl-connect-submit $class_icon' ".$background.">"
                    . "<a class='recall-button rcl-login' href=".rcl_get_loginform_url('login').">$submit_value</a>"
                . "</span>"
            . "</div>
            </div>";

        }

        return $form;
    }

    function result($data){
        global $rmag_options;

        $api_pass = $rmag_options['cp_api_pass'];

        echo '{"code":0}';
        $headers = getallheaders();
        if ((!isset($headers['Content-HMAC'])) and (!isset($headers['Content-Hmac']))) {
            mail(get_option('admin_email'), 'не установлены заголовки', print_r($headers,1));
            exit;
        }
        $message = file_get_contents('php://input');

        $s = hash_hmac('sha256', $message, $api_pass, true);
        $hmac = base64_encode($s);

        if ($headers['Content-HMAC'] != $hmac){
            rcl_mail_payment_error($hmac,$headers);
            exit;
        }

        $posted = wp_unslash( $_POST );

        $customData = json_decode($posted['Data']);

        $data->pay_summ = $posted['PaymentAmount'];
        $data->pay_id = $posted['InvoiceId'];
        $data->user_id = $posted['AccountId'];
        $data->pay_type = $customData->cp_type_pay;
        $data->baggage_data = $customData->cp_baggage;

        if ($posted['Status'] == 'Completed') {
            if(!parent::get_pay($data)){
                parent::insert_pay($data);
            }
        }
        exit;
    }

}

}

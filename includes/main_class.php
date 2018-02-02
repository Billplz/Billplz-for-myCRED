<?php
if (! class_exists('billplz_buycred_gateway')) {
    require_once 'Billplz_API.php';
    class billplz_buycred_gateway extends myCRED_Payment_Gateway
    {
        public function __construct($gateway_prefs)
        {

            // Add a default exchange rate for all point types
            $types            = mycred_get_types();
            $default_exchange = array();
            foreach ($types as $type => $label) {
                $default_exchange[ $type ] = 1;
            }

            // Register settings
            parent::__construct(array(
                'id'               => 'billplz_gateway',
                'label'            => 'Billplz Payment Gateway',
                'defaults'         => array(
                    'billplz_api_key'     => '',
                    'billplz_x_signature' => '',
                    'billplz_collection_id' => '',
                    'billplz_notification' => '0',
                    'billplz_description' => 'Payment',
                    'currency'         => 'MYR',
                    'exchange'         => $default_exchange
                )
            ), $gateway_prefs);
        }

        public function preferences()
        {
            $prefs = $this->prefs; ?>
            <label class="subheader" for="<?php echo $this->field_id('billplz_api_key'); ?>"><?php _e('API Secret Key', 'mycred'); ?></label>
            <ol>
            	<li>
            		<div class="h2"><input type="text" name="<?php echo $this->field_name('billplz_api_key'); ?>" id="<?php echo $this->field_id('billplz_api_key'); ?>" value="<?php echo $prefs['billplz_api_key']; ?>" class="long" /></div>
            	</li>
            </ol>
            <label class="subheader" for="<?php echo $this->field_id('billplz_x_signature'); ?>"><?php _e('X Signature Key', 'mycred'); ?></label>
            <ol>
            	<li>
            		<div class="h2"><input type="text" name="<?php echo $this->field_name('billplz_x_signature'); ?>" id="<?php echo $this->field_id('billplz_x_signature'); ?>" value="<?php echo $prefs['billplz_x_signature']; ?>" class="long" /></div>
            	</li>
            </ol>
            <label class="subheader" for="<?php echo $this->field_id('billplz_description'); ?>"><?php _e('Bill Description', 'mycred'); ?></label>
            <ol>
            	<li>
            		<div class="h2"><input type="text" name="<?php echo $this->field_name('billplz_description'); ?>" id="<?php echo $this->field_id('billplz_description'); ?>" value="<?php echo $prefs['billplz_description']; ?>" class="long" /></div>
            	</li>
            </ol>
            <label class="subheader" for="<?php echo $this->field_id('billplz_collection_id'); ?>"><?php _e('Collection ID', 'mycred'); ?></label>
            <ol>
            	<li>
            		<div class="h2"><input type="text" name="<?php echo $this->field_name('billplz_collection_id'); ?>" id="<?php echo $this->field_id('billplz_collection_id'); ?>" value="<?php echo $prefs['billplz_collection_id']; ?>" class="long" /></div>
            	</li>
            </ol>
            <label class="subheader" for="<?php echo $this->field_id('billplz_notification'); ?>"><?php _e('Bill Notification', 'mycred'); ?></label>
            <ol>
            	<li>
            		<select name="<?php echo $this->field_name('billplz_notification'); ?>" id="<?php echo $this->field_id('billplz_notification'); ?>">
            <?php

                        $options = array(
                            '0'   => __('No Notification', 'mycred'),
                            '1' => __('Email Only (FREE)', 'mycred'),
                            '2' => __('SMS Only (RM 0.15)', 'mycred'),
                            '3' => __('Both (RM 0.15)', 'mycred')
                        );
            foreach ($options as $value => $label) {
                echo '<option value="' . $value . '"';
                if ($prefs['billplz_notification'] == $value) {
                    echo ' selected="selected"';
                }
                echo '>' . $label . '</option>';
            } ?>

            		</select>
            	</li>
            </ol>
            <?php
        }

        public function buy()
        {
            if (! isset($this->prefs['billplz_api_key']) || empty($this->prefs['billplz_api_key'])) {
                wp_die(__('Please setup Billplz Payment Gateway API KEY before attempting to make a purchase!', 'mycred'));
            }

            if (! isset($this->prefs['billplz_x_signature']) || empty($this->prefs['billplz_x_signature'])) {
                wp_die(__('Please setup Billplz Payment Gateway X SIGNATURE KEY before attempting to make a purchase!', 'mycred'));
            }

            // Prep
            $type         = $this->get_point_type();
            $mycred       = mycred($type);

            $amount       = $mycred->number($_REQUEST['amount']);
            $amount       = abs($amount);

            $cost         = $this->get_cost($amount, $type);
            $to           = $this->get_to();
            $from         = get_current_user_id();
            $thankyou_url = $this->get_thankyou();

            $item_name    = str_replace('%number%', $amount, $this->prefs['billplz_description']);
            $item_name    = $mycred->template_tags_general($item_name);

            // Revisiting pending payment
            if (isset($_REQUEST['revisit'])) {
                $this->transaction_id = strtoupper(sanitize_text_field($_REQUEST['revisit']));
            }
            // New pending payment
            else {
                $post_id              = $this->add_pending_payment(array( $to, $from, $amount, $cost, $this->prefs['currency'], $type ));
                $this->transaction_id = get_the_title($post_id);
            }

            $cancel_url   = $this->get_cancelled($this->transaction_id);

            $billplz = new Billplz_API($this->prefs['billplz_api_key']);
            $billplz
                ->setCollection($this->prefs['billplz_collection_id'])
                ->setAmount($cost)
                ->setName($this->get_buyers_name($from))
                ->setDeliver($this->prefs['billplz_notification'])
                ->setEmail($this->get_buyers_email($from))
                ->setMobile($this->get_buyers_phone($from))
                ->setDescription($item_name)
                ->setReference_1($this->transaction_id)
                ->setReference_1_Label('ID')
                ->setPassbackURL($this->callback_url(), $this->callback_url())
                ->create_bill(true);
            $url = $billplz->getURL();

            if (empty($url)) {
                wp_die(__('Something went wrong! ' . $billplz->getErrorMessage(), 'mycred'));
            }

            if (! add_post_meta($post_id, 'billplz_id', $billplz->getID(), true)) {
                update_post_meta($post_id, 'billplz_id', $billplz->getID());
            }

            if (! add_post_meta($post_id, 'billplz_api_key', $this->prefs['billplz_api_key'], true)) {
                update_post_meta($post_id, 'billplz_api_key', $this->prefs['billplz_api_key']);
            }

            if (! add_post_meta($post_id, 'billplz_paid', 'false', true)) {
                update_post_meta($post_id, 'billplz_paid', 'false');
            }

            header('Location: '. $url);

            /*
            Create Checkout Page
            $this->get_page_header(__('Redirecting to Billplz Payment Page..', 'mycred')); ?>
            <div class="continue-forward" style="text-align:center;">
            	<p>&nbsp;</p>
                <img src="<?php echo plugins_url('assets/images/loading.gif', MYCRED_PURCHASE); ?>" alt="Loading" />
            	<p id="manual-continue"><a href="<?php echo $url; ?>"><?php _e('Click here if you are not automatically redirected', 'mycred'); ?></a></p>
            </div>
            <script>window.location.replace("<?php echo $url; ?>");</script>
            <?php

            $this->get_page_footer();
            */
            exit;
        }

        public function process()
        {
            $api_key = $this->prefs['billplz_api_key'];
            $x_signature = $this->prefs['billplz_x_signature'];

            try {
                if (isset($_GET['billplz']['id'])) {
                    $data = Billplz_API::getRedirectData($x_signature);
                } else {
                    $data = Billplz_API::getCallbackData($x_signature);
                }
            } catch (\Exception $e) {
                exit($e->getMessage());
            }

            $billplz = new Billplz_API($api_key);
            $moreData = $billplz->check_bill($data['id']);

            $pending_post_id = $moreData['reference_1'];
            $pending_payment = $this->get_pending_payment($pending_post_id);
            //echo '<pre>'.print_r($pending_payment, true).'</pre>';
            //exit;
            if ($pending_payment !== false) {
                $errors   = false;
                $new_call = array();

                // Check amount paid
                if (number_format($moreData['amount']/100, 2) !== $pending_payment->cost) {
                    $new_call[] = sprintf(__('Price mismatch. Expected: %s Received: %s', 'mycred'), $pending_payment->cost, $moreData['amount']);
                    $errors     = true;
                }

                // Check status
                if (!$moreData['paid']) {
                    $new_call[] = sprintf(__('Payment not completed. Received: %s', 'mycred'), 'FAILED');
                    $errors     = true;
                }

                // Credit payment
                if ($errors === false) {

                    // If account is credited, delete the post and it's comments.
                    if ($this->complete_payment($pending_payment, $data['id'])) {
                        update_post_meta($post_id, 'billplz_paid', 'true');
                        $this->trash_pending_payment($pending_post_id);
                    } else {
                        $new_call[] = __('Failed to credit users account.', 'mycred');
                    }
                }

                // Log Call
                if (! empty($new_call)) {
                    $this->log_call($pending_post_id, $new_call);
                }
            }

            if (isset($_GET['billplz']['id']) && $moreData['paid']) {
                header('Location: '.$this->get_thankyou());
            } elseif (isset($_GET['billplz']['id']) && !$moreData['paid']) {
                header('Location: '.$pending_payment->cancel_url);
                // OR
                //header('Location: '.$this->get_cancelled($moreData['reference_1']));
            }

            exit('ALL IS WELL');
        }

        public function get_buyers_email($user_id = null)
        {
            if ($user_id === null) {
                return '';
            }

            $user = get_userdata($user_id);
            if (! isset($user->ID)) {
                return $user_id;
            }

            if (! empty($user->data->user_email)) {
                $email = $user->data->user_email;
            } else {
                wp_die(__('User no email?', 'mycred'));
            }

            return $email;
        }

        public function get_buyers_phone($user_id = null)
        {
            if ($user_id === null) {
                return '';
            }

            if (class_exists('WooCommerce')) {
                $phone = get_user_meta($user_id, 'billing_phone', true);
            }

            if (empty($phone)) {
                return '';
            }

            return $phone;
        }

        public static function delete_bill($post_id)
        {
            global $post_type;

            if ($post_type !== 'buycred_payment') {
                return;
            }

            $bill_id = get_post_meta($post_id, 'billplz_id', true);
            $api_key  =get_post_meta($post_id, 'billplz_api_key', true);
            $status  =get_post_meta($post_id, 'billplz_paid', true);

            if (empty($bill_id) || empty($api_key) || empty($status)) {
                return;
            }

            if ($status === 'true') {
                delete_post_meta($post_id, 'billplz_id');
                delete_post_meta($post_id, 'billplz_api_key');
                delete_post_meta($post_id, 'billplz_paid');
                return;
            }

            $billplz = new Billplz_API($api_key);
            if (!$billplz->deleteBill($bill_id)) {
                wp_die(__('Bill cannot be deleted. Deleting this posts has been prevented.', 'mycred'));
            }
        }
    }
}

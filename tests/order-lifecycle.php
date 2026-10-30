<?php
// Run only against an isolated development WordPress installation:
// wp eval-file wp-content/plugins/gelikon-abandoned-carts/tests/order-lifecycle.php
if (!defined('WP_CLI') || !WP_CLI) exit;
add_filter('pre_wp_mail', '__return_true');
global $wpdb;
$table=$wpdb->prefix.'gl_abandoned_carts';
$uid=wp_insert_user(['user_login'=>'gl-ac-test-'.wp_generate_uuid4(),'user_pass'=>wp_generate_password()]);
if(is_wp_error($uid)) throw new RuntimeException($uid->get_error_message());
wp_set_current_user($uid);
WC()->initialize_session(); WC()->initialize_cart();
$plugin=GL_Abandoned_Carts::instance();
$product=new WC_Product_Simple();$product->set_name('Lifecycle test');$product->set_regular_price(10);$pid=$product->save();
$orders=[];$row_ids=[];
$check=function($ok,$label){if(!$ok) throw new RuntimeException($label); echo "PASS: $label\n";};
$get=function($id)use($wpdb,$table){return $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id=%d",$id));};
$new_order=function()use(&$orders,$product){$o=wc_create_order();$orders[]=$o;$o->add_product($product,1);$o->calculate_totals();return $o;};
try {
 WC()->cart->add_to_cart($pid);WC()->cart->calculate_totals();
 // Simulate WooCommerce retaining its session data while replacing the guest customer ID on login.
 $original_session=WC()->session;
 $old_id=(int)WC()->session->get('gl_ac_cart_id');
 $migrated=new class extends WC_Session_Handler { public function get_customer_id(){ return 'migration-test-'.get_current_user_id(); } };
 $migrated->set('gl_ac_cart_id',$old_id);WC()->session=$migrated;
 $plugin->capture_cart();
 $check((int)$wpdb->get_var($wpdb->prepare("SELECT id FROM $table WHERE session_key=%s",$migrated->get_customer_id()))===$old_id,'login session migration retains original cart');
 $key=(string)WC()->session->get_customer_id();
 $id=(int)$wpdb->get_var($wpdb->prepare("SELECT id FROM $table WHERE session_key=%s",$key));$row_ids[]=$id;
 $wpdb->update($table,['status'=>'abandoned'],['id'=>$id]);
 $order=$new_order();
 do_action('woocommerce_checkout_create_order',$order,[]);$order->save();
 do_action('woocommerce_checkout_order_processed',$order->get_id(),[],$order);
 $check((int)$order->get_meta('_gl_ac_cart_id')===$id,'classic checkout stores persistent cart link');
 $check($get($id)->status==='ordered','returned abandoned cart awaits payment');
 $wpdb->update($table,['updated_at'=>'2000-01-01 00:00:00'],['id'=>$id]);
 $plugin->mark_abandoned();
 $check($get($id)->status==='ordered','unpaid placed order is not abandoned by cron');
 WC()->cart->empty_cart();$plugin->capture_cart();
 $check($get($id)->status==='ordered' && count(json_decode($get($id)->cart_contents,true))===1,'cart emptying preserves order snapshot');
 $session=WC()->session;WC()->session=null;
 $order->payment_complete('test');
 $check($get($id)->status==='completed','payment without browser session completes linked cart');
 do_action('woocommerce_payment_complete',$order->get_id());
 $check($get($id)->status==='completed','duplicate payment event is safe');
 WC()->session=$session;
 $plugin->capture_cart();$plugin->mark_abandoned();
 $check($get($id)->status==='completed','late capture and cron preserve completed cart');
 WC()->cart->add_to_cart($pid);WC()->cart->calculate_totals();
 $next=(int)$wpdb->get_var($wpdb->prepare("SELECT id FROM $table WHERE session_key=%s",$key));$row_ids[]=$next;
 $check($next!==$id && $get($id)->status==='completed' && $get($next)->status==='active','new purchase preserves previous history');
 $block=$new_order();do_action('woocommerce_store_api_checkout_order_processed',$block);
 $check((int)$block->get_meta('_gl_ac_cart_id')===$next && $get($next)->status==='ordered','block checkout links cart');
 $block->update_status('failed');
 $check($get($next)->status==='abandoned','failed order appears abandoned');
 $block->update_status('cancelled');
 $check($get($next)->status==='abandoned','cancelled order remains abandoned');
 $block->update_status('on-hold');
 $check($get($next)->status==='ordered','on-hold order awaits payment');
 $block->payment_complete('retry');
 $check($get($next)->status==='completed','successful payment retry completes cart');
 $order->update_status('processing');
 $check((int)$get($next)->completed_order_id===$block->get_id(),'old order callback does not affect newer cart');
 $block->update_status('refunded');
 $check($get($next)->status==='completed','refund is not treated as cart abandonment');
 // Reproduce a legacy row whose status was overwritten after payment.
 $wpdb->update($table,['status'=>'abandoned'],['id'=>$id]);
 $order->delete_meta_data('_gl_ac_cart_id');$order->save();
 $plugin->sync_order($order->get_id());
 $check($get($id)->status==='completed','legacy explicit order link repairs paid cart');
} finally {
 if(isset($session)) WC()->session=$session;
 WC()->cart->empty_cart();
 foreach($row_ids as $id) $wpdb->delete($table,['id'=>$id]);
 foreach($orders as $o) $o->delete(true);
 wp_delete_post($pid,true);
 if(isset($original_session)) WC()->session=$original_session;
 require_once ABSPATH.'wp-admin/includes/user.php';wp_delete_user($uid);
}

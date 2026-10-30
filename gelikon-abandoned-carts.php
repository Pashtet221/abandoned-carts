<?php
/**
 * Plugin Name: Gelikon Abandoned Carts
 * Description: Tracks WooCommerce carts and checkout data before order completion and shows abandoned carts in wp-admin.
 * Version: 1.1.0
 * Author: WPDevStudio
 * Requires PHP: 7.4
 */

if (!defined('ABSPATH')) exit;

final class GL_Abandoned_Carts {
    const VERSION = '1.1.0';
    const CRON = 'gl_ac_mark_abandoned';
    const NONCE = 'gl_ac_capture';
    private static $instance;

    public static function instance(){ return self::$instance ?: self::$instance = new self(); }
    private function __construct(){
        register_activation_hook(__FILE__, [$this,'activate']);
        register_deactivation_hook(__FILE__, [$this,'deactivate']);
        add_action('plugins_loaded', [$this,'init']);
    }

    public function init(){
        if (!class_exists('WooCommerce')) return;
        add_action('before_woocommerce_init', [$this,'declare_hpos']);
        add_action('wp_enqueue_scripts', [$this,'enqueue']);
        add_action('wp_ajax_gl_ac_capture', [$this,'ajax_capture']);
        add_action('wp_ajax_nopriv_gl_ac_capture', [$this,'ajax_capture']);
        add_action('woocommerce_add_to_cart', [$this,'new_cart']);
        add_action('woocommerce_after_cart_item_quantity_update', [$this,'capture_cart']);
        add_action('woocommerce_cart_item_removed', [$this,'capture_cart']);
        add_action('woocommerce_cart_emptied', [$this,'capture_cart']);
        add_action('woocommerce_checkout_order_processed', [$this,'order_processed'], 10, 3);
        add_action('woocommerce_checkout_create_order', [$this,'link_order']);
        add_action('woocommerce_store_api_checkout_order_processed', [$this,'block_order_processed']);
        add_action('woocommerce_payment_complete', [$this,'sync_order']);
        add_action('woocommerce_order_status_changed', [$this,'sync_order']);
        add_action(self::CRON, [$this,'mark_abandoned']);
        add_action('admin_menu', [$this,'menu']);
        add_action('admin_post_gl_ac_delete', [$this,'delete']);
        add_action('admin_post_gl_ac_bulk_delete', [$this,'bulk_delete']);
        add_action('admin_enqueue_scripts', [$this,'enqueue_admin']);
    }

    public function declare_hpos(){
        if (class_exists('\\Automattic\\WooCommerce\\Utilities\\FeaturesUtil')) {
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
        }
    }

    private function table(){ global $wpdb; return $wpdb->prefix.'gl_abandoned_carts'; }

    public function activate(){
        global $wpdb;
        require_once ABSPATH.'wp-admin/includes/upgrade.php';
        $table = $this->table();
        $charset = $wpdb->get_charset_collate();
        dbDelta("CREATE TABLE {$table} (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            session_key varchar(191) NOT NULL,
            user_id bigint unsigned NOT NULL DEFAULT 0,
            status varchar(30) NOT NULL DEFAULT 'active',
            customer_name varchar(191) NOT NULL DEFAULT '',
            email varchar(191) NOT NULL DEFAULT '',
            phone varchar(100) NOT NULL DEFAULT '',
            billing_address longtext NULL,
            shipping_address longtext NULL,
            cart_contents longtext NULL,
            cart_total decimal(18,2) NOT NULL DEFAULT 0,
            currency varchar(10) NOT NULL DEFAULT '',
            checkout_data longtext NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            abandoned_at datetime NULL,
            completed_order_id bigint unsigned NOT NULL DEFAULT 0,
            PRIMARY KEY (id), UNIQUE KEY session_key (session_key), KEY status (status), KEY updated_at (updated_at)
        ) {$charset};");
        add_option('gl_ac_timeout', 30);
        if (!wp_next_scheduled(self::CRON)) wp_schedule_event(time()+300, 'hourly', self::CRON);
    }

    public function deactivate(){ if ($t=wp_next_scheduled(self::CRON)) wp_unschedule_event($t,self::CRON); }

    public function enqueue(){
        if (is_admin() || (!is_cart() && !is_checkout())) return;
        wp_enqueue_script('gl-ac', plugin_dir_url(__FILE__).'assets/js/capture.js', ['jquery'], self::VERSION, true);
        wp_localize_script('gl-ac','GLAC',[
            'ajax'=>admin_url('admin-ajax.php'),
            'nonce'=>wp_create_nonce(self::NONCE),
            'delay'=>800,
        ]);
    }

    public function ajax_capture(){
        check_ajax_referer(self::NONCE,'nonce');
        $data=[];
        if (isset($_POST['checkout_data'])) parse_str(wp_unslash($_POST['checkout_data']),$data);
        $this->save($data);
        wp_send_json_success();
    }

    public function capture_cart(){ $this->save([]); }

    private function session_key(){
        if (!function_exists('WC') || !WC()->session) return '';
        $id = WC()->session->get_customer_id();
        return $id ? (string)$id : wp_hash((string)wp_get_session_token().'|'.($_SERVER['REMOTE_ADDR'] ?? '').'|'.($_SERVER['HTTP_USER_AGENT'] ?? ''));
    }

    private function val($data,$key){ return isset($data[$key]) ? sanitize_text_field(wp_unslash($data[$key])) : ''; }
    private function current_cart(){
        global $wpdb;
        $session=$this->session_key();
        if(!$session) return null;
        $row=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->table()} WHERE session_key=%s",$session));
        // WooCommerce carries session data across guest-to-account login.
        $id=absint(WC()->session->get('gl_ac_cart_id'));
        return $row ?: ($id ? $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->table()} WHERE id=%d",$id)) : null);
    }

    private function clean($value){
        if (is_array($value)){ $out=[]; foreach($value as $k=>$v) $out[sanitize_key((string)$k)]=$this->clean($v); return $out; }
        return is_scalar($value) ? sanitize_text_field((string)$value) : '';
    }

    private function save(array $checkout){
        if (!function_exists('WC') || !WC()->session || !WC()->cart) return;
        global $wpdb;
        $session = $this->session_key(); if (!$session) return;
        $existing=$this->current_cart();
        // A checkout snapshot belongs to its order, not to subsequent AJAX requests.
        if($existing && ((int)$existing->completed_order_id || $existing->status==='completed')) return;
        if($existing) $checkout=array_merge(json_decode($existing->checkout_data,true) ?: [],$checkout);
        $items=[];
        foreach(WC()->cart->get_cart() as $item){
            $product = $item['data'] ?? null; if (!$product) continue;
            $items[]=[
                'product_id'=>(int)$item['product_id'], 'variation_id'=>(int)$item['variation_id'],
                'name'=>$product->get_name(), 'sku'=>$product->get_sku(), 'quantity'=>(int)$item['quantity'],
                'line_total'=>(float)($item['line_total'] ?? 0), 'url'=>get_permalink($item['product_id'])
            ];
        }
        $first=$this->val($checkout,'billing_first_name'); $last=$this->val($checkout,'billing_last_name');
        $email=sanitize_email($this->val($checkout,'billing_email')); $phone=$this->val($checkout,'billing_phone');
        $billing=[
            'first_name'=>$first,'last_name'=>$last,'city'=>$this->val($checkout,'billing_city'),
            'postcode'=>$this->val($checkout,'billing_postcode'),'address_1'=>$this->val($checkout,'billing_address_1'),
            'address_2'=>$this->val($checkout,'billing_address_2'),'country'=>$this->val($checkout,'billing_country'),
            'state'=>$this->val($checkout,'billing_state')
        ];
        $shipping=[
            'first_name'=>$this->val($checkout,'shipping_first_name'),'last_name'=>$this->val($checkout,'shipping_last_name'),
            'city'=>$this->val($checkout,'shipping_city'),'postcode'=>$this->val($checkout,'shipping_postcode'),
            'address_1'=>$this->val($checkout,'shipping_address_1'),'address_2'=>$this->val($checkout,'shipping_address_2'),
            'country'=>$this->val($checkout,'shipping_country'),'state'=>$this->val($checkout,'shipping_state')
        ];
        $now=current_time('mysql');
        $row=[
            'session_key'=>$session,'user_id'=>get_current_user_id(),'status'=>$items?'active':'empty',
            'customer_name'=>trim($first.' '.$last),'email'=>$email,'phone'=>$phone,
            'billing_address'=>wp_json_encode($billing,JSON_UNESCAPED_UNICODE),'shipping_address'=>wp_json_encode($shipping,JSON_UNESCAPED_UNICODE),
            'cart_contents'=>wp_json_encode($items,JSON_UNESCAPED_UNICODE),'cart_total'=>(float)WC()->cart->get_total('edit'),
            'currency'=>get_woocommerce_currency(),'checkout_data'=>wp_json_encode($this->clean($checkout),JSON_UNESCAPED_UNICODE),
            'updated_at'=>$now,'abandoned_at'=>null
        ];
        if($existing) {
            // Do not overwrite an order linked by another request after our initial read.
            $wpdb->update($this->table(),$row,['id'=>(int)$existing->id,'completed_order_id'=>0,'status'=>$existing->status]);
            WC()->session->set('gl_ac_cart_id',(int)$existing->id);
        } else {
            $row['created_at']=$now;
            if($wpdb->insert($this->table(),$row)) WC()->session->set('gl_ac_cart_id',(int)$wpdb->insert_id);
        }
    }

    public function mark_abandoned(){
        global $wpdb;
        // Repair older linked records too, in bounded batches without requiring a browser session.
        $cursor=absint(get_option('gl_ac_reconcile_cursor',0));
        $rows=$wpdb->get_results($wpdb->prepare("SELECT id, completed_order_id FROM {$this->table()} WHERE completed_order_id>0 AND id>%d ORDER BY id LIMIT 100",$cursor));
        foreach($rows as $row) $this->sync_order((int)$row->completed_order_id);
        update_option('gl_ac_reconcile_cursor',count($rows)===100 ? (int)end($rows)->id : 0,false);
        $minutes=max(5,absint(get_option('gl_ac_timeout',30)));
        $cutoff=date_i18n('Y-m-d H:i:s', current_time('timestamp')-$minutes*MINUTE_IN_SECONDS);
        $wpdb->query($wpdb->prepare("UPDATE {$this->table()} SET status='abandoned', abandoned_at=%s WHERE status='active' AND updated_at < %s AND cart_contents <> '[]'", current_time('mysql'), $cutoff));
    }

    public function new_cart(){
        global $wpdb;
        $session=$this->session_key();
        if(!$session) return;
        // Free the unique session key while retaining the previous purchase's history.
        $row=$this->current_cart();
        if($row && ((int)$row->completed_order_id || $row->status==='completed')) {
            $wpdb->update($this->table(),['session_key'=>'history:'.$row->id],['id'=>(int)$row->id]);
            WC()->session->set('gl_ac_cart_id',null);
        }
        $this->capture_cart();
    }

    public function link_order($order){
        if(!$order instanceof WC_Order || $order->get_meta('_gl_ac_cart_id')) return;
        global $wpdb;
        $session=$this->session_key();
        if(!$session) return;
        $this->capture_cart();
        $row=$this->current_cart();
        if($row && (!(int)$row->completed_order_id || (int)$row->completed_order_id===$order->get_id())) {
            $order->update_meta_data('_gl_ac_cart_id',(int)$row->id);
        }
    }

    public function order_processed($order_id,$posted_data=[],$order=null){
        $order=$order instanceof WC_Order ? $order : wc_get_order($order_id);
        if(!$order) return;
        $this->link_order($order);
        $order->save();
        $this->sync_order($order->get_id());
    }

    public function block_order_processed($order){
        $this->order_processed($order->get_id(),[],$order);
    }

    public function sync_order($order_id){
        $order=wc_get_order($order_id);
        if(!$order || $order->has_status(['checkout-draft','auto-draft'])) return;
        global $wpdb;
        $id=absint($order->get_meta('_gl_ac_cart_id'));
        if($id) {
            $row=$wpdb->get_row($wpdb->prepare("SELECT id, completed_order_id FROM {$this->table()} WHERE id=%d",$id));
            if(!$row || ((int)$row->completed_order_id && (int)$row->completed_order_id!==(int)$order_id)) return;
        } else {
            // Legacy orders have no metadata; only use an explicit existing order link.
            $id=(int)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$this->table()} WHERE completed_order_id=%d",$order_id));
        }
        if(!$id) return;
        $status=$order->is_paid() || $order->has_status('refunded') ? 'completed' : ($order->has_status(['failed','cancelled']) ? 'abandoned' : 'ordered');
        $wpdb->update($this->table(),[
            'status'=>$status,'completed_order_id'=>(int)$order_id,
            'updated_at'=>current_time('mysql'),
            'abandoned_at'=>$status==='abandoned' ? current_time('mysql') : null,
        ],['id'=>$id]);
    }

    public function menu(){
        $count=$this->abandoned_count();
        $menu_title='Брошенные корзины';
        if($count>0){
            $formatted_count=number_format_i18n($count);
            $badge_style='display:inline-flex;align-items:center;justify-content:center;box-sizing:border-box;min-width:18px;height:18px;padding:0 5px;margin-left:5px;border-radius:9px;background:#2271b1;color:#fff;font-size:11px;font-weight:600;line-height:18px;vertical-align:middle;';
            $menu_title.=' <span class="awaiting-mod count-'.(int)$count.'" style="'.esc_attr($badge_style).'"><span class="pending-count" aria-hidden="true">'.esc_html($formatted_count).'</span><span class="screen-reader-text">'.esc_html(sprintf('Количество брошенных корзин: %s',$formatted_count)).'</span></span>';
        }
        add_submenu_page('woocommerce','Брошенные корзины',$menu_title,'manage_woocommerce','gl-abandoned-carts',[$this,'page']);
    }

    private function abandoned_count(){
        global $wpdb;
        return (int)$wpdb->get_var("SELECT COUNT(*) FROM {$this->table()} WHERE status='abandoned'");
    }

    private function checkout_label($key){
        $labels=[
            'billing_first_name'=>'Имя','billing_last_name'=>'Фамилия','billing_company'=>'Компания',
            'billing_country'=>'Страна (платёжный адрес)','billing_state'=>'Регион (платёжный адрес)',
            'billing_city'=>'Город (платёжный адрес)','billing_postcode'=>'Индекс (платёжный адрес)',
            'billing_address_1'=>'Адрес плательщика','billing_address_2'=>'Дополнение к адресу плательщика',
            'billing_phone'=>'Телефон','billing_email'=>'Электронная почта',
            'shipping_first_name'=>'Имя получателя','shipping_last_name'=>'Фамилия получателя',
            'shipping_company'=>'Компания получателя','shipping_country'=>'Страна доставки',
            'shipping_state'=>'Регион доставки','shipping_city'=>'Город доставки',
            'shipping_postcode'=>'Индекс доставки','shipping_address_1'=>'Адрес доставки',
            'shipping_address_2'=>'Дополнение к адресу доставки','ship_to_different_address'=>'Доставка по другому адресу',
            'shipping_method'=>'Способ доставки','payment_method'=>'Способ оплаты',
            'order_comments'=>'Комментарий к заказу','coupon_code'=>'Промокод',
        ];
        if(isset($labels[$key])) return $labels[$key];
        $label=str_replace(['billing_','shipping_'],['',''],$key);
        return ucfirst(str_replace(['_','-'],' ',$label));
    }

    private function checkout_value($key,$value){
        if(is_array($value)){
            $values=[];
            foreach($value as $item){
                $formatted=$this->checkout_value($key,$item);
                if($formatted!=='') $values[]=$formatted;
            }
            return implode(', ',$values);
        }
        $value=trim((string)$value);
        if($value==='') return '';
        if($key==='ship_to_different_address') return $value==='1' ? 'Да' : 'Нет';
        if($key==='payment_method' && function_exists('WC')){
            $gateways=WC()->payment_gateways() ? WC()->payment_gateways()->payment_gateways() : [];
            if(isset($gateways[$value])) return $gateways[$value]->get_title();
        }
        if($key==='shipping_method' && preg_match('/:(\d+)$/',$value,$match) && class_exists('WC_Shipping_Zones')){
            $method=WC_Shipping_Zones::get_shipping_method((int)$match[1]);
            if($method) return $method->get_title();
        }
        if(in_array($key,['billing_country','shipping_country'],true) && function_exists('WC') && WC()->countries){
            $countries=WC()->countries->get_countries();
            if(isset($countries[$value])) return $countries[$value];
        }
        return $value;
    }

    private function render_checkout_data(array $checkout){
        $hidden=['_wpnonce','_wp_http_referer','woocommerce-process-checkout-nonce','terms-field'];
        echo '<details class="gl-ac-checkout"><summary>Введённые данные</summary><dl style="margin:8px 0 0;display:grid;grid-template-columns:max-content minmax(120px,1fr);gap:4px 12px;max-width:640px">';
        $shown=false;
        foreach($checkout as $key=>$raw){
            $key=(string)$key;
            if(in_array($key,$hidden,true) || strpos($key,'wc_order_attribution_')===0) continue;
            $value=$this->checkout_value($key,$raw);
            if($value==='') continue;
            echo '<dt style="font-weight:600">'.esc_html($this->checkout_label($key)).':</dt><dd style="margin:0;overflow-wrap:anywhere">'.esc_html($value).'</dd>';
            $shown=true;
        }
        if(!$shown) echo '<dt>Нет дополнительных данных</dt>';
        echo '</dl></details>';
    }

    public function enqueue_admin($hook){
        if($hook!=='woocommerce_page_gl-abandoned-carts') return;
        wp_enqueue_script('gl-ac-admin', plugin_dir_url(__FILE__).'assets/js/admin.js', [], self::VERSION, true);
    }

    public function page(){
        if(!current_user_can('manage_woocommerce')) wp_die('Forbidden');
        global $wpdb;
        $status=isset($_GET['status'])?sanitize_key($_GET['status']):'abandoned';
        $allowed=['abandoned','active','ordered','completed','all']; if(!in_array($status,$allowed,true)) $status='abandoned';
        $rows=$status==='all' ? $wpdb->get_results("SELECT * FROM {$this->table()} ORDER BY updated_at DESC LIMIT 200") : $wpdb->get_results($wpdb->prepare("SELECT * FROM {$this->table()} WHERE status=%s ORDER BY updated_at DESC LIMIT 200",$status));
        echo '<div class="wrap"><h1>Брошенные корзины</h1><p>Корзина считается брошенной после '.esc_html(get_option('gl_ac_timeout',30)).' минут без активности.</p><p>';
        foreach(['abandoned'=>'Брошенные','active'=>'Активные','ordered'=>'Ожидают оплаты','completed'=>'Завершённые','all'=>'Все'] as $k=>$label){
            $url=admin_url('admin.php?page=gl-abandoned-carts&status='.$k); echo '<a class="button '.($k===$status?'button-primary':'').'" href="'.esc_url($url).'">'.esc_html($label).'</a> ';
        }
        echo '</p><form id="gl-ac-bulk-form" method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
        echo '<input type="hidden" name="action" value="gl_ac_bulk_delete"><input type="hidden" name="status" value="'.esc_attr($status).'">';
        wp_nonce_field('gl_ac_bulk_delete');
        echo '<p><button type="submit" class="button" id="gl-ac-delete-selected" disabled>Удалить выбранные</button> <span>«Выбрать все» отмечает только показанные записи (до 200).</span></p>';
        echo '<table class="widefat striped"><thead><tr><td class="check-column"><input type="checkbox" id="gl-ac-select-all" aria-label="Выбрать все показанные корзины"></td><th>ID</th><th>Покупатель</th><th>Контакты</th><th>Корзина</th><th>Сумма</th><th>Статус</th><th>Последняя активность</th><th></th></tr></thead><tbody>';
        if(!$rows) echo '<tr><td colspan="9">Записей нет.</td></tr>';
        foreach($rows as $r){
            $items=json_decode($r->cart_contents,true) ?: []; $checkout=json_decode($r->checkout_data,true) ?: [];
            echo '<tr><th scope="row" class="check-column"><input type="checkbox" name="cart_ids[]" value="'.(int)$r->id.'" aria-label="Выбрать корзину #'.(int)$r->id.'"></th><td>#'.(int)$r->id.'</td><td><strong>'.esc_html($r->customer_name ?: 'Гость').'</strong></td><td>';
            echo $r->phone?'<div>'.esc_html($r->phone).'</div>':''; echo $r->email?'<div>'.esc_html($r->email).'</div>':''; if(!$r->phone&&!$r->email) echo '—';
            echo '</td><td>';
            foreach($items as $it){ echo '<div><a target="_blank" href="'.esc_url($it['url'] ?? '#').'">'.esc_html($it['name'] ?? 'Товар').'</a> × '.(int)($it['quantity']??1).'</div>'; }
            if($checkout) $this->render_checkout_data($checkout);
            echo '</td><td>'.wp_kses_post(wc_price((float)$r->cart_total,['currency'=>$r->currency ?: get_woocommerce_currency()])).'</td><td>'.esc_html($r->status).'</td><td>'.esc_html($r->updated_at).'</td><td>';
            $del=wp_nonce_url(admin_url('admin-post.php?action=gl_ac_delete&id='.(int)$r->id),'gl_ac_delete_'.(int)$r->id);
            echo '<a href="'.esc_url($del).'" onclick="return confirm(\'Удалить запись?\')">Удалить</a></td></tr>';
        }
        echo '</tbody></table></form></div>';
    }

    public function bulk_delete(){
        if(!current_user_can('manage_woocommerce')) wp_die('Forbidden', '', ['response'=>403]);
        if(($_SERVER['REQUEST_METHOD'] ?? '')!=='POST') wp_die('Method not allowed', '', ['response'=>405]);
        check_admin_referer('gl_ac_bulk_delete');
        $raw=isset($_POST['cart_ids']) && is_array($_POST['cart_ids']) ? wp_unslash($_POST['cart_ids']) : [];
        $ids=[];
        foreach($raw as $id){
            if(is_scalar($id) && ctype_digit((string)$id) && (int)$id>0) $ids[]=(int)$id;
        }
        $ids=array_values(array_unique($ids));
        global $wpdb;
        foreach(array_chunk($ids,200) as $chunk){
            $placeholders=implode(',',array_fill(0,count($chunk),'%d'));
            $wpdb->query($wpdb->prepare("DELETE FROM {$this->table()} WHERE id IN ($placeholders)",$chunk));
        }
        $status=isset($_POST['status']) && is_string($_POST['status']) ? sanitize_key(wp_unslash($_POST['status'])) : 'abandoned';
        if(!in_array($status,['abandoned','active','ordered','completed','all'],true)) $status='abandoned';
        wp_safe_redirect(admin_url('admin.php?page=gl-abandoned-carts&status='.$status));
        exit;
    }

    public function delete(){
        if(!current_user_can('manage_woocommerce')) wp_die('Forbidden');
        $id=absint($_GET['id']??0); check_admin_referer('gl_ac_delete_'.$id);
        global $wpdb; $wpdb->delete($this->table(),['id'=>$id],['%d']);
        wp_safe_redirect(wp_get_referer() ?: admin_url('admin.php?page=gl-abandoned-carts')); exit;
    }
}
GL_Abandoned_Carts::instance();

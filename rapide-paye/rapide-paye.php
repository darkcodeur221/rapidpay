<?php
/**
 * Plugin Name: Rapide Paye
 * Plugin URI: https://github.com/darkcodeur221/rapidpay
 * Description: Remplace le checkout WooCommerce par un parcours simplifié avec Orange Money et Wave.
 * Version: 1.1.0
 * Author: Rapide Paye
 * Text Domain: rapide-paye
 * Requires at least: 6.4
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 */

if (!defined('ABSPATH')) {
    exit;
}

final class Rapide_Paye_Plugin
{
    private const META_MOBILE_ACCOUNT = '_rp_payment_phone';
    private const GATEWAYS = ['rapide_orange_money', 'rapide_wave'];

    public function __construct()
    {
        add_action('plugins_loaded', [$this, 'init'], 20);
    }

    public function init(): void
    {
        if (!class_exists('WooCommerce')) {
            add_action('admin_notices', [$this, 'woocommerce_missing_notice']);
            return;
        }

        add_filter('woocommerce_checkout_fields', [$this, 'simplify_checkout_fields'], 999);
        add_filter('woocommerce_available_payment_gateways', [$this, 'limit_payment_methods']);
        add_filter('woocommerce_cart_needs_shipping_address', '__return_false', 999);
        add_action('woocommerce_checkout_process', [$this, 'validate_minimal_fields']);
        add_action('woocommerce_checkout_create_order', [$this, 'save_custom_data_on_order'], 10, 2);
        add_action('woocommerce_checkout_order_processed', [$this, 'notify_admin'], 10, 3);
        add_filter('woocommerce_email_order_meta_fields', [$this, 'add_phone_to_emails'], 10, 3);
        add_filter('woocommerce_order_button_text', [$this, 'change_place_order_text']);
        add_action('woocommerce_before_checkout_form', [$this, 'checkout_intro']);

        add_action('woocommerce_payment_gateways', [$this, 'register_gateways']);
    }

    public function woocommerce_missing_notice(): void
    {
        echo '<div class="notice notice-error"><p><strong>Rapide Paye:</strong> WooCommerce est requis.</p></div>';
    }

    public function simplify_checkout_fields(array $fields): array
    {
        $fields['billing'] = [
            'billing_first_name' => [
                'type' => 'text',
                'label' => __('Nom complet', 'rapide-paye'),
                'required' => true,
                'class' => ['form-row-wide'],
                'priority' => 10,
            ],
            'billing_phone' => [
                'type' => 'tel',
                'label' => __('Téléphone de contact', 'rapide-paye'),
                'required' => true,
                'class' => ['form-row-wide'],
                'priority' => 20,
            ],
            'billing_email' => [
                'type' => 'email',
                'label' => __('E-mail (optionnel)', 'rapide-paye'),
                'required' => false,
                'class' => ['form-row-wide'],
                'priority' => 30,
            ],
            'rp_payment_phone' => [
                'type' => 'text',
                'label' => __('Numéro Orange Money / Wave', 'rapide-paye'),
                'required' => true,
                'class' => ['form-row-wide'],
                'priority' => 40,
                'placeholder' => __('Ex: 77 000 00 00', 'rapide-paye'),
            ],
        ];

        $fields['shipping'] = [];
        $fields['account'] = [];
        $fields['order'] = [];

        return $fields;
    }

    public function validate_minimal_fields(): void
    {
        $payment_method = isset($_POST['payment_method']) ? wc_clean(wp_unslash($_POST['payment_method'])) : '';
        $phone = isset($_POST['rp_payment_phone']) ? preg_replace('/\s+/', '', wc_clean(wp_unslash($_POST['rp_payment_phone']))) : '';

        if (in_array($payment_method, self::GATEWAYS, true) && $phone === '') {
            wc_add_notice(__('Veuillez renseigner le numéro du compte mobile money.', 'rapide-paye'), 'error');
        }

        if ($phone !== '' && !preg_match('/^[0-9+]{7,15}$/', $phone)) {
            wc_add_notice(__('Le numéro mobile money semble invalide.', 'rapide-paye'), 'error');
        }
    }

    public function save_custom_data_on_order(WC_Order $order, array $data): void
    {
        if (!isset($_POST['rp_payment_phone'])) {
            return;
        }

        $phone = preg_replace('/\s+/', '', wc_clean(wp_unslash($_POST['rp_payment_phone'])));
        if (!empty($phone)) {
            $order->update_meta_data(self::META_MOBILE_ACCOUNT, $phone);
        }
    }

    public function limit_payment_methods(array $gateways): array
    {
        if (!is_checkout() || is_wc_endpoint_url()) {
            return $gateways;
        }

        foreach (array_keys($gateways) as $gateway_id) {
            if (!in_array($gateway_id, self::GATEWAYS, true)) {
                unset($gateways[$gateway_id]);
            }
        }

        return $gateways;
    }

    public function change_place_order_text(string $text): string
    {
        return is_checkout() ? __('Valider la commande', 'rapide-paye') : $text;
    }

    public function checkout_intro(): void
    {
        if (!is_checkout() || is_wc_endpoint_url()) {
            return;
        }

        echo '<div class="woocommerce-info">' . esc_html__('Paiement rapide : ajoutez vos infos minimales puis choisissez Orange Money ou Wave.', 'rapide-paye') . '</div>';
    }

    public function notify_admin(int $order_id, array $posted_data, WC_Order $order): void
    {
        if (!in_array($order->get_payment_method(), self::GATEWAYS, true)) {
            return;
        }

        $admin_email = sanitize_email((string) get_option('admin_email'));
        if (empty($admin_email)) {
            return;
        }

        $phone = (string) $order->get_meta(self::META_MOBILE_ACCOUNT, true);
        $subject = sprintf(__('Nouvelle commande Rapide Paye #%d', 'rapide-paye'), $order_id);

        $lines = [
            __('Une nouvelle commande a été passée.', 'rapide-paye'),
            '',
            'Commande: #' . $order->get_order_number(),
            'Montant: ' . wp_strip_all_tags($order->get_formatted_order_total()),
            'Paiement: ' . $order->get_payment_method_title(),
            'Client: ' . $order->get_formatted_billing_full_name(),
            'Téléphone: ' . $order->get_billing_phone(),
            'E-mail: ' . $order->get_billing_email(),
            'Compte mobile money: ' . $phone,
            '',
            'Détails articles:',
        ];

        foreach ($order->get_items() as $item) {
            $lines[] = '- ' . $item->get_name() . ' x ' . $item->get_quantity();
        }

        wp_mail($admin_email, $subject, implode("\n", $lines));
    }

    public function add_phone_to_emails(array $fields, bool $sent_to_admin, WC_Order $order): array
    {
        $phone = (string) $order->get_meta(self::META_MOBILE_ACCOUNT, true);
        if ($phone !== '') {
            $fields['rp_payment_phone'] = [
                'label' => __('Compte Mobile Money', 'rapide-paye'),
                'value' => $phone,
            ];
        }

        return $fields;
    }

    public function register_gateways(array $methods): array
    {
        if (!class_exists('WC_Payment_Gateway')) {
            return $methods;
        }

        $methods[] = 'WC_Gateway_Rapide_Orange_Money';
        $methods[] = 'WC_Gateway_Rapide_Wave';

        return $methods;
    }
}

abstract class WC_Gateway_Rapide_Abstract extends WC_Payment_Gateway
{
    protected string $default_title = '';
    protected string $default_description = '';
    protected string $default_instructions = '';

    public function __construct()
    {
        $this->has_fields = false;

        $this->init_form_fields();
        $this->init_settings();

        $this->title = (string) $this->get_option('title', $this->default_title);
        $this->description = (string) $this->get_option('description', $this->default_description);
        $this->instructions = (string) $this->get_option('instructions', $this->default_instructions);
        $this->enabled = (string) $this->get_option('enabled', 'yes');

        add_action('woocommerce_update_options_payment_gateways_' . $this->id, [$this, 'process_admin_options']);
        add_action('woocommerce_thankyou_' . $this->id, [$this, 'thankyou_page']);
    }

    public function init_form_fields(): void
    {
        $this->form_fields = [
            'enabled' => ['title' => 'Activer/Désactiver', 'type' => 'checkbox', 'label' => 'Activer ce paiement', 'default' => 'yes'],
            'title' => ['title' => 'Titre', 'type' => 'text', 'default' => $this->default_title],
            'description' => ['title' => 'Description', 'type' => 'textarea', 'default' => $this->default_description],
            'instructions' => ['title' => 'Instructions', 'type' => 'textarea', 'default' => $this->default_instructions],
        ];
    }

    public function process_payment($order_id): array
    {
        $order = wc_get_order($order_id);
        if (!$order) {
            wc_add_notice(__('Commande introuvable.', 'rapide-paye'), 'error');
            return ['result' => 'failure'];
        }

        $order->update_status('on-hold', __('En attente de confirmation du paiement mobile money.', 'rapide-paye'));
        $order->reduce_order_stock();
        WC()->cart->empty_cart();

        return [
            'result' => 'success',
            'redirect' => $this->get_return_url($order),
        ];
    }

    public function thankyou_page(): void
    {
        if (!empty($this->instructions)) {
            echo wp_kses_post(wpautop($this->instructions));
        }
    }
}

if (class_exists('WC_Payment_Gateway')) {
    class WC_Gateway_Rapide_Orange_Money extends WC_Gateway_Rapide_Abstract
    {
        public function __construct()
        {
            $this->id = 'rapide_orange_money';
            $this->method_title = 'Orange Money (Rapide Paye)';
            $this->method_description = 'Paiement manuel Orange Money';
            $this->default_title = 'Orange Money';
            $this->default_description = 'Payez facilement avec Orange Money.';
            $this->default_instructions = 'Votre commande est enregistrée. Nous vous contacterons pour finaliser le paiement Orange Money.';
            parent::__construct();
        }
    }

    class WC_Gateway_Rapide_Wave extends WC_Gateway_Rapide_Abstract
    {
        public function __construct()
        {
            $this->id = 'rapide_wave';
            $this->method_title = 'Wave (Rapide Paye)';
            $this->method_description = 'Paiement manuel Wave';
            $this->default_title = 'Wave';
            $this->default_description = 'Payez facilement avec Wave.';
            $this->default_instructions = 'Votre commande est enregistrée. Nous vous contacterons pour finaliser le paiement Wave.';
            parent::__construct();
        }
    }
}

new Rapide_Paye_Plugin();

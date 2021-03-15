<?php

/**
 * Plugin Name: WooCommerce BA Packs
 * Author: Lèmur
 */

if (!defined("ABSPATH")) {
	return;
}

class WCBA_Packs {

	/**
	 * Build the instance
	 */
	public function __construct () {
		add_action("woocommerce_loaded", array(
			$this,
			"load_plugin"
		));

		add_filter("product_type_selector", array(
			$this,
			"add_type"
		));

		register_activation_hook(__FILE__, array(
			$this,
			"install"
		));

		add_filter("woocommerce_data_stores", function ($stores){
    			$stores["product-wcba_pack"] = "WC_Product_Variable_Data_Store_CPT";
    			return $stores;
		});

		add_action("admin_enqueue_scripts", array(
			$this,
			"enqueue_scripts"
		));

		add_action("woocoomerce_product_options_general_product_data", function () {
			echo "<div class=\"options_group show_if_pack clear\"></div>";
		});

		add_filter("woocommerce_product_data_tabs", array(
			$this,
			"add_product_tab"
		), 50);

		add_action("woocommerce_product_data_panels", array(
			$this,
			"add_product_tab_content"
		));

		add_action("wp_ajax_wcba_pack_update", array(
			$this,
			"on_pack_update"
		));

		add_action("woocommerce_update_product", array(
			$this,
			"save_pack_settings"
		));

		add_action("woocommerce_thankyou", array(
			$this,
			"on_thankyou"
		));

		add_filter("woocommerce_locate_template", array(
			$this,
			"locate_template"
		));
	}

	/**
	 * Load WC Dependencies
	 *
	 * @return void
	 */
	public function load_plugin () {
		require_once "includes/class-wcba-product-pack.php";
		require_once "wcba-rest.php";
	}

	/**
	 * Advanced Type
	 *
	 * @param array $types
	 * @return void
	 */
	public function add_type ($types) {
		$types["wcba_pack"] = __("Pack Product");
		return $types;
	}

	/**
	 * Installing on activation
	 * @return void
	 */
	public function install () {
		// If there is no pack product type taxonomy, add it.
		if (!get_term_by("slug", "wcba_pack", "product_type")) {
			wp_insert_term("wcba_pack", "product_type");
		}
	}

	public function enqueue_scripts ($hook) {
		GLOBAL $post;
		if ($hook !== "post.php" && $hook !== "post-new.php") {
			return;
		}

		wp_enqueue_script(
			"wcba-packs-script",
			plugins_url(
				"js/wcba-packs.js",
				__FILE__
			),
			array("jquery"),
			"1.0.0",
			true
		);

		wp_localize_script(
			"wcba-packs-script",
			"my_ajax_obj",
			array(
				"ajax_url" => admin_url("admin-ajax.php"),
				"nonce" => wp_create_nonce("wcba-pack-options"),
				"post_id" => $post->ID
			)
		);
	}

	/**
	 * Add Experience 	
	 * 
	 * @param array @tabs
	 * @return array @tabs
	 */
	public function add_product_tab ($tabs) {
		$tabs["wcba_pack"] = array(
			"label" => __("Pack Options"),
			"target" => "wcba_pack_options",
			"class" => "show_if_wcba_pack"
		);


		$tabs["variations"]["class"][] = "show_if_wcba_pack";

		return $tabs;
	}

	/**
	 * Add Content to Product Tab
	 *
	 * @return void
	 */
	public function add_product_tab_content () {
		global $product_object;
		?>
		<div id="wcba_pack_options" class="panel woocommerce_options_panel hidden">
			<div class="options_group">
			<?php
			$relateds = "";
			if ($product_object) {
				if ($product_object->is_type("wcba_pack")) {
					$relateds = $product_object->get_relateds();
				}
			}

			woocommerce_wp_text_input(array(
				"id" => "wcba_pack_relateds",
				"label" => __("Related Products"),
				"value" => $relateds,
				"default" => "",
				"placeholder" => "1|2|3"
			));

			$products = wc_get_products(array(
				"type" => array(
					"simple",
					"variable"
				)
			));

			$select_options = array();
			foreach ($products as $product) {
				$select_options[$product->get_ID()] = $product->get_name();
			}
			?>
			<p class="form-field wcba_pack_store_products_field">
				<label for="wcba_pack_store_productes">Store Products</label>
				<select id="wcba_pack_store_products" class="select short" multiple>
				<?php
					foreach ($select_options as $id => $name) {
						echo "<option value=\"".$id."\">".$name."</option>";
					}
				?>
				</select>
			</p>
			<?php
			/*$discount = 0;
			if ($product_object) {
				if ($product_object->is_type("wcba_pack")) {
					$discount = $product_object->get_discount();
				}
			}

			woocommerce_wp_text_input(array(
				"id" => "wcba_pack_discount",
				"label" => __("Discount percentage"),
				"value" => $discount,
				"default" => 0,
				"placeholder" => "add a percentage"
			));*/
			?>
			</div>
			<div class="toolbar" style="padding: 10px 20px;">
				<button id="wcba_pack_update" type="button" disabled class="button button-primary">Save data</button>
			</div>
		</div>
		<?php
	}

	/**
	 * Save pack metadata on product creation
	 *
	 * @param integer $post_id
	 * @return void
	 */
	public function save_pack_settings ($product_id) {
		$product = wc_get_product($product_id);
		
		if ($product && $product->is_type("wcba_pack")) {
			$relateds = isset($_POST["_pack_relateds"]) ? sanitize_text_field($_POST["_pack_relateds"]) : "";
			$product->set_relateds($relateds);

			$discount = isset($_POST["_pack_discount"]) && is_numeric($_POST["_pack_discount"]) ? absint($_POST["_pack_discount"]) : 0;
			$product->set_discount($discount);
		}
	}

	public function on_pack_update () {
		check_ajax_referer("wcba-pack-options");

		$post_id = $_POST["_pack_id"];

		$this->save_pack_settings($post_id);

		$product = wc_get_product($post_id);
		echo json_encode(array("success" => true));

		wp_die();
	}

	/**
	 * Synchronize related products stock after checkout
	 *
	 * @param integer $order_id
	 * @return void
	 */
	private function on_thankyou ($order_id) {
		if (!$order_id) {
			return;
		}

		$order = wc_get_order($order_id);

		foreach ($order->get_items() as $item_id => $item) {
			$product = $item->get_product();

			if ($product->is_type("wcba_pack")) {
				$qty = $item->get_quantity();
				$data = $product->get_data();
				$relateds = $product->get_relateds(true);
				echo print_r($relateds);
			}
		}
	}

	/**
	 * Log messages
	 *
	 * @param string @message
	 * @return void
	 */
	private function log ($message) {
		$log_path = dirname(__FILE__)."/log";
		file_put_contents($log_path."/log.txt", $data."\n", FILE_APPEND);
	}

	public function locate_template ($template_name, $template_path="", $default_path="") {
		$file = basename($template_name);
		$template = str_replace("plugins\/woocommerce\/templates", "plugins/wcba_packs/templates", $template_name);
		echo $template_name . "\n";
		return $template_name;
		$_template = $template;
		$template_path = $woocommerce->template_url;

		$plugin_path = untrailingslashit( plugin_dir_path( __FILE__ ) )  . '/template/woocommerce/';
		// Look within passed path within the theme - this is priority
    		$template = locate_template(array(
      			$template_path . $template_name,
      			$template_name
    		));

   		if (!$template && file_exists($plugin_path . $template_name)) {
    			$template = $plugin_path . $template_name;
		}

   		if (!$template) {
    			$template = $_template;
		}

   		return $template;
	}
}

new WCBA_Packs ();

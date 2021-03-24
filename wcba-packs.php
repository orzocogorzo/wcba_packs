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

		add_action("woocommerce_wcba_pack_add_to_cart", array(
			$this,
			"add_to_cart"
		));

		add_filter("woocommerce_add_to_cart_handler_wcba_pack", array(
			$this,
			"add_to_cart_handler"
		));

		add_filter("woocommerce_ajax_variation_threshold", function () {
			return 1000;
		}, 10, 0);

		add_action("woocommerce_product_set_stock", array(
			$this,
			"on_set_stock"
		));

		add_action("woocommerce_variation_set_stock", array(
			$this,
			"on_set_stock"
		));

		add_action("wcba_update_relateds", array(
			$this,
			"on_update_relateds"
		), 10, 3);

		#add_action("wcba_update_discount", array(
		#	$this,
		#	"on_update_discount"
		#), 10, 3);

		add_filter("woocommerce_variation_is_active", array(
			$this,
			"is_variation_active"
		), 10, 2);

		add_action("woocommerce_thankyou", array(
			$this,
			"on_thankyou"
		));

		add_action("before_delete_post", array(
			$this,
			"before_delete_post"
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
				$discount = 0;
				$active = false;
				if ($product_object) {
					if ($product_object->is_type("wcba_pack")) {
						$relateds = $product_object->get_relateds();
						$discount = $product_object->get_discount();
						$active = true;
					}
				}

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

				woocommerce_wp_text_input(array(
					"id" => "wcba_pack_relateds",
					"label" => __("Related Products"),
					"value" => $relateds,
					"default" => "",
					"placeholder" => "1|2|3"
				));
				?>
				<p class="form-field wcba_pack_store_products_field">
					<label for="wcba_pack_store_products">Store Products</label>
					<select id="wcba_pack_store_products" class="select short" multiple>
						<?php
						foreach ($select_options as $id => $name) {
							echo "<option value=\"{$id}\">{$name}</option>";
						}
						?>
					</select>
				</p>
				<?php
				woocommerce_wp_text_input(array(
					"id" => "wcba_pack_discount",
					"label" => __("Discount percentage"),
					"value" => $discount,
					"data_type" => "decimal",
					"default" => 0,
					"placeholder" => "add a percentage"
				));
				?>
				<div class="toolbar" style="padding: 10px 20px;">
					<button id="wcba_pack_update" type="button" class="button button-primary" <?php echo ($active ? null : "disabled"); ?>>Create variations</button>
				</div>
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
	public function save_pack_settings ($product_id, $create_variations=false) {
		$product = wc_get_product($product_id);
		
		if ($product && $product->is_type("wcba_pack")) {
			if (isset($_POST["wcba_pack_discount"])) {
				$discount = absint($_POST["wcba_pack_discount"]);
				$product->set_discount($discount);
			}

			if (isset($_POST["wcba_pack_relateds"])) {
				$relateds = sanitize_text_field($_POST["wcba_pack_relateds"]);
				$product->set_relateds($relateds, $create_variations);
			}
		}
	}

	/**
	 * AJAX callback for generate variations action triggered by the pack options tab of the admin menu
	 */
	public function on_pack_update () {
		check_ajax_referer("wcba-pack-options");

		$post_id = $_POST["wcba_pack_id"];

		$this->save_pack_settings($post_id, true);

		$product = wc_get_product($post_id);
		echo json_encode(array("success" => true));

		wp_die();
	}

	/**
	 * Apply variable product behavior on add_to_cart hook
	 *
	 * @return void
	 */
	public function add_to_cart () {
		woocommerce_variable_add_to_cart();
	}

	public function add_to_cart_handler ($product_id) {
		$variation_id = empty( $_REQUEST['variation_id'] ) ? '' : absint( wp_unslash( $_REQUEST['variation_id'] ) );
		$quantity     = empty( $_REQUEST['quantity'] ) ? 1 : wc_stock_amount( wp_unslash( $_REQUEST['quantity'] ) );
		$variations   = array();

		$product      = wc_get_product( $product_id );

		foreach ( $_REQUEST as $key => $value ) {
			if ( 'attribute_' !== substr( $key, 0, 10 ) ) {
				continue;
			}

			$variations[ sanitize_title( wp_unslash( $key ) ) ] = wp_unslash( $value );
		}

		$passed_validation = apply_filters( 'woocommerce_add_to_cart_validation', true, $product_id, $quantity, $variation_id, $variations );

		if ( ! $passed_validation ) {
			return false;
		}

		// Prevent parent variable product from being added to cart.
		if ( empty( $variation_id ) && $product && $product->is_type( 'variable' ) ) {
			/* translators: 1: product link, 2: product name */
			wc_add_notice( sprintf( __( 'Please choose product options by visiting <a href="%1$s" title="%2$s">%2$s</a>.', 'woocommerce' ), esc_url( get_permalink( $product_id ) ), esc_html( $product->get_name() ) ), 'error' );

			return false;
		}

		if ( false !== WC()->cart->add_to_cart( $product_id, $quantity, $variation_id, $variations ) ) {
			wc_add_to_cart_message( array( $product_id => $quantity ), true );
			return true;
		}

		return false;
	}

	public function on_set_stock ($product) {
		if ($product->is_type("variation")) {
			# $parent = wc_get_product($product->get_parent_id());
			# if ($parent->is_type("wcba_pack")) {
			# } else if ($product->get_meta("_wcba_pack_role", true) == "wcba_pack_bundle") {
			# }
		}
	}

	public function on_update_relateds ($product, $relateds, $old_relateds) {
		$product->clear_bundles();
	}

	public function is_variation_active ($is_active, $variation) {
		return $variation->is_in_stock();
	}

	/**
	 * Synchronize related products stock after checkout
	 *
	 * @param integer $order_id
	 * @return void
	 */
	public function on_thankyou ($order_id) {
		if (!$order_id) {
			return;
		}

		$order = wc_get_order($order_id);

		foreach ($order->get_items() as $item_id => $item) {
			$qty = $item->get_quantity();
			$product = $item->get_product();
			$stock = $product->get_stock_quantity();
			$role = $product->get_meta("_wcba_pack_role", true);

			echo("QTY: {$qty}\n");
			echo("STOCK: {$stock}\n");

			if ($product->is_type("wcba_pack")) {
				// pass
			} else if ($role && $role == "wcba_pack_bundle") { 
				$bundles = $product->get_meta("_wcba_pack_bundles", true);
				foreach (explode("|", $bundles) as $bundle_id) {
					$bundle = wc_get_product($bundle_id);
					$bundle_stock = $bundle->get_stock_quantity();
					$qty = $bundle_stock - $qty == $stock ? $qty : 0;
					wc_update_product_stock($bundle, $qty, "decrease");
				}
			} else if ($product->is_type("variation")) {
				$parent = wc_get_product($product->get_parent_id());
				if ($parent->is_type("wcba_pack")) {
					$bundles = $product->get_meta("_wcba_pack_bundles", true);
					$bundles = explode("|", $bundles);
					foreach ($bundles as $bundle_id) {
						$bundle = wc_get_product($bundle_id);
						wc_update_product_stock($bundle, $qty, "decrease");
					}

					$siblings = array_map("wc_get_product", $parent->get_children());
					foreach ($siblings as $sibling) {
						if ($sibling->get_ID() == $product->get_ID()) {
							continue;
						}

						$sibling_bundles = $sibling->get_meta("_wcba_pack_bundles", true);
						$sibling_bundles = explode("|", $sibling_bundles);
						$is_related = false;
						foreach ($sibling_bundles as $sibling_bundle) {
							if (in_array($sibling_bundle, $bundles)) {
								$is_related = true;
							}
						}

						if ($is_related) {
							$sibling_stock = $sibling->get_stock_quantity();
							$qty = $sibling_stock == 0 ? 0 : $sibling_stock - $qty == $stock ? $qty : 0;
							wc_update_product_stock($sibling->get_ID(), $qty, "decrease");
						}
					}
				}
			}
		}
	}

	public function before_delete_post ($post_id) {
		$product = wc_get_product($post_id);
		if ($product) {
			$bundles = $product->get_meta("_wcba_pack_bundles", true);
			if ($product->is_type("variation")) {
				$parent = wc_get_product($product->get_parent_id());
				if ($parent->is_type("wcba_pack")) {
					foreach (explode("|", $bundles) as $bundle) {
						$rel_prod = wc_get_product($bundle);
						$reverse_bundles = $rel_prod->get_meta("_wcba_pack_bundles", true);
						$new_bundles = array();
						foreach (explode("|", $reverse_bundles) as $bundle) {
							if ($bundle != $product->get_ID()) {
								$new_bundles[] = $bundle;
							}
						}
						$new_bundles = implode("|", $new_bundles);
						update_post_meta($rel_prod->get_ID(), "_wcba_pack_bundles", $new_bundles); 
					}
				}
			} else if ($bundles) {
				foreach (explode("|", $bundles) as $bundle) {
					wp_delete_post($bundle);
				}
			}	
		}
	}
}

new WCBA_Packs();

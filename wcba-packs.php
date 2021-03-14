<?php
/**
 * Plugin Name: WooCommerce BA Packs
 * Author: Lèmur
 */

$log_path = dirname(__FILE__)."/log";

add_action("woocommerce_thankyou", "wcba_on_thankyou");
function wcba_on_thankyou ($order_id) {
	GLOBAL $log_path;

	if (!$order_id) {
		return;
	}

	$order = wc_get_order($order_id);

	foreach ($order->get_items() as $item_id => $item) {
		$product = $item->get_product();
		$sku = $product->get_sku();
		$qty = $item->get_quantity();

		if (preg_match("^wcba_packs_", $sku)) {
			$data = $item->get_data();
			file_put_contents($log_path."/on_payment.txt", print_r($data));
		}
	}
}

add_action("rest_api_init", function () {
	register_rest_route("wcba_packs/v1", "/get/(?P<id>[\d]+)", array(
		"methods" => "GET",
		"callback" => "wcba_get_pack",
		"args" => array(
			"id" => array(
				"sanitize_callback" => "absint",
				"required" => true,
				"type" => "integer",
			)
		)
	));
	register_rest_route("wcba_packs/v1", "/add", array(
		"methods" => "POST",
		"callback" => "wcba_create_pack",
		"args" => array(
			"name" => array(
				"sanitize_callback" => "sanitize_text_field",
				"required" => true,
				"type" => "string"
			)
		)
	));
	register_rest_route("wcba_packs/v1", "/list", array(
		"methods" => "GET",
		"callback" => "wcba_list_packs",
	));
	register_rest_route("wcba_packs/v1", "/delete/(?P<id>[\d]+)", array(
		"methods" => "DELETE",
		"callback" => "wcba_delete_pack",
		"args" => array(
			"id" => array(
				"sanitize_callback" => "absint",
				"required" => true,
				"type" => "integer",
			)
		)
	));
});

function wcba_get_pack (WP_REST_Request $request) {
	$pack_id = (string) $request["id"];
	$pack = wc_get_product(intval($pack_id));
	if ($pack) {
		echo json_encode($pack->get_data());
	} else {
		echo "{}";
	}
}

function wcba_create_pack (WP_REST_Request $request) {
	try {
		$payload = json_decode($request->get_body(), true);

        	$pack = new WC_Product_Variable();
        	$pack->set_name($payload["name"]);
        	$pack->set_status("publish");
        	$pack->set_catalog_visibility("hidden");
        	$pack->set_description("Producte creat com a pack automàtic, recorda fer-lo visible per que pugui ser trobat a la botiga.");
		$pack->set_sku("wcba_packs_".str_replace(" ", "_", strtolower($pack->get_name())));
        	$pack->set_category_ids(array(43));

        	$id = $pack->save();
        
        	$attributes = wcba_cross_related_attributes($payload["relateds"]);
        
		if ($attributes) {
			$i = 0;
			$pack_attrs = array();
        		foreach ($attributes as $attr => $options) {
				$pack_attrs[sanitize_title($attr)] = array(
					"name" => wc_clean($attr),
					"value" => implode("|", $options),
					"position" => $i,
					"is_visible" => 1,
					"is_variation" => 1,
					"is_taxonomy" => 0
				);
        		}
			update_post_meta($id, "_product_attributes", $pack_attrs);
			delete_transient('wc_attribute_taxonomies');
		}

        	$variations = wcba_cross_related_variations($payload["relateds"]);
		# echo json_encode($variations);
        
        	if ($variations) {
        		foreach ($variations as $d) {
				$post = array(
					"post_title" => $pack->get_name(),
					"post_name" => "product-".$id."-variation",
					"post_status" => "publish",
					"post_parent" => $id,
					"post_type" => "product_variation",
					"guid" => $pack->get_permalink()
				);
				$var_id = wp_insert_post($post);
				$var = new WC_Product_Variation($var_id);
        			$var->set_parent_id($id);
        			$var->set_price($d["price"]);
				$var->set_regular_price($d["price"]);
        			$var->set_sku($d["sku"]);
        			$var->set_manage_stock($d["manage_stock"]);
        			$var->set_stock_quantity($d["stock_quantity"]);
        			$var->set_stock_status($d["instock"]);

				foreach ($d["attributes"] as $attr => $opt) {
					$tax = "pa_".wc_sanitize_taxonomy_name(stripslashes($attr));	
					if (!taxonomy_exists($tax)) {
						register_taxonomy(
							$tax,
							"product_variation",
							array(
								"hierarchical" => false,
								"label" => ucfirst($attr),
								"query_var" => true,
								"rewrite" => array("slug" => str_replace("pa_", "", $tax))
							)
						);
					}

					if (!term_exists($opt, $tax)) {
						wp_insert_term($opt, $tax);
					}

					$slug = get_term_by("name", $opt, $tax)->slug;

					$post_term = wp_get_post_terms($id, $tax, array("fields" => "names"));

					if (!in_array($opt, $post_terms)) {
						wp_set_post_terms($id, $opt, $tax, true);
					}

					update_post_meta($var_id, $tax, $slug);

				}
        			$var->save();
        		}
        	}

        	$pack = wc_get_product($id);
        	echo json_encode($pack->get_data());
	} catch (Exception $e) {
		echo '{"error": "'.$e->getMessage().'"}';
	}
}

function wcba_get_related_products ($ids) {
 	$products = array();

	foreach ($ids as $id) {
		$products[] = wc_get_product(intval($id));
	}

	return $products;
}

function wcba_cross_related_attributes ($ids) {
	$products = wcba_get_related_products($ids);

	$attributes = array();
	foreach ($products as $p) {
		$p_name = $p->get_name();
		if ($p->is_type("variable")) {
			$p_attrs = get_post_meta($p->get_ID(), "_product_attributes")[0];
			foreach ($p_attrs as $attr) {
				$attributes[
					$p_name." ".$attr["name"]
				] = array_map("trim", explode("|", $attr["value"]));
			}
		}
	}

	return $attributes;
}

function wcba_cross_related_variations ($ids) {
	$products = wcba_get_related_products($ids);

	$variations = array();
	$related_names = array();
	$related_variations = array();
	foreach ($products as $p) {
		if ($p->is_type("variable")) {
			$related_names[] = $p->get_name();
			$related_variations[] = $p->get_available_variations();
		}
	}

	$l = count($related_variations);
	for ($i = 0; $i < $l; $i++) {
		$n1 = $related_names[$i];
		$p1 = $related_variations[$i];
		for ($j = $i; $j < $l; $j++) {
			if ($i == $j) {
				continue;
			} else {
				$n2 = $related_names[$j];
				$p2 = $related_variations[$j];
				foreach ($p1 as $var1) {
					foreach ($p2 as $var2) {
						$new_var = array();
						$new_var["price"] = $var1["display_price"] + $var2["display_price"];
						$new_var["manage_stock"] = 1;
						$new_var["stock_quantity"] = min($var1["max_qty"], $var2["max_qty"]);
						$new_var["instock"] = $new_var["stock_quantity"] > 0;
						$new_var["attributes"] = array();
						foreach ($var1["attributes"] as $attr => $val) {
							$new_var["attributes"][
								$n1." ".ucfirst(str_replace("-", " ", str_replace("attribute_", "", strtolower($attr))))
							] = $val;
						}
						foreach ($var2["attributes"] as $attr => $val) {
							$new_var["attributes"][
								$n2." ".ucfirst(str_replace("-", " ", str_replace("attribute_", "", strtolower($attr))))
							] = $val;
						}
						$pack_variations[] = $new_var;
					}
				}	
			}
		}
	}

	return $pack_variations;
}

function wcba_list_packs (WP_REST_Request $request) {
	$args = array(
		"category" => "packs"
	);
	$packs = wc_get_products($args);

	echo "[";
	$list = array();
	foreach ($packs as $pack) {
		$list[] = json_encode($pack->get_data());
	}
	echo join(",", $list);
	echo "]";
}

function wcba_delete_pack (WP_REST_Request $request) {
	$pack_id = (string) $request["id"];
	$pack = wc_get_product(intval($pack_id));
	if ($pack) {
		$pack->delete();
		echo '{"success": true}';
	} else {
		echo '{"success": false}';
	}
}
?>

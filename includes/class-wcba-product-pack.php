<?php
/**
 * Pack Product Type
 */

class WC_Product_WCBA_Pack extends WC_Product_Variable {

	public function get_type () {
		return "wcba_pack";
	}

	/*public function get_price ($context="view") {
		$discount = $this->get_meta("_pack_discount", true);
		$discount = is_numeric($discount) ? max(1, min(0, absint($discount))) : 0;
		$factor = 1 + $discount;
		$this->get_prop("price", $context) * $factor;
	}*/

	public function set_relateds ($relateds) {
		if (is_string($relateds) && strpos($relateds, "|")) {
			$relateds = implode("|", array_map("trim", explode("|", $relateds)));
		} else if (!is_numeric($relateds)) {
			$relateds = strval($relateds);
		} else if (is_array($relateds)) {
			$relateds = implode("|", $relateds);
		} else {
			$relateds = array();	
		}

		update_post_meta($this->get_ID(), "_pack_relateds", $relateds);

		$this->set_pack_variations();
	}

	public function get_relateds ($as_array=false) {
		$relateds = $this->get_meta("_pack_relateds", true);
		if ($as_array) {
			return array_map("trim", explode("|", $relateds));
		}
		return $relateds;
	}

	public function set_discount ($rate) {
		if (!is_numeric($rate)) {
			$rate = 0;
		}

		$rate = absint($rate);

		if ($rate > 1) {
			$rate = $rate / 100;
		}

		update_post_meta($this->get_ID(), "_pack_discount", $rate);
	}

	public function get_discount ($as_factor=false) {
		$discount = $this->get_meta("_pack_discount", true);
		if ($as_factor) {
			return $discount;
		}
		return $discount * 100;
	}

	public function get_related_products () {
 		$products = array();
		$ids = explode("|", $this->get_relateds());

		foreach ($ids as $id) {
			if (is_numeric($id)) {
				$product = wc_get_product(absint($id));
				if ($product) {
					$products[] = $product;
				}
			}
		}

		return $products;
	}

	private function set_pack_variations () {
		$products = $this->get_related_products();
		$attributes = $this->get_cross_related_attributes($products);
		if ($attributes) {
			$this->set_cross_related_attributes($attributes);
		}

		# $variations = $this->get_cross_related_variations($products);

        	# if ($variations) {
		#	$this->set_cross_related_variations($variations);
		# }
	}

	private function get_cross_related_attributes ($products) {
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

	private function set_cross_related_attributes ($attributes) {
		$i = 0;
		$pack_attrs = array();
		foreach ($attributes as $attr => $options) {
			wp_set_object_terms($this->get_ID(), implode("|", $options), sanitize_title_with_dashes($attr));
			$pack_attrs[sanitize_title_with_dashes($attr)] = array(
				"name" => wc_clean($attr),
				"value" => implode("|", $options),
				"position" => $i,
				"is_visible" => 1,
				"is_variation" => 1,
				"is_taxonomy" => 0
			);
		}
		update_post_meta($this->get_ID(), "_product_attributes", $pack_attrs);
		delete_transient('wc_attribute_taxonomies');
	}

	private function get_cross_related_variations ($products) {
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
							$name = array();
							$new_var["price"] = ($var1["display_price"] + $var2["display_price"]) * (1 - $this->get_discount(true));
							$new_var["manage_stock"] = 1;
							$new_var["stock_quantity"] = min($var1["max_qty"], $var2["max_qty"]);
							$new_var["instock"] = $new_var["stock_quantity"] > 0;
							$new_var["attributes"] = array();
							foreach ($var1["attributes"] as $attr => $val) {
								$attr_name = sanitize_title_with_dashes($n1."-"-str_replace("attribute_", $attr));
								$name[] = $attr_name."_".$val;
								$new_var["attributes"][
									$attr_name
								] = $val;
							}
							foreach ($var2["attributes"] as $attr => $val) {
								$attr_name = sanitize_title_with_dashes($n2."-".str_replace("attribute_", $attr));
								$name[] = $attr_name."_".$val;
								$new_var["attributes"][
									$attr_name
								] = $val;
							}
							$name = implode("-", $name);
							$pack_variations[$name] = $new_var;
						}
					}	
				}
			}
		}

		return $pack_variations;
	}

	private function set_cross_related_variations ($variations) {
		GLOBAL $wpdb;
		foreach ($variations as $name => $d) {
			$var_name = "product-".$this->get_ID()."-variation-".$name;

			$var_id = $wpdb->get_var("SELECT ID FROM $wpdb->posts WHERE post_name LIKE '$name'");

			if (!$var_id) {
				$post = array(
					"post_title" => $this->get_name(),
					"post_name" => $var_name,
					"post_status" => "publish",
					"post_parent" => $this->get_ID(),
					"post_type" => "product_variation",
					"guid" => $this->get_permalink()
				);
				$var_id = wp_insert_post($post);
			}

			$var = new WC_Product_Variation($var_id);
			$var->set_parent_id($this->get_ID());
			$var->set_price($d["price"]);
			$var->set_regular_price($d["price"]);
			$var->set_manage_stock($d["manage_stock"]);
			$var->set_stock_quantity($d["stock_quantity"]);
			$var->set_stock_status($d["instock"]);

			foreach ($d["attributes"] as $attr => $opt) {
				$tax = "pa_".wc_sanitize_taxonomy_name($attr);	
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

				$post_term = wp_get_post_terms($var_id, $tax, array("fields" => "names"));
				if (!in_array($opt, $post_terms)) {
					wp_set_post_terms($var_id, $opt, $tax, true);
				}

				$slug = get_term_by("name", $opt, $tax)->slug;
				update_post_meta($var_id, "attribute_".$tax, $slug);

			}
			$var->save();
		}
	}
}
?>

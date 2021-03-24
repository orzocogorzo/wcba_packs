<?php
/**
 * Pack Product Type
 */

class WC_Product_WCBA_Pack extends WC_Product_Variable {

	public function get_type () {
		return "wcba_pack";
	}

	public function set_relateds ($relateds, $create_variations=false) {
		if (is_string($relateds) && strpos($relateds, "|")) {
			$relateds = implode("|", array_map("trim", explode("|", $relateds)));
		} else if (!is_numeric($relateds)) {
			$relateds = strval($relateds);
		} else if (is_array($relateds)) {
			$relateds = implode("|", $relateds);
		} else {
			$relateds = array();	
		}

		$obj_relateds = $this->get_relateds();
		if ($obj_relateds != $relateds) {
			do_action("wcba_before_update_relateds", $this, $relateds);
			update_post_meta($this->get_ID(), "_wcba_pack_relateds", $relateds);
			do_action("wcba_update_relateds", $this, $relateds, $obj_relateds);
		}

		if ($create_variations) {
			$this->set_pack_variations();
		}
	}

	public function get_relateds ($as_array=false) {
		$relateds = $this->get_meta("_wcba_pack_relateds", true);
		if ($as_array) {
			return array_map("trim", explode("|", $relateds));
		}
		return $relateds;
	}

	public function set_discount ($discount) {
		if (!is_numeric($discount)) {
			$discount = 0;
		}

		$discount = absint($discount);

		if ($discount > 1) {
			$discount = $discount / 100;
		}

		$obj_discount = $this->get_discount(true);
		if ($obj_discount != $discount) {
			do_action("wcba_before_update_discount", $this, $discount);
			update_post_meta($this->get_ID(), "_wcba_pack_discount", $discount);
			do_action("wcba_update_discount", $this, $discount, $obj_discount);
		}
	}

	public function get_discount ($as_factor=false) {
		$discount = $this->get_meta("_wcba_pack_discount", true);

		if (!$discount) {
			$discount = 0;
		}

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
			$this->data_store->clear_chaches($this);
			$this->data_store->create_all_product_variations($this);
			$this->data_store->clear_chaches($this);
			$this->setup_variations();
		}
	}

	private function get_cross_related_attributes ($products) {
		$attributes = array();
		foreach ($products as $p) {
			$p_name = $p->get_name();
			if ($p->is_type("variable")) {
				$p_attrs = get_post_meta($p->get_ID(), "_product_attributes")[0];
				foreach ($p_attrs as $attr) {
					$attr_id = $p_name." ".$attr["name"];
					$attributes[$attr_id] = array_map("trim", explode("|", $attr["value"]));
				}
			} else {
				$attributes[$p_name] = array("default");
			}
		}

		return $attributes;
	}

	private function set_cross_related_attributes ($attributes) {
		$pack_attrs = array();

		foreach ($attributes as $attr => $options) {
			$slug = $this->sanitize($attr);

			wp_set_object_terms($this->get_ID(), implode("|", $options), $slug);
			$pack_attrs[$slug] = array(
				"name" => wc_clean($attr),
				"value" => implode("|", $options),
				"position" => 0,
				"is_visible" => 1,
				"is_variation" => 1,
				"is_taxonomy" => 0
			);
		}

		update_post_meta($this->get_ID(), "_product_attributes", $pack_attrs);
	}

	private function setup_variations () {
		$relateds = array_map("wc_get_product", $this->get_relateds(true));
		$own_variations = array_map("wc_get_product", $this->get_children());
		$reverse_bundles = array();

		foreach ($own_variations as $own_var) {
			$price = 0;
			$stock = null;
			$manage_stock = false;
			$bundles = array();

			foreach ($relateds as $rel_prod) {
				$reverse_bundles[$rel_prod->get_ID()] = $reverse_bundles[$rel_prod->get_ID()] ?
					$reverse_bundles[$rel_prod->get_ID()] : array();
				$rel_prod_name = $this->sanitize($rel_prod->get_name());
				if ($rel_prod->is_type("variable")) {
					foreach (array_map("wc_get_product", $rel_prod->get_children()) as $rel_var) {
						$reverse_bundles[$rel_var->get_ID()] = $reverse_bundles[$rel_var->get_ID()] ?
							$reverse_bundles[$rel_var->get_ID()] : array();
						$is_related = true;
						foreach ($own_var->get_variation_attributes(false) as $own_attr => $own_val) {
							foreach ($rel_var->get_variation_attributes(false) as $rel_attr => $rel_val) {
								if (strpos($own_attr, $rel_attr) !== false && strpos($own_attr, $rel_prod_name) !== false) {
									$is_related = $is_related && $own_val == $rel_val;
								}
							}
						}

						if ($is_related) {
							$price += (float)$rel_var->get_price();
							if ($rel_var->get_manage_stock()) {
								$manage_stock = true;
								if ($rel_var->get_stock_status() == "instock") {
									$stock = $stock !== null ? min($stock, $rel_var->get_stock_quantity()) : $rel_var->get_stock_quantity();
								} else {
									$stock = 0;
								}
							}
							$bundles[] = $rel_var->get_ID();
							$reverse_bundles[$rel_var->get_ID()][] = $own_var->get_ID();
						}
					}

				} else {
					$price += (float)$rel_prod->get_price();
					if ($rel_prod->get_manage_stock()) {
						$manage_stock = true;
						if ($rel_prod->get_stock_status() == "instock") {
							$stock = $stock !== null ? min($stock, $rel_var->get_stock_quantity()) : $rel_var->get_stock_quantity();
						} else {
							$stock = 0;
						}
					}
					$bundles[] = $rel_prod->get_ID();
					$reverse_bundles[$rel_prod->get_ID()][] = $own_var->get_ID();
				}
			}

			$own_var->set_manage_stock($manage_stock);
			$stock_status = ($stock > 0 || !$manage_stock) ? "instock" : "outofstock";
			$own_var->set_stock_status($stock_status);
			$own_var->set_stock_quantity($stock);

			$price = (float)$price * (1 - (float)$this->get_discount(true));
			$own_var->set_price($price);
			$own_var->set_regular_price($price);
			$own_var->set_sale_price($price);

			update_post_meta($own_var->get_ID(), "_wcba_pack_bundles", implode("|", $bundles));

			$own_var->save();
		}

		foreach ($reverse_bundles as $rel_id => $bundles) {
			if (sizeof($bundles) > 0) {
				$product = wc_get_product($rel_id);
				update_post_meta($product->get_ID(), "_wcba_pack_role", "wcba_pack_bundle");
				update_post_meta($product->get_ID(), "_wcba_pack_bundles", implode("|", $bundles));
			}
		}
	}

	public function clear_bundles () {
		foreach ($this->get_children() as $child) {
			$variation = wc_get_product($child);
			$bundles = $variation->get_meta("_wcba_pack_bundles", true);
			if ($bundles) {
				foreach (explode("|", $bundles) as $bundle) {
					$rel_prod = wc_get_product($bundle);
					if ($rel_prod) {
						delete_post_meta($rel_prod->get_ID(), "_wcba_pack_bundles");
					}
				}
			}
			wp_delete_post($variation->get_ID());
		}

		update_post_meta($this->get_ID(), "_product_attributes", array());
	}

	private function sanitize ($string) {
		return wc_sanitize_taxonomy_name($string);
	}
}
?>

if ( ! function_exists( 'woocommerce_simple_add_to_cart' ) ) {

	/**
	 * Output the simple product add to cart area.
	 */
	function woocommerce_simple_add_to_cart() {
		wc_get_template( 'single-product/add-to-cart/simple.php' );
	}
}
if ( ! function_exists( 'woocommerce_grouped_add_to_cart' ) ) {

	/**
	 * Output the grouped product add to cart area.
	 */
	function woocommerce_grouped_add_to_cart() {
		global $product;

		$products = array_filter( array_map( 'wc_get_product', $product->get_children() ), 'wc_products_array_filter_visible_grouped' );

		if ( $products ) {
			wc_get_template(
				'single-product/add-to-cart/grouped.php',
				array(
					'grouped_product'    => $product,
					'grouped_products'   => $products,
					'quantites_required' => false,
				)
			);
		}
	}
}
if ( ! function_exists( 'woocommerce_variable_add_to_cart' ) ) {

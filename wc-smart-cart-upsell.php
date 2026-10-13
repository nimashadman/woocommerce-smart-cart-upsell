<?php
/**
 * Plugin Name: پیشنهاد هوشمند سبد خرید
 * Plugin URI: https://nimashadman.ir/
 * Description: نمایش پنجره تأیید افزودن به سبد خرید و پیشنهاد محصولات مکمل در ووکامرس.
 * Version: 1.3.0
 * Author: نیما شادمان
 * Author URI: https://nimashadman.ir/
 * Text Domain: wc-smart-cart-upsell
 * Requires Plugins: woocommerce
 * Requires PHP: 7.4
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

defined( 'ABSPATH' ) || exit;

final class WC_Smart_Cart_Upsell {
	const VERSION       = '1.3.0';
	const META_KEY      = '_wcscu_complementary_products';
	const TERM_META_KEY = '_wcscu_complementary_categories';

	public static function init() {
		add_action( 'plugins_loaded', array( __CLASS__, 'boot' ) );
	}

	public static function boot() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action( 'admin_notices', array( __CLASS__, 'woocommerce_notice' ) );
			return;
		}

		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'frontend_assets' ) );
		add_filter( 'woocommerce_product_data_tabs', array( __CLASS__, 'add_product_tab' ) );
		add_action( 'woocommerce_product_data_panels', array( __CLASS__, 'product_panel' ) );
		add_action( 'woocommerce_process_product_meta', array( __CLASS__, 'save_product_fields' ) );
		add_action( 'product_cat_add_form_fields', array( __CLASS__, 'category_add_fields' ) );
		add_action( 'product_cat_edit_form_fields', array( __CLASS__, 'category_edit_fields' ) );
		add_action( 'created_product_cat', array( __CLASS__, 'save_category_fields' ) );
		add_action( 'edited_product_cat', array( __CLASS__, 'save_category_fields' ) );
		add_action( 'wc_ajax_wcscu_recommendations', array( __CLASS__, 'ajax_recommendations' ) );
		add_action( 'wc_ajax_nopriv_wcscu_recommendations', array( __CLASS__, 'ajax_recommendations' ) );
	}

	public static function woocommerce_notice() {
		echo '<div class="notice notice-error"><p>افزونه «پیشنهاد هوشمند سبد خرید» برای اجرا به ووکامرس نیاز دارد.</p></div>';
	}

	public static function frontend_assets() {
		if ( is_admin() ) {
			return;
		}

		$base = plugin_dir_url( __FILE__ );
		wp_enqueue_style( 'wcscu', $base . 'assets/css/frontend.css', array(), self::VERSION );
		wp_enqueue_script( 'wcscu', $base . 'assets/js/frontend.js', array( 'jquery' ), self::VERSION, true );
		wp_localize_script(
			'wcscu',
			'wcscuData',
			array(
				'endpoint' => WC_AJAX::get_endpoint( 'wcscu_recommendations' ),
				'addEndpoint' => WC_AJAX::get_endpoint( 'add_to_cart' ),
				'cartUrl'  => wc_get_cart_url(),
				'nonce'    => wp_create_nonce( 'wcscu_frontend' ),
				'i18n'     => array(
					'error'   => 'خطایی رخ داد؛ لطفاً دوباره تلاش کنید.',
					'added'   => 'اضافه شد',
					'adding'  => 'در حال افزودن…',
					'add'     => 'افزودن به سبد',
				),
			)
		);
	}

	public static function add_product_tab( $tabs ) {
		$tabs['wcscu'] = array(
			'label'    => 'محصولات مکمل',
			'target'   => 'wcscu_product_data',
			'class'    => array(),
			'priority' => 75,
		);
		return $tabs;
	}

	public static function product_panel() {
		global $post;
		$selected = array_filter( array_map( 'absint', (array) get_post_meta( $post->ID, self::META_KEY, true ) ) );
		?>
		<div id="wcscu_product_data" class="panel woocommerce_options_panel hidden">
			<div class="options_group">
				<p class="form-field">
					<label for="wcscu_products">محصولات مکمل</label>
					<select class="wc-product-search" multiple="multiple" style="width: 50%;" id="wcscu_products" name="wcscu_products[]" data-placeholder="جست‌وجوی محصول…" data-action="woocommerce_json_search_products_and_variations" data-exclude="<?php echo esc_attr( $post->ID ); ?>">
						<?php foreach ( $selected as $product_id ) : ?>
							<?php $product = wc_get_product( $product_id ); ?>
							<?php if ( $product ) : ?>
								<option value="<?php echo esc_attr( $product_id ); ?>" selected><?php echo esc_html( wp_strip_all_tags( $product->get_formatted_name() ) ); ?></option>
							<?php endif; ?>
						<?php endforeach; ?>
					</select>
					<?php echo wc_help_tip( 'این محصولات پس از افزودن کالای اصلی به سبد خرید پیشنهاد می‌شوند. ترتیب انتخاب حفظ می‌شود.' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				</p>
				<p class="form-field"><span class="description" style="margin-right:150px">اگر موردی انتخاب نشود، محصولات مکمل ووکامرس (Cross-sells) نمایش داده می‌شوند.</span></p>
			</div>
		</div>
		<?php
	}

	public static function save_product_fields( $post_id ) {
		$ids = isset( $_POST['wcscu_products'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['wcscu_products'] ) ) : array();
		$ids = array_values( array_unique( array_filter( $ids ) ) );
		update_post_meta( $post_id, self::META_KEY, $ids );
	}

	private static function category_select( $selected = array(), $exclude = 0 ) {
		$terms = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
				'exclude'    => $exclude ? array( $exclude ) : array(),
			)
		);

		if ( is_wp_error( $terms ) ) {
			return;
		}

		echo '<select name="wcscu_related_categories[]" multiple="multiple" style="width:100%;min-height:120px">';
		foreach ( $terms as $term ) {
			printf(
				'<option value="%1$d" %2$s>%3$s</option>',
				absint( $term->term_id ),
				selected( in_array( $term->term_id, $selected, true ), true, false ),
				esc_html( $term->name )
			);
		}
		echo '</select>';
	}

	public static function category_add_fields() {
		wp_nonce_field( 'wcscu_save_category', 'wcscu_category_nonce' );
		?>
		<div class="form-field">
			<label for="wcscu_related_categories">دسته‌های مکمل</label>
			<?php self::category_select(); ?>
			<p>مثلاً برای دسته «کتونی»، دسته «ملزومات کفش» را انتخاب کنید. برای انتخاب چند دسته، Ctrl در ویندوز یا Command در مک را نگه دارید.</p>
		</div>
		<?php
	}

	public static function category_edit_fields( $term ) {
		$selected = array_values( array_filter( array_map( 'absint', (array) get_term_meta( $term->term_id, self::TERM_META_KEY, true ) ) ) );
		wp_nonce_field( 'wcscu_save_category', 'wcscu_category_nonce' );
		?>
		<tr class="form-field">
			<th scope="row"><label for="wcscu_related_categories">دسته‌های مکمل</label></th>
			<td>
				<?php self::category_select( $selected, $term->term_id ); ?>
				<p class="description">محصولات موجود این دسته‌ها برای تمام کالاهای دسته فعلی پیشنهاد می‌شوند. انتخاب اختصاصی داخل خود محصول، اولویت بالاتری دارد.</p>
			</td>
		</tr>
		<?php
	}

	public static function save_category_fields( $term_id ) {
		if ( ! isset( $_POST['wcscu_category_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wcscu_category_nonce'] ) ), 'wcscu_save_category' ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_product_terms' ) && ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$ids = isset( $_POST['wcscu_related_categories'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['wcscu_related_categories'] ) ) : array();
		$ids = array_values( array_diff( array_unique( array_filter( $ids ) ), array( absint( $term_id ) ) ) );
		update_term_meta( $term_id, self::TERM_META_KEY, $ids );
	}

	private static function category_recommendation_ids( $product_id ) {
		$product_categories = wp_get_post_terms( $product_id, 'product_cat', array( 'fields' => 'ids' ) );
		if ( is_wp_error( $product_categories ) || ! $product_categories ) {
			return array();
		}

		$related_categories = array();
		foreach ( $product_categories as $category_id ) {
			$related_categories = array_merge( $related_categories, (array) get_term_meta( $category_id, self::TERM_META_KEY, true ) );
		}
		$related_categories = array_values( array_unique( array_filter( array_map( 'absint', $related_categories ) ) ) );
		if ( ! $related_categories ) {
			return array();
		}

		$slugs = array();
		foreach ( $related_categories as $category_id ) {
			$term = get_term( $category_id, 'product_cat' );
			if ( $term && ! is_wp_error( $term ) ) {
				$slugs[] = $term->slug;
			}
		}

		if ( ! $slugs ) {
			return array();
		}

		return wc_get_products(
			array(
				'status'       => 'publish',
				'category'     => $slugs,
				'exclude'      => array( $product_id ),
				'stock_status' => 'instock',
				'limit'        => 6,
				'orderby'      => 'menu_order',
				'order'        => 'ASC',
				'return'       => 'ids',
			)
		);
	}

	public static function ajax_recommendations() {
		check_ajax_referer( 'wcscu_frontend', 'nonce' );

		$product_id = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;
		$product    = wc_get_product( $product_id );

		if ( ! $product ) {
			wp_send_json_error( array( 'message' => 'محصول پیدا نشد.' ), 404 );
		}

		$ids = array_filter( array_map( 'absint', (array) get_post_meta( $product_id, self::META_KEY, true ) ) );
		if ( ! $ids ) {
			$ids = self::category_recommendation_ids( $product_id );
		}
		if ( ! $ids ) {
			$ids = $product->get_cross_sell_ids();
		}
		if ( ! $ids ) {
			$ids = wc_get_related_products( $product_id, 4 );
		}

		$recommendations = array();
		foreach ( array_slice( array_unique( $ids ), 0, 6 ) as $id ) {
			$item = wc_get_product( $id );
			if ( ! $item || ! $item->is_visible() || ! $item->is_in_stock() ) {
				continue;
			}

			$recommendations[] = array(
				'id'          => $item->get_id(),
				'name'        => $item->get_name(),
				'image'       => wp_get_attachment_image_url( $item->get_image_id(), 'woocommerce_thumbnail' ) ?: wc_placeholder_img_src( 'woocommerce_thumbnail' ),
				'price_html'  => $item->get_price_html(),
				'url'         => $item->get_permalink(),
				'can_add'     => $item->is_type( 'simple' ) && $item->is_purchasable(),
				'button_text' => $item->is_type( 'simple' ) ? 'افزودن به سبد' : 'انتخاب گزینه‌ها',
			);
		}

		wp_send_json_success(
			array(
				'product'         => array(
					'id'    => $product->get_id(),
					'name'  => $product->get_name(),
					'image' => wp_get_attachment_image_url( $product->get_image_id(), 'woocommerce_thumbnail' ) ?: wc_placeholder_img_src( 'woocommerce_thumbnail' ),
				),
				'recommendations' => $recommendations,
			)
		);
	}
}

WC_Smart_Cart_Upsell::init();

<?php
/**
 * Plugin Name: Custom Product Pages for WooCommerce
 * Description: Create custom product listing pages with frontend form. Get shortcode to use anywhere. 3-column products, search, header filter.
 * Version: 1.0.8
 * Author: Your Name
 * Text Domain: cppw
 * Requires at least: 4.7
 * Tested up to: 6.5
 * WC requires at least: 3.0
 * WC tested up to: 10.9.3
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'CPPW_VERSION', '1.0.8' );
define( 'CPPW_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'CPPW_PLUGIN_PATH', plugin_dir_path( __FILE__ ) );
define( 'CPPW_TAXONOMY_BRAND', 'product_brand' );

// ---------------------- RELIABLE WOOCOMMERCE DETECTION ----------------------
$woocommerce_active = false;
if ( function_exists( 'is_plugin_active' ) ) {
    if ( is_plugin_active( 'woocommerce/woocommerce.php' ) ) {
        $woocommerce_active = true;
    }
} else {
    $active_plugins = get_option( 'active_plugins', array() );
    if ( is_multisite() ) {
        $active_plugins = array_merge( $active_plugins, get_site_option( 'active_sitewide_plugins', array() ) );
    }
    if ( in_array( 'woocommerce/woocommerce.php', $active_plugins ) || array_key_exists( 'woocommerce/woocommerce.php', $active_plugins ) ) {
        $woocommerce_active = true;
    }
}

if ( ! $woocommerce_active ) {
    add_action( 'admin_notices', function() {
        echo '<div class="error"><p>' . esc_html__( 'Custom Product Pages for WooCommerce requires WooCommerce to be installed and activated.', 'cppw' ) . '</p></div>';
    } );
    return;
}

// ---------------------- HPOS COMPATIBILITY USING before_woocommerce_init ----------------------
add_action( 'before_woocommerce_init', function() {
    if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'product_block_editor', __FILE__, true );
    }
}, 0 );

register_activation_hook( __FILE__, 'cppw_activate' );
function cppw_activate() {
    $plugin = new CPPW_Plugin();
    $plugin->register_post_type();
    $plugin->register_brand_taxonomy();
    flush_rewrite_rules();
}

register_deactivation_hook( __FILE__, 'cppw_deactivate' );
function cppw_deactivate() {
    flush_rewrite_rules();
}

class CPPW_Plugin {

    public function __construct() {
        add_action( 'init', array( $this, 'init' ) );
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
        
        add_action( 'wp_ajax_cppw_search_terms', array( $this, 'ajax_search_terms' ) );
        add_action( 'wp_ajax_nopriv_cppw_search_terms', array( $this, 'ajax_search_terms' ) );
        add_action( 'wp_ajax_cppw_filter_products', array( $this, 'ajax_filter_products' ) );
        add_action( 'wp_ajax_nopriv_cppw_filter_products', array( $this, 'ajax_filter_products' ) );
        add_action( 'wp_ajax_cppw_create_page_ajax', array( $this, 'ajax_create_page' ) );
        add_action( 'wp_ajax_nopriv_cppw_create_page_ajax', array( $this, 'ajax_create_page' ) );

        add_shortcode( 'cppw_create_page', array( $this, 'shortcode_create_page' ) );
        add_shortcode( 'cppw_display_page', array( $this, 'shortcode_display_page' ) );
        add_shortcode( 'cppw_filter', array( $this, 'shortcode_filter' ) );

        add_action( 'init', array( $this, 'register_post_type' ) );
        add_action( 'init', array( $this, 'register_brand_taxonomy' ) );
        add_action( 'save_post_cppw_page', array( $this, 'save_page_meta' ), 10, 3 );

        add_filter( 'woocommerce_product_loop_columns', array( $this, 'set_product_columns' ) );
    }

    public function init() {
        load_plugin_textdomain( 'cppw', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
    }

    public function set_product_columns() {
        return 3;
    }

    public function register_post_type() {
        $labels = array(
            'name'               => _x( 'Custom Product Pages', 'post type general name', 'cppw' ),
            'singular_name'      => _x( 'Custom Product Page', 'post type singular name', 'cppw' ),
            'menu_name'          => _x( 'Product Pages', 'admin menu', 'cppw' ),
            'add_new'            => _x( 'Add New', 'cppw' ),
            'add_new_item'       => __( 'Add New Product Page', 'cppw' ),
            'edit_item'          => __( 'Edit Product Page', 'cppw' ),
            'new_item'           => __( 'New Product Page', 'cppw' ),
            'view_item'          => __( 'View Product Page', 'cppw' ),
            'search_items'       => __( 'Search Product Pages', 'cppw' ),
            'not_found'          => __( 'No product pages found', 'cppw' ),
            'not_found_in_trash' => __( 'No product pages found in Trash', 'cppw' ),
        );
        $args = array(
            'labels'             => $labels,
            'public'             => true,
            'publicly_queryable' => true,
            'show_ui'            => true,
            'show_in_menu'       => true,
            'query_var'          => true,
            'rewrite'            => array( 'slug' => 'product-page', 'with_front' => true ),
            'capability_type'    => 'post',
            'has_archive'        => true,
            'hierarchical'       => false,
            'menu_position'      => 56,
            'supports'           => array( 'title', 'editor', 'thumbnail' ),
            'show_in_rest'       => true,
        );
        register_post_type( 'cppw_page', $args );
    }

    public function register_brand_taxonomy() {
        if ( ! taxonomy_exists( CPPW_TAXONOMY_BRAND ) ) {
            $labels = array(
                'name'              => _x( 'Brands', 'taxonomy general name', 'cppw' ),
                'singular_name'     => _x( 'Brand', 'taxonomy singular name', 'cppw' ),
                'search_items'      => __( 'Search Brands', 'cppw' ),
                'all_items'         => __( 'All Brands', 'cppw' ),
                'parent_item'       => __( 'Parent Brand', 'cppw' ),
                'parent_item_colon' => __( 'Parent Brand:', 'cppw' ),
                'edit_item'         => __( 'Edit Brand', 'cppw' ),
                'update_item'       => __( 'Update Brand', 'cppw' ),
                'add_new_item'      => __( 'Add New Brand', 'cppw' ),
                'new_item_name'     => __( 'New Brand Name', 'cppw' ),
                'menu_name'         => __( 'Brands', 'cppw' ),
            );
            $args = array(
                'hierarchical'      => true,
                'labels'            => $labels,
                'show_ui'           => true,
                'show_admin_column' => true,
                'query_var'         => true,
                'rewrite'           => array( 'slug' => 'brand' ),
                'show_in_rest'      => true,
            );
            register_taxonomy( CPPW_TAXONOMY_BRAND, 'product', $args );
        }
    }

    public function save_page_meta( $post_id, $post, $update ) {
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
        if ( ! current_user_can( 'edit_post', $post_id ) ) return;
        if ( isset( $_POST['cppw_categories'] ) ) {
            update_post_meta( $post_id, '_cppw_categories', array_map( 'intval', $_POST['cppw_categories'] ) );
        }
        if ( isset( $_POST['cppw_brands'] ) ) {
            update_post_meta( $post_id, '_cppw_brands', array_map( 'intval', $_POST['cppw_brands'] ) );
        }
        if ( isset( $_POST['cppw_tags'] ) ) {
            update_post_meta( $post_id, '_cppw_tags', array_map( 'intval', $_POST['cppw_tags'] ) );
        }
    }

    public function enqueue_scripts() {
        wp_enqueue_style( 'cppw-select2', 'https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/css/select2.min.css', array(), '4.0.13' );
        wp_enqueue_script( 'cppw-select2', 'https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/js/select2.min.js', array( 'jquery' ), '4.0.13', true );
        wp_enqueue_style( 'cppw-style', CPPW_PLUGIN_URL . 'assets/cppw-style.css', array(), CPPW_VERSION );
        wp_enqueue_script( 'cppw-script', CPPW_PLUGIN_URL . 'assets/cppw-script.js', array( 'jquery', 'cppw-select2' ), CPPW_VERSION, true );
        wp_localize_script( 'cppw-script', 'cppw_ajax', array(
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'nonce'    => wp_create_nonce( 'cppw_nonce' ),
        ) );
    }

    public function ajax_search_terms() {
        check_ajax_referer( 'cppw_nonce', 'nonce' );
        $search = isset( $_GET['q'] ) ? sanitize_text_field( $_GET['q'] ) : '';
        $taxonomy = isset( $_GET['taxonomy'] ) ? sanitize_text_field( $_GET['taxonomy'] ) : '';
        if ( empty( $taxonomy ) ) {
            wp_send_json_error( 'No taxonomy provided' );
        }
        $terms = get_terms( array(
            'taxonomy'   => $taxonomy,
            'hide_empty' => false,
            'search'     => $search,
            'number'     => 20,
        ) );
        $results = array();
        foreach ( $terms as $term ) {
            $results[] = array(
                'id'   => $term->term_id,
                'text' => $term->name . ' (' . $term->count . ')',
            );
        }
        wp_send_json( $results );
    }

    public function ajax_create_page() {
        check_ajax_referer( 'cppw_create_page_nonce', 'cppw_nonce' );
        if ( ! current_user_can( 'publish_posts' ) ) {
            wp_send_json_error( array( 'message' => __( 'Permission denied.', 'cppw' ) ) );
        }
        $title = isset( $_POST['cppw_page_title'] ) ? sanitize_text_field( $_POST['cppw_page_title'] ) : '';
        if ( empty( $title ) ) {
            wp_send_json_error( array( 'message' => __( 'Page title is required.', 'cppw' ) ) );
        }
        $categories = isset( $_POST['cppw_categories'] ) ? array_map( 'intval', $_POST['cppw_categories'] ) : array();
        $brands     = isset( $_POST['cppw_brands'] ) ? array_map( 'intval', $_POST['cppw_brands'] ) : array();
        $tags       = isset( $_POST['cppw_tags'] ) ? array_map( 'intval', $_POST['cppw_tags'] ) : array();
        if ( empty( $categories ) && empty( $brands ) && empty( $tags ) ) {
            wp_send_json_error( array( 'message' => __( 'At least one category, brand, or tag must be selected.', 'cppw' ) ) );
        }
        $post_data = array(
            'post_title'   => $title,
            'post_type'    => 'cppw_page',
            'post_status'  => 'publish',
            'post_content' => '',
        );
        $post_id = wp_insert_post( $post_data );
        if ( is_wp_error( $post_id ) ) {
            wp_send_json_error( array( 'message' => $post_id->get_error_message() ) );
        }
        update_post_meta( $post_id, '_cppw_categories', $categories );
        update_post_meta( $post_id, '_cppw_brands', $brands );
        update_post_meta( $post_id, '_cppw_tags', $tags );
        
        $shortcode = '[cppw_display_page id="' . $post_id . '"]';
        $filter_shortcode = '[cppw_filter id="' . $post_id . '"]';
        
        wp_send_json_success( array(
            'message'           => __( 'Page created successfully!', 'cppw' ),
            'edit_link'         => get_edit_post_link( $post_id, '' ),
            'page_url'          => get_permalink( $post_id ),
            'shortcode'         => $shortcode,
            'filter_shortcode'  => $filter_shortcode,
        ) );
    }

    public function shortcode_create_page() {
        if ( ! is_user_logged_in() || ! current_user_can( 'publish_posts' ) ) {
            return '<p>' . esc_html__( 'You need to be logged in as an editor/admin to create pages.', 'cppw' ) . '</p>';
        }
        ob_start();
        ?>
        <div id="cppw-create-form">
            <form method="post" action="">
                <?php wp_nonce_field( 'cppw_create_page_nonce', 'cppw_nonce' ); ?>
                <div class="cppw-field">
                    <label for="cppw_page_title"><?php esc_html_e( 'Page Title:', 'cppw' ); ?></label>
                    <input type="text" name="cppw_page_title" id="cppw_page_title" required />
                </div>
                <div class="cppw-field">
                    <label for="cppw_categories"><?php esc_html_e( 'Categories:', 'cppw' ); ?></label>
                    <select name="cppw_categories[]" id="cppw_categories" multiple="multiple" class="cppw-select2" data-taxonomy="product_cat"></select>
                </div>
                <div class="cppw-field">
                    <label for="cppw_brands"><?php esc_html_e( 'Brands:', 'cppw' ); ?></label>
                    <select name="cppw_brands[]" id="cppw_brands" multiple="multiple" class="cppw-select2" data-taxonomy="<?php echo esc_attr( CPPW_TAXONOMY_BRAND ); ?>"></select>
                </div>
                <div class="cppw-field">
                    <label for="cppw_tags"><?php esc_html_e( 'Tags:', 'cppw' ); ?></label>
                    <select name="cppw_tags[]" id="cppw_tags" multiple="multiple" class="cppw-select2" data-taxonomy="product_tag"></select>
                </div>
                <div class="cppw-field">
                    <input type="submit" name="cppw_submit" value="<?php esc_attr_e( 'Create Page', 'cppw' ); ?>" />
                </div>
                <div id="cppw-form-message"></div>
            </form>
        </div>
        <script>
            jQuery(document).ready(function($) {
                $('.cppw-select2').each(function() {
                    var $el = $(this);
                    var taxonomy = $el.data('taxonomy');
                    $el.select2({
                        ajax: {
                            url: cppw_ajax.ajax_url,
                            dataType: 'json',
                            delay: 250,
                            data: function(params) {
                                return {
                                    action: 'cppw_search_terms',
                                    nonce: cppw_ajax.nonce,
                                    q: params.term,
                                    taxonomy: taxonomy
                                };
                            },
                            processResults: function(data) {
                                return { results: data };
                            },
                            cache: true
                        },
                        placeholder: '<?php esc_attr_e( 'Search and select...', 'cppw' ); ?>',
                        minimumInputLength: 1,
                        multiple: true
                    });
                });

                $('#cppw-create-form form').on('submit', function(e) {
                    e.preventDefault();
                    var formData = $(this).serialize();
                    $('#cppw-form-message').html('').removeClass('error success');
                    $.ajax({
                        url: cppw_ajax.ajax_url,
                        type: 'POST',
                        data: formData + '&action=cppw_create_page_ajax',
                        dataType: 'json',
                        success: function(response) {
                            if (response.success) {
                                var msg = '<p class="success">' + response.data.message + '</p>';
                                msg += '<p><strong><?php esc_html_e( 'Shortcode for Products + Filter:', 'cppw' ); ?></strong> <code>' + response.data.shortcode + '</code> <button class="cppw-copy-btn" data-copy="' + response.data.shortcode + '"><?php esc_html_e( 'Copy', 'cppw' ); ?></button></p>';
                                msg += '<p><strong><?php esc_html_e( 'Shortcode for Filter only (sidebar):', 'cppw' ); ?></strong> <code>' + response.data.filter_shortcode + '</code> <button class="cppw-copy-btn" data-copy="' + response.data.filter_shortcode + '"><?php esc_html_e( 'Copy', 'cppw' ); ?></button></p>';
                                msg += '<p><a href="' + response.data.edit_link + '" target="_blank"><?php esc_html_e( 'Edit Page in Gutenberg', 'cppw' ); ?></a> | <a href="' + response.data.page_url + '" target="_blank"><?php esc_html_e( 'View Page', 'cppw' ); ?></a></p>';
                                msg += '<p class="cppw-hint" style="font-size:13px;color:#555;"><?php esc_html_e( 'Copy the shortcode above and paste it into your Gutenberg page content using a "Shortcode" block.', 'cppw' ); ?></p>';
                                $('#cppw-form-message').html(msg).addClass('success');
                                $('#cppw-create-form form')[0].reset();
                                $('.cppw-select2').val(null).trigger('change');
                            } else {
                                $('#cppw-form-message').html('<p class="error">' + response.data.message + '</p>').addClass('error');
                            }
                        },
                        error: function() {
                            $('#cppw-form-message').html('<p class="error"><?php esc_html_e( 'An unexpected error occurred.', 'cppw' ); ?></p>').addClass('error');
                        }
                    });
                });

                $(document).on('click', '.cppw-copy-btn', function() {
                    var text = $(this).data('copy');
                    var $temp = $('<input>');
                    $('body').append($temp);
                    $temp.val(text).select();
                    document.execCommand('copy');
                    $temp.remove();
                    var $btn = $(this);
                    var origText = $btn.text();
                    $btn.text('<?php esc_html_e( 'Copied!', 'cppw' ); ?>');
                    setTimeout(function() { $btn.text(origText); }, 2000);
                });
            });
        </script>
        <?php
        return ob_get_clean();
    }

    public function shortcode_display_page( $atts ) {
        $atts = shortcode_atts( array( 'id' => 0, 'show_filter' => 'yes' ), $atts, 'cppw_display_page' );
        $page_id = intval( $atts['id'] );
        if ( ! $page_id ) {
            return '<p>' . esc_html__( 'Invalid page ID.', 'cppw' ) . '</p>';
        }
        $categories = get_post_meta( $page_id, '_cppw_categories', true ) ?: array();
        $brands     = get_post_meta( $page_id, '_cppw_brands', true ) ?: array();
        $tags       = get_post_meta( $page_id, '_cppw_tags', true ) ?: array();
        if ( empty( $categories ) && empty( $brands ) && empty( $tags ) ) {
            return '<p>' . esc_html__( 'This page has no criteria defined.', 'cppw' ) . '</p>';
        }
        ob_start();
        ?>
        <div class="cppw-product-container" data-page-id="<?php echo esc_attr( $page_id ); ?>">
            <?php if ( $atts['show_filter'] === 'yes' ) : ?>
                <div class="cppw-filter-header">
                    <?php $this->render_filter( $page_id ); ?>
                </div>
            <?php endif; ?>
            <div class="cppw-products-wrapper">
                <div class="cppw-products">
                    <?php $this->render_products( $page_id, array() ); ?>
                </div>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    public function shortcode_filter( $atts ) {
        $atts = shortcode_atts( array( 'id' => 0 ), $atts, 'cppw_filter' );
        $page_id = intval( $atts['id'] );
        if ( ! $page_id ) {
            return '<p>' . esc_html__( 'Invalid page ID.', 'cppw' ) . '</p>';
        }
        ob_start();
        $this->render_filter( $page_id );
        return ob_get_clean();
    }

    private function render_filter( $page_id ) {
        $categories = get_post_meta( $page_id, '_cppw_categories', true ) ?: array();
        $brands     = get_post_meta( $page_id, '_cppw_brands', true ) ?: array();
        $tags       = get_post_meta( $page_id, '_cppw_tags', true ) ?: array();

        $base_tax_query = array( 'relation' => 'AND' );
        if ( ! empty( $categories ) ) {
            $base_tax_query[] = array( 'taxonomy' => 'product_cat', 'field' => 'term_id', 'terms' => $categories );
        }
        if ( ! empty( $brands ) ) {
            $base_tax_query[] = array( 'taxonomy' => CPPW_TAXONOMY_BRAND, 'field' => 'term_id', 'terms' => $brands );
        }
        if ( ! empty( $tags ) ) {
            $base_tax_query[] = array( 'taxonomy' => 'product_tag', 'field' => 'term_id', 'terms' => $tags );
        }
        $base_product_ids = get_posts( array(
            'post_type'      => 'product',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'tax_query'      => $base_tax_query,
        ) );

        $term_counts = array();
        $taxonomies = array( 'product_cat' => 'Categories', CPPW_TAXONOMY_BRAND => 'Brands', 'product_tag' => 'Tags' );
        foreach ( $taxonomies as $tax => $label ) {
            $terms = get_terms( array(
                'taxonomy'   => $tax,
                'hide_empty' => true,
                'object_ids' => $base_product_ids,
            ) );
            $term_counts[ $tax ] = $terms;
        }
        ?>
        <div class="cppw-filter" data-page-id="<?php echo esc_attr( $page_id ); ?>">
            <form class="cppw-filter-form">
                <?php wp_nonce_field( 'cppw_filter_nonce', 'cppw_filter_nonce' ); ?>
                <div class="filter-row">
                    <div class="filter-group filter-search">
                        <input type="text" name="search_term" placeholder="<?php esc_attr_e( 'Search products...', 'cppw' ); ?>" />
                    </div>
                    <div class="filter-group filter-price">
                        <input type="number" name="min_price" placeholder="<?php esc_attr_e( 'Min price', 'cppw' ); ?>" step="1" min="0" />
                        <span>-</span>
                        <input type="number" name="max_price" placeholder="<?php esc_attr_e( 'Max price', 'cppw' ); ?>" step="1" min="0" />
                    </div>
                    <div class="filter-group filter-newold">
                        <select name="new_old">
                            <option value=""><?php esc_html_e( 'All dates', 'cppw' ); ?></option>
                            <option value="new"><?php esc_html_e( 'New (30 days)', 'cppw' ); ?></option>
                            <option value="old"><?php esc_html_e( 'Old (>30 days)', 'cppw' ); ?></option>
                        </select>
                    </div>
                    <?php foreach ( $taxonomies as $tax => $label ) : ?>
                        <?php if ( ! empty( $term_counts[ $tax ] ) ) : ?>
                            <div class="filter-group filter-tax">
                                <select name="tax_<?php echo esc_attr( $tax ); ?>">
                                    <option value=""><?php echo esc_html( $label ); ?></option>
                                    <?php foreach ( $term_counts[ $tax ] as $term ) : ?>
                                        <option value="<?php echo esc_attr( $term->term_id ); ?>"><?php echo esc_html( $term->name ); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        <?php endif; ?>
                    <?php endforeach; ?>
                    <div class="filter-group filter-actions">
                        <button type="submit" class="cppw-apply-filter"><?php esc_html_e( 'Apply', 'cppw' ); ?></button>
                        <button type="reset" class="cppw-reset-filter"><?php esc_html_e( 'Reset', 'cppw' ); ?></button>
                    </div>
                </div>
            </form>
        </div>
        <?php
    }

    private function render_products( $page_id, $filter_args ) {
        $categories = get_post_meta( $page_id, '_cppw_categories', true ) ?: array();
        $brands     = get_post_meta( $page_id, '_cppw_brands', true ) ?: array();
        $tags       = get_post_meta( $page_id, '_cppw_tags', true ) ?: array();

        $tax_query = array( 'relation' => 'AND' );
        if ( ! empty( $categories ) ) {
            $tax_query[] = array( 'taxonomy' => 'product_cat', 'field' => 'term_id', 'terms' => $categories, 'operator' => 'IN' );
        }
        if ( ! empty( $brands ) ) {
            if ( taxonomy_exists( CPPW_TAXONOMY_BRAND ) ) {
                $tax_query[] = array( 'taxonomy' => CPPW_TAXONOMY_BRAND, 'field' => 'term_id', 'terms' => $brands, 'operator' => 'IN' );
            } else {
                echo '<div class="cppw-error"><p><strong>' . esc_html__( 'Warning:', 'cppw' ) . '</strong> ' . esc_html__( 'Brand taxonomy "' . CPPW_TAXONOMY_BRAND . '" does not exist. Please check your brand taxonomy name.', 'cppw' ) . '</p></div>';
            }
        }
        if ( ! empty( $tags ) ) {
            $tax_query[] = array( 'taxonomy' => 'product_tag', 'field' => 'term_id', 'terms' => $tags, 'operator' => 'IN' );
        }

        $meta_query = array( 'relation' => 'AND' );
        if ( isset( $filter_args['min_price'] ) && $filter_args['min_price'] !== '' ) {
            $meta_query[] = array(
                'key'     => '_price',
                'value'   => floatval( $filter_args['min_price'] ),
                'compare' => '>=',
                'type'    => 'NUMERIC',
            );
        }
        if ( isset( $filter_args['max_price'] ) && $filter_args['max_price'] !== '' ) {
            $meta_query[] = array(
                'key'     => '_price',
                'value'   => floatval( $filter_args['max_price'] ),
                'compare' => '<=',
                'type'    => 'NUMERIC',
            );
        }

        $date_query = array();
        if ( isset( $filter_args['new_old'] ) && $filter_args['new_old'] !== '' ) {
            if ( $filter_args['new_old'] === 'new' ) {
                $date_query = array( 'after' => '30 days ago', 'column' => 'post_date' );
            } elseif ( $filter_args['new_old'] === 'old' ) {
                $date_query = array( 'before' => '30 days ago', 'column' => 'post_date' );
            }
        }

        $taxonomies = array( 'product_cat', CPPW_TAXONOMY_BRAND, 'product_tag' );
        foreach ( $taxonomies as $tax ) {
            $param = 'tax_' . $tax;
            if ( isset( $filter_args[ $param ] ) && $filter_args[ $param ] !== '' ) {
                $tax_query[] = array(
                    'taxonomy' => $tax,
                    'field'    => 'term_id',
                    'terms'    => intval( $filter_args[ $param ] ),
                    'operator' => 'IN',
                );
            }
        }

        $search_term = isset( $filter_args['search_term'] ) ? sanitize_text_field( $filter_args['search_term'] ) : '';

        $args = array(
            'post_type'      => 'product',
            'posts_per_page' => 30,
            'paged'          => get_query_var( 'paged' ) ?: 1,
            'tax_query'      => $tax_query,
            'meta_query'     => $meta_query,
        );
        if ( ! empty( $date_query ) ) {
            $args['date_query'] = $date_query;
        }
        if ( ! empty( $search_term ) ) {
            $args['s'] = $search_term;
        }

        echo '<div class="cppw-debug-info" style="background:#f5f5f5;padding:10px;margin-bottom:15px;border-left:3px solid #007cba;font-size:14px;">';
        echo '<strong>' . esc_html__( 'Selected Criteria:', 'cppw' ) . '</strong> ';
        $criteria = array();
        if ( ! empty( $categories ) ) {
            $names = array();
            foreach ( $categories as $id ) {
                $term = get_term( $id, 'product_cat' );
                if ( $term && ! is_wp_error( $term ) ) {
                    $names[] = $term->name;
                }
            }
            $criteria[] = 'Categories: ' . implode(', ', $names);
        }
        if ( ! empty( $brands ) ) {
            $names = array();
            foreach ( $brands as $id ) {
                $term = get_term( $id, CPPW_TAXONOMY_BRAND );
                if ( $term && ! is_wp_error( $term ) ) {
                    $names[] = $term->name;
                }
            }
            $criteria[] = 'Brands: ' . implode(', ', $names);
        }
        if ( ! empty( $tags ) ) {
            $names = array();
            foreach ( $tags as $id ) {
                $term = get_term( $id, 'product_tag' );
                if ( $term && ! is_wp_error( $term ) ) {
                    $names[] = $term->name;
                }
            }
            $criteria[] = 'Tags: ' . implode(', ', $names);
        }
        if ( ! empty( $search_term ) ) {
            $criteria[] = 'Search: "' . $search_term . '"';
        }
        echo implode(' | ', $criteria);
        echo '</div>';

        $query = new WP_Query( $args );
        if ( $query->have_posts() ) {
            add_filter( 'woocommerce_product_loop_columns', function() { return 3; } );
            woocommerce_product_loop_start();
            while ( $query->have_posts() ) {
                $query->the_post();
                wc_get_template_part( 'content', 'product' );
            }
            woocommerce_product_loop_end();
            echo wp_kses_post( paginate_links( array(
                'total'   => $query->max_num_pages,
                'current' => max( 1, get_query_var( 'paged' ) ),
                'format'  => '?paged=%#%',
            ) ) );
            wp_reset_postdata();
            remove_filter( 'woocommerce_product_loop_columns', function() { return 3; } );
        } else {
            echo '<div class="cppw-no-products" style="background:#fff3cd;padding:15px;border:1px solid #ffeeba;border-radius:4px;color:#856404;">';
            echo '<p><strong>' . esc_html__( 'No products found matching the above criteria.', 'cppw' ) . '</strong></p>';
            echo '<p>' . esc_html__( 'Possible reasons:', 'cppw' ) . '</p>';
            echo '<ul style="margin-left:20px;list-style:disc;">';
            echo '<li>' . esc_html__( 'The selected categories/brands/tags have no products assigned.', 'cppw' ) . '</li>';
            echo '<li>' . esc_html__( 'The brand taxonomy name might be different. Currently set to: "' . CPPW_TAXONOMY_BRAND . '".', 'cppw' ) . '</li>';
            echo '<li>' . esc_html__( 'Products might be out of stock or unpublished.', 'cppw' ) . '</li>';
            echo '</ul>';
            echo '</div>';
        }
    }

    public function ajax_filter_products() {
        check_ajax_referer( 'cppw_filter_nonce', 'nonce' );
        $page_id = isset( $_POST['page_id'] ) ? intval( $_POST['page_id'] ) : 0;
        if ( ! $page_id ) {
            wp_send_json_error( array( 'message' => 'Invalid page ID' ) );
        }
        $filter_args = array();
        $fields = array( 'min_price', 'max_price', 'new_old', 'search_term' );
        foreach ( $fields as $field ) {
            if ( isset( $_POST[ $field ] ) && $_POST[ $field ] !== '' ) {
                $filter_args[ $field ] = sanitize_text_field( $_POST[ $field ] );
            }
        }
        $taxonomies = array( 'product_cat', CPPW_TAXONOMY_BRAND, 'product_tag' );
        foreach ( $taxonomies as $tax ) {
            $param = 'tax_' . $tax;
            if ( isset( $_POST[ $param ] ) && $_POST[ $param ] !== '' ) {
                $filter_args[ $param ] = intval( $_POST[ $param ] );
            }
        }
        ob_start();
        $this->render_products( $page_id, $filter_args );
        $html = ob_get_clean();
        wp_send_json_success( array( 'html' => $html ) );
    }
}

new CPPW_Plugin();

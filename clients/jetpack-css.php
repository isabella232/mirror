<?php

class Jetpack_CSS_Client {

	const SLUG = 'editcss';

	function __construct() {
		add_action( 'init', array( $this, 'handle_requests' ), 5 );
		add_action( 'admin_init', array( $this, 'add_meta_boxes' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'admin_enqueue_scripts' ) );

		// TODO: Server events
		add_action( 'mirror_push_css', array( $this, 'mirror_push_css' ) );
		add_action( 'mirror_pull_css', array( $this, 'mirror_pull_css' ) );
	}

	/**
	 * Handle request data
	 */
	function handle_requests() {
		if ( !isset( $_REQUEST['page'] ) || $_REQUEST['page'] != self::SLUG )
			return;

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( isset( $_POST['deploy'] ) ) {
			if ( check_admin_referer( 'safecss' ) )
				$this->deploycss();
		} elseif ( isset( $_POST['clone'] ) ) {
			if ( check_admin_referer( 'safecss' ) )
				$this->clonecss();
		} elseif ( isset( $_POST['merge'] ) ) {
			$query = http_build_query( array(
				'page' => self::SLUG
			));

			wp_safe_redirect( esc_url_raw( 'themes.php?' . $query ) );
		}
	}

	function add_meta_boxes() {
		global $mirror;
		$option = $mirror::OPTION;

		if ( get_option( $option . '_mode' ) )
			add_meta_box( self::SLUG, __( 'Mirror', 'mirror' ), array( $this, 'meta_box' ), 'editcss', 'side' );
	}

	/**
	 * Display a meta box with merge, deploy, and clone buttons
	 */
	function meta_box() {
		global $mirror;
		$option = $mirror::OPTION;
		$options = get_option( $option );
		?>

		<?php
			submit_button( __( 'Merge', 'mirror' ), 'secondary', 'merge', false );
			submit_button( __( 'Deploy', 'mirror' ), 'secondary', 'deploy', false );
			submit_button( __( 'Clone', 'mirror' ), 'secondary', 'clone', false );
		?>

		<?php
			if ( $options['site'] && $options['username'] && $options['password'] ) {
				$remote = $this->get_remote_css();
				$current = $this->get_css_post();
				$original = array_shift(get_posts(array(
					'numberposts' => 1,
					'post_type' => 'revision',
					'post_parent' => $current['ID'],
					'post_status' => 'inherit',
					'orderby' => 'post_date',
					'order' => 'ASC'
				)));
			}

			if ( !empty( $current ) && !empty( $original ) ):
				$original = $original->post_content;
				$current  = $current['post_content'];
				$remote   = $remote;

				// fix a merge conflict
				if ( $remote != $current && $remote != $original ): ?>
					<textarea id="f0" style="display:none"><?php echo esc_textarea( $original ); ?></textarea>
					<textarea id="f1" style="display:none"><?php echo esc_textarea( $current ); ?></textarea>
					<textarea id="f2" style="display:none"><?php echo esc_textarea( $remote ); ?></textarea>
				<?php endif; ?>
			<?php endif; ?>
<?php }

	/**
	 * Load diff3 scripts
	 */
	function admin_enqueue_scripts() {
		if ( !isset( $_REQUEST['page'] ) || $_REQUEST['page'] != self::SLUG )
			return;

		wp_enqueue_script('diff', plugins_url('diff.css', __FILE__));
		wp_enqueue_script('init', plugins_url('init.css', __FILE__), array('diff', 'jquery'));
	}

	/**
	 * Deploy css to remote server
	 */
	function deploycss() {
		global $mirror;
		$option = $mirror::OPTION;
		$options = get_option( $option );

		$css_post = $this->get_css_post();
		$css = isset( $_REQUEST['f3'] ) ? $_REQUEST['f3'] : $css_post['post_content'];

		$client = new WP_HTTP_IXR_Client( esc_url_raw( 'http://' . $options['site'] . '/xmlrpc.php' ) );
		$client->query( 'mirror.push', 'css', $options['username'], $options['password'], $css );

		if ( $client->isError() )
			return false;

		$this->clonecss();
	}

	/**
	 * Grab the local css post
	 */
	function get_css_post() {
		$args = array(
			'numberposts' => 1,
			'post_type' => 'safecss',
			'post_status' => 'publish',
		);

		if ( $post = array_shift( get_posts( $args ) ) )
			return get_object_vars( $post );

		return false;
	}

	/**
	 * Clone css from remote server
	 */
	function clonecss() {
		if ( $css = $this->get_css_post() )
			wp_delete_post( $css['ID'] );

		if ( $css = $this->get_remote_css() )
			$clone = $this->save_css_revision( $css );

		$query = http_build_query(array(
			'page' => self::SLUG
		));

		wp_safe_redirect( esc_url_raw( 'themes.php?' . $query ) );
		exit;
	}

	/**
	 * Retrieve remote css through XML-RPC
	 */
	function get_remote_css() {
		global $mirror;
		$option = $mirror::OPTION;
		$options = get_option( $option );

		$client = new WP_HTTP_IXR_Client( esc_url_raw( 'http://' . $options['site'] . '/xmlrpc.php' ) );
		$client->query( 'mirror.pull', 'css', $options['username'], $options['password'] );

		if ( $client->isError() ) {
			var_dump( $client->getErrorMessage() );
		}

		if ( $client->isError() )
			return false;

		return $client->getResponse();
	}

	/**
	 * Save a new revision of the css
	 */
	function save_css_revision( $css, $is_preview = false ) {

		if ( !$css_post = $this->get_css_post() ) {
			$post = array(
				'post_content' => $css,
				'post_status' => 'publish',
				'post_type' => 'safecss'
			);

			$post_id = wp_insert_post( $post );

			return true;
		}

		$css_post['post_content'] = $css;

		if ( false === $is_preview )
			return wp_update_post( $css_post );
	}

	// TODO
	function mirror_push_css( $css ) {
		return $this->save_css_revision( $css );
	}

	// TODO
	function mirror_pull_css() {
		$css_post = $this->get_css_post();
		return $css_post['post_content'];
	}

}

new Jetpack_CSS_Client();

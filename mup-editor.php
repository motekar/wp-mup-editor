<?php
/*
Plugin Name: MUP Editor
Plugin URI: http://wordpress.org/plugins/mup-editor/
Description: Code editor for Must-Use Plugins
Author: Motekar
Version: 0.3.0
Author URI: http://motekar.com/
*/

function mupe_menu_pages() {
	add_submenu_page( 'plugins.php', 'MU-Plugin Editor', 'MU-Plugin Editor', 'manage_options', 'mup-editor', 'mupe_editor_page' );
}
add_action( 'admin_menu', 'mupe_menu_pages' );

function mupe_editor_page() {
	global $file;

	$file = '';
	if ( isset( $_REQUEST['file'] ) ) {
		$file = wp_unslash( $_REQUEST['file'] );
	}

	$plugin_files = list_files( WPMU_PLUGIN_DIR );
	$files = [];
	foreach ($plugin_files as $f) {
		$files[] = str_replace( WPMU_PLUGIN_DIR . '/', '', $f );
	}
	if ( empty( $file ) ) {
		for ($i=0; $i < sizeof($files); $i++) { 
			if ( is_file( WPMU_PLUGIN_DIR . '/' . $files[$i]) ) {
				$file = $files[$i];
				break;
			}
		}
	}
	$real_file = WPMU_PLUGIN_DIR . '/' . $file;

	// Handle fallback editing of file when JavaScript is not available.
	$edit_error     = null;
	$posted_content = null;
	if ( 'POST' === $_SERVER['REQUEST_METHOD'] ) {
		$r = mupe_edit_plugin_file( wp_unslash( $_POST ) );
		if ( is_wp_error( $r ) ) {
			$edit_error = $r;
			if ( check_ajax_referer( 'edit-mu-plugin_' . $file, 'nonce', false ) && isset( $_POST['newcontent'] ) ) {
				$posted_content = wp_unslash( $_POST['newcontent'] );
			}
		} else {
			wp_redirect(
				add_query_arg(
					array(
						'a'      => 1, // This means "success" for some reason.
						'file'   => $file,
					),
		            admin_url( 'plugins.php?page=mup-editor', 'admin' )
				)
			);
			exit;
		}
	}

	if ( ! is_file( $real_file ) ) {
		wp_die( sprintf( '<p>%s</p>', __( 'No such file exists! Double check the name and try again.' ) ) );
	}

	$settings = array(
		'codeEditor' => wp_enqueue_code_editor( array( 'file' => $real_file ) ),
	);
	wp_enqueue_script( 'mup-editor', plugins_url( '/js/mup-editor.js', __FILE__ ), array( 'jquery', 'wp-util' ) );
	wp_add_inline_script( 'mup-editor', sprintf( 'jQuery( function( $ ) { mupe.editor.init( $( "#template" ), %s ); } )', wp_json_encode( $settings ) ) );

	if ( ! empty( $posted_content ) ) {
		$content = $posted_content;
	} else {
		$content = file_get_contents( $real_file );
	}

	if ( '.php' == substr( $real_file, strrpos( $real_file, '.' ) ) ) {
		$functions = wp_doc_link_parse( $content );

		if ( ! empty( $functions ) ) {
			$docs_select  = '<select name="docs-list" id="docs-list">';
			$docs_select .= '<option value="">' . __( 'Function Name&hellip;' ) . '</option>';
			foreach ( $functions as $function ) {
				$docs_select .= '<option value="' . esc_attr( $function ) . '">' . esc_html( $function ) . '()</option>';
			}
			$docs_select .= '</select>';
		}
	}

	$content = esc_textarea( $content );
?>
<div class="wrap">
	<h1>MU-Plugin Editor</h1>

<?php if ( isset( $_GET['a'] ) ) : ?>
	<div id="message" class="updated notice is-dismissible">
		<p><?php _e( 'File edited successfully.' ); ?></p>
	</div>
<?php elseif ( is_wp_error( $edit_error ) ) : ?>
	<div id="message" class="notice notice-error">
		<p><?php _e( 'There was an error while trying to update the file. You may need to fix something and try updating again.' ); ?></p>
		<pre><?php echo esc_html( $edit_error->get_error_message() ? $edit_error->get_error_message() : $edit_error->get_error_code() ); ?></pre>
	</div>
<?php endif; ?>

	<div id="templateside">
		<h2 id="plugin-files-label">MU-Plugin Files</h2>
		<ul role="tree" aria-labelledby="plugin-files-label">
			<li role="treeitem" tabindex="-1" aria-expanded="true" aria-level="1" aria-posinset="1" aria-setsize="1">
				<ul role="group">
					<?php mupe_print_file_tree( mupe_make_file_tree( $files ) ); ?>
				</ul>
			</li>
		</ul>
	</div>
	<form action="" id="template" method="post">
    	<?php wp_nonce_field( 'edit-mu-plugin_' . $file, 'nonce' ); ?>
		<div>
			<label for="newcontent" id="theme-plugin-editor-label"><?php _e( 'Selected file content:' ); ?></label>
			<textarea cols="70" rows="25" name="newcontent" id="newcontent" aria-describedby="editor-keyboard-trap-help-1 editor-keyboard-trap-help-2 editor-keyboard-trap-help-3 editor-keyboard-trap-help-4"><?php echo $content; ?></textarea>
			<input type="hidden" name="action" value="update" />
			<input type="hidden" name="file" value="<?php echo esc_attr( $file ); ?>" />
		</div>
		<?php if ( ! empty( $docs_select ) ) : ?>
		<div id="documentation" class="hide-if-no-js"><label for="docs-list"><?php _e( 'Documentation:' ); ?></label> <?php echo $docs_select; ?> <input disabled id="docs-lookup" type="button" class="button" value="<?php esc_attr_e( 'Look Up' ); ?> " onclick="if ( '' != jQuery('#docs-list').val() ) { window.open( 'https://api.wordpress.org/core/handbook/1.0/?function=' + escape( jQuery( '#docs-list' ).val() ) + '&amp;locale=<?php echo urlencode( get_user_locale() ); ?>&amp;version=<?php echo urlencode( get_bloginfo( 'version' ) ); ?>&amp;redirect=true'); }" /></div>
		<?php endif; ?>
		<div class="editor-notices">
		</div>
		<p class="submit">
			<?php submit_button( __( 'Update File' ), 'primary', 'submit', false ); ?>
			<span class="spinner"></span>
		</p>
		<?php wp_print_file_editor_templates(); ?>
	</form>
</div>
<?php
}

function mupe_make_file_tree( $files ) {
	$tree = [];
	foreach ( $files as $file ) {
		$list = explode( '/', $file );
		$last_dir = &$tree;
		foreach ( $list as $dir ) {
			$last_dir = &$last_dir[ $dir ];
		}
		$last_dir = $file;
	}
	return $tree;
}

function mupe_print_file_tree( $tree, $label = '', $level = 2, $size = 1, $index = 1 ) {
	global $file;

    if ( is_array( $tree ) ) {
        $index = 0;
        $size  = count( $tree );
        foreach ( $tree as $label => $plugin_file ) :
            $index++;
            if ( ! is_array( $plugin_file ) ) {
                mupe_print_file_tree( $plugin_file, $label, $level, $index, $size );
                continue;
            }
            ?>
            <li role="treeitem" aria-expanded="true" tabindex="-1"
                aria-level="<?php echo esc_attr( $level ); ?>"
                aria-setsize="<?php echo esc_attr( $size ); ?>"
                aria-posinset="<?php echo esc_attr( $index ); ?>">
                <span class="folder-label"><?php echo esc_html( $label ); ?> <span class="screen-reader-text"><?php _e( 'folder' ); ?></span><span aria-hidden="true" class="icon"></span></span>
                <ul role="group" class="tree-folder"><?php mupe_print_file_tree( $plugin_file, '', $level + 1, $index, $size ); ?></ul>
            </li>
            <?php
        endforeach;
    } else {
        $url = add_query_arg(
            array(
                'file'   => rawurlencode( $tree ),
            ),
            admin_url( 'plugins.php?page=mup-editor', 'admin' )
        );
        ?>
        <li role="none" class="<?php echo esc_attr( $file === $tree ? 'current-file' : '' ); ?>">
            <a role="treeitem" tabindex="<?php echo esc_attr( $file === $tree ? '0' : '-1' ); ?>"
                href="<?php echo esc_url( $url ); ?>"
                aria-level="<?php echo esc_attr( $level ); ?>"
                aria-setsize="<?php echo esc_attr( $size ); ?>"
                aria-posinset="<?php echo esc_attr( $index ); ?>">
                <?php
                if ( $file === $tree ) {
                    echo '<span class="notice notice-info">' . esc_html( $label ) . '</span>';
                } else {
                    echo esc_html( $label );
                }
                ?>
            </a>
        </li>
        <?php
    }
}

function mupe_edit_plugin_file( $args ) {
	if ( empty( $args['file'] ) ) {
		return new WP_Error( 'missing_file' );
	}
	$file = $args['file'];
	if ( 0 !== validate_file( $file ) ) {
		return new WP_Error( 'bad_file' );
	}

	if ( ! isset( $args['newcontent'] ) ) {
		return new WP_Error( 'missing_content' );
	}
	$content = $args['newcontent'];

	if ( ! isset( $args['nonce'] ) ) {
		return new WP_Error( 'missing_nonce' );
	}

	$real_file = null;
	if ( ! current_user_can( 'edit_plugins' ) ) {
		return new WP_Error( 'unauthorized', __( 'Sorry, you are not allowed to edit plugins for this site.' ) );
	}

	if ( ! wp_verify_nonce( $args['nonce'], 'edit-mu-plugin_' . $file ) ) {
		return new WP_Error( 'nonce_failure' );
	}

	$editable_extensions = array(
		'bash',
		'conf',
		'css',
		'diff',
		'htm',
		'html',
		'http',
		'inc',
		'include',
		'js',
		'json',
		'jsx',
		'less',
		'md',
		'patch',
		'php',
		'php3',
		'php4',
		'php5',
		'php7',
		'phps',
		'phtml',
		'sass',
		'scss',
		'sh',
		'sql',
		'svg',
		'text',
		'txt',
		'xml',
		'yaml',
		'yml',
	);

	$real_file = WPMU_PLUGIN_DIR . '/' . $file;

	// Ensure file is real.
	if ( ! is_file( $real_file ) ) {
		return new WP_Error( 'file_does_not_exist', __( 'No such file exists! Double check the name and try again.' ) );
	}

	// Ensure file extension is allowed.
	$extension = null;
	if ( preg_match( '/\.([^.]+)$/', $real_file, $matches ) ) {
		$extension = strtolower( $matches[1] );
		if ( ! in_array( $extension, $editable_extensions, true ) ) {
			return new WP_Error( 'illegal_file_type', __( 'Files of this type are not editable.' ) );
		}
	}

	$previous_content = file_get_contents( $real_file );

	if ( ! is_writeable( $real_file ) ) {
		return new WP_Error( 'file_not_writable' );
	}

	$f = fopen( $real_file, 'w+' );
	if ( false === $f ) {
		return new WP_Error( 'file_not_writable' );
	}

	$written = fwrite( $f, $content );
	fclose( $f );
	if ( false === $written ) {
		return new WP_Error( 'unable_to_write', __( 'Unable to write to file.' ) );
	}
	if ( 'php' === $extension && function_exists( 'opcache_invalidate' ) ) {
		opcache_invalidate( $real_file, true );
	}

	if ( 'php' === $extension ) {

		$scrape_key = md5( rand() );
		$transient = 'scrape_key_' . $scrape_key;
		$scrape_nonce = strval( rand() );
		set_transient( $transient, $scrape_nonce, 60 );

		$scrape_params = array(
			'wp_scrape_key'   => $scrape_key,
			'wp_scrape_nonce' => $scrape_nonce,
		);
		$headers       = array(
			'Cache-Control' => 'no-cache',
		);

		$needle_start = "###### wp_scraping_result_start:$scrape_key ######";
		$needle_end   = "###### wp_scraping_result_end:$scrape_key ######";

		$url = add_query_arg( array( 'mup-test' => $file ), home_url( '/' ) );
		$url = add_query_arg( $scrape_params, $url );
		$r = wp_remote_get( $url, compact( $headers ) );
		$body = wp_remote_retrieve_body( $r );
		$scrape_result_position = strpos( $body, $needle_start );

		$loopback_request_failure = array(
			'code'    => 'loopback_request_failed',
			'message' => __( 'Unable to communicate back with site to check for fatal errors, so the PHP change was reverted. You will need to upload your PHP file change by some other means, such as by using SFTP.' ),
		);
		$json_parse_failure       = array(
			'code' => 'json_parse_error',
		);

		$result = null;
		if ( false === $scrape_result_position ) {
			$result = $loopback_request_failure;
		} else {
			$error_output = substr( $body, $scrape_result_position + strlen( $needle_start ) );
			$error_output = substr( $error_output, 0, strpos( $error_output, $needle_end ) );
			$result       = json_decode( trim( $error_output ), true );
			if ( empty( $result ) ) {
				$result = $json_parse_failure;
			}
		}

		delete_transient( $transient );

		if ( true !== $result ) {
			// Roll-back file change.
			file_put_contents( $real_file, $previous_content );
			if ( function_exists( 'opcache_invalidate' ) ) {
				opcache_invalidate( $real_file, true );
			}

			if ( ! isset( $result['message'] ) ) {
				$message = __( 'Something went wrong.' );
			} else {
				$message = $result['message'];
				unset( $result['message'] );
			}
			return new WP_Error( 'php_error', $message, $result );
		}
	}

	return true;
}

function mupe_ajax_edit_plugin_file() {
	$r = mupe_edit_plugin_file( wp_unslash( $_POST ) );
	if ( is_wp_error( $r ) ) {
		wp_send_json_error(
			array_merge(
				array(
					'code'    => $r->get_error_code(),
					'message' => $r->get_error_message(),
				),
				(array) $r->get_error_data()
			)
		);
	} else {
		wp_send_json_success(
			array(
				'message' => __( 'File edited successfully.' ),
			)
		);
	}
}
add_action( 'wp_ajax_mupe-edit-plugin-file', 'mupe_ajax_edit_plugin_file' );

add_action( 'plugins_loaded', function() {
	if ( isset( $_GET['mup-test'] ) ) {
		$mup_test = wp_filter_nohtml_kses( $_GET['mup-test'] );
		$file = WPMU_PLUGIN_DIR . '/' . $mup_test;
		if ( is_file( $file ) ) {
			include $file;
			die();
		}
		die('file_not_found');
	}
} );
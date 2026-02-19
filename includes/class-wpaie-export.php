<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPAIE_Export {

	public function __construct() {
        // AJAX Hooks
		add_action( 'wp_ajax_wpaie_init_export', array( $this, 'ajax_init_export' ) );
		add_action( 'wp_ajax_wpaie_export_step', array( $this, 'ajax_export_step' ) );
		add_action( 'wp_ajax_wpaie_export_finish', array( $this, 'ajax_export_finish' ) );
        
        // Download Handler (No priv check needed if nonce is valid, but we add caps check)
        add_action( 'wp_ajax_wpaie_download_file', array( $this, 'ajax_download_file' ) );
	}

    public function ajax_download_file() {
        // Verify nonce
        // Note: JS passes generic nonce 'wpaie_ajax_nonce'
        if ( ! isset( $_GET['nonce'] ) || ! wp_verify_nonce( $_GET['nonce'], 'wpaie_ajax_nonce' ) ) {
            wp_die( 'Security check failed' );
        }
        
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Permission denied' );
        }

        $filename = isset( $_GET['file'] ) ? sanitize_file_name( $_GET['file'] ) : '';
        $upload_dir = wp_upload_dir();
        $file_path = $upload_dir['basedir'] . '/wpaie-exports/' . $filename;
        
        if ( ! file_exists( $file_path ) ) {
            wp_die( 'File not found.' );
        }
        
        if ( ob_get_length() ) {
            ob_end_clean();
        }
        
        header( 'Content-Description: File Transfer' );
        header( 'Content-Type: application/octet-stream' ); // Force download better than json type sometimes
        header( 'Content-Disposition: attachment; filename="' . basename( $file_path ) . '"' );
        header( 'Content-Length: ' . filesize( $file_path ) );
        header( 'Pragma: public' );
        header( 'Expires: 0' );
        header( 'Cache-Control: must-revalidate' );
        
        readfile( $file_path );
        exit;
    }

    private function get_query_args( $post_data ) {
		$content_types = isset( $post_data['content_types'] ) ? $post_data['content_types'] : array();
		$start_date    = ! empty( $post_data['start_date'] ) ? $post_data['start_date'] : null;
		$end_date      = ! empty( $post_data['end_date'] ) ? $post_data['end_date'] : null;
		$post_status   = isset( $post_data['post_status'] ) && $post_data['post_status'] !== 'any' ? $post_data['post_status'] : 'any';

		$args = array(
			'post_type'      => $content_types,
			'post_status'    => $post_status,
            'posts_per_page' => -1,
            'fields'         => 'ids', // Optimization for counting
		);

		if ( $start_date || $end_date ) {
			$args['date_query'] = array();
			if ( $start_date ) {
				$args['date_query'][] = array( 'after' => $start_date, 'inclusive' => true );
			}
			if ( $end_date ) {
				$args['date_query'][] = array( 'before' => $end_date, 'inclusive' => true );
			}
		}
        
        return $args;
    }

	public function ajax_init_export() {
		check_ajax_referer( 'wpaie_export_action', 'wpaie_export_nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Permission denied' );
		}

        $args = $this->get_query_args( $_POST );
        
        // Count Query
        $query = new WP_Query( $args );
        $total = $query->found_posts;
        
        // Breakdown Count
        $counts = array();
        if ( isset( $_POST['content_types'] ) ) {
            foreach( $_POST['content_types'] as $type ) {
                $type_args = $args;
                $type_args['post_type'] = $type;
                $type_query = new WP_Query($type_args);
                $counts[$type] = $type_query->found_posts;
            }
        }

        // Initialize File
		$upload_dir = wp_upload_dir();
        $base_dir = $upload_dir['basedir'] . '/wpaie-exports';
        
        if ( ! file_exists( $base_dir ) ) {
            wp_mkdir_p( $base_dir );
        }
        
        // Use a random filename or based on date
		$filename = 'wpaie-export-' . date( 'Y-m-d-H-i-s' ) . '-' . wp_generate_password( 6, false ) . '.json';
        $file_path = $base_dir . '/' . $filename;
        
        // Write Header
        $header_data = array(
			'site_url' => site_url(),
			'generated_at' => current_time( 'mysql' ),
			'posts' => array(), // Start the array, but we will hack the JSON structure manually to stream
		);
        // We write "{"site_url": "...", ..., "posts": ["
        $json_start = json_encode( $header_data );
        // Remove the last "]}" to leave the array open
        // Standard json_encode output: {"a":1,"posts":[]}
        // We want: {"a":1,"posts":[
        $json_start = substr( $json_start, 0, -2 ); // remove ]}
        
        file_put_contents( $file_path, $json_start );

		wp_send_json_success( array( 
            'total' => $total, 
            'batch_size' => 50,
            'file_name' => $filename,
            'counts' => $counts
        ) );
	}

    public function ajax_export_step() {
        // Validation...
        $page = isset( $_POST['page'] ) ? intval( $_POST['page'] ) : 1;
        $filename = isset( $_POST['file_name'] ) ? sanitize_file_name( $_POST['file_name'] ) : '';
        
        $upload_dir = wp_upload_dir();
        $file_path = $upload_dir['basedir'] . '/wpaie-exports/' . $filename;
        
        if ( ! file_exists( $file_path ) ) {
            wp_send_json_error( 'Export file not found.' );
        }
        
        $args = $this->get_query_args( $_POST );
        $args['posts_per_page'] = 50; // Batch Size
        $args['paged'] = $page;
        $args['fields'] = 'all'; // Get full objects
        
        $query = new WP_Query( $args );
        $posts_data = array();
        
        $include_terms    = isset( $_POST['include_terms'] );
		$include_meta     = isset( $_POST['include_meta'] );
        $include_comments = isset( $_POST['include_comments'] );
        $include_images   = isset( $_POST['include_images'] );
        $content_types    = isset( $_POST['content_types'] ) ? $_POST['content_types'] : array();

        if ( $query->have_posts() ) {
            while ( $query->have_posts() ) {
                $query->the_post();
                global $post;
                
                // ... (Logic from previous implementation) ...
                $post_data = array(
					'ID'           => $post->ID,
					'post_title'   => $post->post_title,
					'post_content' => $post->post_content,
					'post_excerpt' => $post->post_excerpt,
					'post_date'    => $post->post_date,
					'post_type'    => $post->post_type,
					'post_status'  => $post->post_status,
					'post_name'    => $post->post_name,
					'post_parent'  => $post->post_parent,
				);

				if ( $include_meta ) {
					$post_data['meta'] = get_post_meta( $post->ID );
				}

				if ( $include_terms ) {
					$taxonomies = get_object_taxonomies( $post->post_type );
					$post_data['terms'] = array();
					foreach ( $taxonomies as $taxonomy ) {
						$terms = wp_get_post_terms( $post->ID, $taxonomy );
						if ( ! empty( $terms ) && ! is_wp_error( $terms ) ) {
							foreach ( $terms as $term ) {
								$post_data['terms'][ $taxonomy ][] = array(
									'slug' => $term->slug,
									'name' => $term->name,
								);
							}
						}
					}
				}

				if ( $include_comments ) {
					$comments = get_comments( array( 'post_id' => $post->ID ) );
					$post_data['comments'] = array();
					foreach ( $comments as $comment ) {
						$post_data['comments'][] = (array) $comment;
					}
				}

				if ( $include_images && has_post_thumbnail( $post->ID ) ) {
					$img_url = get_the_post_thumbnail_url( $post->ID, 'full' );
					if($img_url) {
						$post_data['featured_image_url'] = $img_url;
					}
				}
                
                $posts_data[] = $post_data;
            }
        }
        
        // Append to file
        $json_chunk = json_encode( $posts_data );
        // $json_chunk is [ {...}, {...} ]. We want to append content inside keys.
        // Strip [ and ]
        $json_chunk = substr( $json_chunk, 1, -1 );
        
        if ( ! empty( $json_chunk ) ) {
            // If not first page (or even if first page, but we already wrote 'posts': [ )
            // Wait, if it's the FIRST batch, we don't need a comma BEFORE it.
            // If page > 1, we need comma.
            if ( $page > 1 ) {
                 file_put_contents( $file_path, ',' . $json_chunk, FILE_APPEND );
            } else {
                 file_put_contents( $file_path, $json_chunk, FILE_APPEND );
            }
        }
        
        wp_send_json_success();
    }
    
    public function ajax_export_finish() {
        $filename = isset( $_POST['file_name'] ) ? sanitize_file_name( $_POST['file_name'] ) : '';
        $upload_dir = wp_upload_dir();
        $file_path = $upload_dir['basedir'] . '/wpaie-exports/' . $filename;
        $file_url = $upload_dir['baseurl'] . '/wpaie-exports/' . $filename;
        
        if ( file_exists( $file_path ) ) {
            // Close the array and object
            file_put_contents( $file_path, ']}', FILE_APPEND );
            wp_send_json_success( array( 'url' => $file_url ) );
        } else {
            wp_send_json_error( 'File not found' );
        }
    }
}

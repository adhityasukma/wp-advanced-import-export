<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPAIE_Import {

	public function __construct() {
		add_action( 'wp_ajax_wpaie_init_import', array( $this, 'ajax_init_import' ) );
		add_action( 'wp_ajax_wpaie_import_step', array( $this, 'ajax_import_step' ) );
        add_action( 'wp_ajax_wpaie_import_cleanup', array( $this, 'ajax_import_cleanup' ) );
        add_action( 'wp_ajax_wpaie_retry_image', array( $this, 'ajax_retry_image' ) );
	}
    
    public function ajax_retry_image() {
        if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Permission denied' );
		}
        
        $post_id = isset( $_POST['post_id'] ) ? intval( $_POST['post_id'] ) : 0;
        $url = isset( $_POST['url'] ) ? esc_url_raw( $_POST['url'] ) : ''; // esc_url_raw handles spaces too
        
        // Retrying means we might need to handle spaces again if esc_url_raw didn't fix it fully for download_url
        // But sideload_image_robust does the space replacement.
        // We get the stored meta URL which might be raw.
        if ( ! $url ) {
            $url = get_post_meta( $post_id, '_wpaie_failed_image_url', true );
        }
        
        if ( ! $post_id || ! $url ) {
            wp_send_json_error( 'Missing data.' );
        }
        
        // Ensure media functions are loaded
        require_once( ABSPATH . 'wp-admin/includes/media.php' );
        require_once( ABSPATH . 'wp-admin/includes/file.php' );
        require_once( ABSPATH . 'wp-admin/includes/image.php' );
        
        // Attempt download
        $attach_id = $this->sideload_image_robust( $url, $post_id, get_the_title( $post_id ) );
        
        if ( is_wp_error( $attach_id ) ) {
            wp_send_json_error( $attach_id->get_error_message() );
        }
        
        set_post_thumbnail( $post_id, $attach_id );
        
        wp_send_json_success( array( 
            'message' => 'Image successfully set.',
            'attach_id' => $attach_id
        ));
    }

	public function ajax_init_import() {
		check_ajax_referer( 'wpaie_import_action', 'wpaie_import_nonce' ); // Or generic nonce

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Permission denied' );
		}

		if ( empty( $_FILES['import_file']['tmp_name'] ) ) {
			wp_send_json_error( 'Please upload a file.' );
		}

        $upload_dir = wp_upload_dir();
        $base_dir = $upload_dir['basedir'] . '/wpaie-imports';
        if ( ! file_exists( $base_dir ) ) {
            wp_mkdir_p( $base_dir );
        }

        // Move to persistent location
        $import_id = 'import-' . date('YmdHis') . '-' . wp_generate_password(6, false);
        $target_file = $base_dir . '/' . $import_id . '.json';
        
        if ( ! move_uploaded_file( $_FILES['import_file']['tmp_name'], $target_file ) ) {
             wp_send_json_error( 'Failed to save uploaded file.' );
        }

		$file_content = file_get_contents( $target_file );
		$data = json_decode( $file_content, true );

		if ( ! $data || empty( $data['posts'] ) ) {
			wp_send_json_error( 'Invalid file format or empty data.' );
		}
        
        $total = count( $data['posts'] );

		wp_send_json_success( array(
            'total' => $total,
            'batch_size' => 1, // Process one by one for granular progress updates
            'import_id' => $import_id
        ) );
	}

    public function ajax_import_step() {
        if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Permission denied' );
		}
        
        $page = isset( $_POST['page'] ) ? intval( $_POST['page'] ) : 1;
        $import_id = isset( $_POST['import_id'] ) ? sanitize_file_name( $_POST['import_id'] ) : '';
        $batch_size = 1; // Process one by one for granular progress updates
        
        $upload_dir = wp_upload_dir();
        $target_file = $upload_dir['basedir'] . '/wpaie-imports/' . $import_id . '.json';
        
        if ( ! file_exists( $target_file ) ) {
            wp_send_json_error( 'Import file not found.' );
        }
        
        // Read Data (Inefficient for huge files, but standard for plugins)
        $data = json_decode( file_get_contents( $target_file ), true );
        $all_posts = $data['posts'];
        $source_url = isset( $data['site_url'] ) ? $data['site_url'] : '';
        
        $offset = ( $page - 1 ) * $batch_size;
        $batch_posts = array_slice( $all_posts, $offset, $batch_size );
        
        $download_images = isset( $_POST['download_images'] );
		$replace_urls    = isset( $_POST['replace_urls'] );
		$author_map      = isset( $_POST['author_map'] ) ? $_POST['author_map'] : 'current';
		$current_user_id = get_current_user_id();
        
        // Offset for logging (passed from JS or calculated)
        $processed_offset = isset($_POST['processed_offset']) ? intval($_POST['processed_offset']) : $offset;
        $total_items = count($all_posts); // Re-count for display "1/263"
        
        // Helpers
		require_once( ABSPATH . 'wp-admin/includes/media.php' );
		require_once( ABSPATH . 'wp-admin/includes/file.php' );
		require_once( ABSPATH . 'wp-admin/includes/image.php' );
        require_once( ABSPATH . 'wp-admin/includes/post.php' ); // For post_exists
        
        $logs = array();
        $processed_in_batch = 0;

        foreach ( $batch_posts as $post_data ) {
            $processed_in_batch++;
            $current_item_num = $processed_offset + $processed_in_batch;
            
            // Detailed Log Header
            $logs[] = "<strong>Processing {$current_item_num}/{$total_items}:</strong> " . esc_html($post_data['post_title']);
            $logs[] = "&nbsp;&nbsp;- Post Type: " . $post_data['post_type'];
            $logs[] = "&nbsp;&nbsp;- Post Status: " . $post_data['post_status'];

            // Prepare Post
            $post_args = array(
				'post_title'    => $post_data['post_title'],
				'post_content'  => $post_data['post_content'],
				'post_excerpt'  => $post_data['post_excerpt'],
				'post_status'   => $post_data['post_status'],
				'post_type'     => $post_data['post_type'],
				'post_date'     => $post_data['post_date'],
				'post_name'     => $post_data['post_name'],
				'post_author'   => $current_user_id, 
			);
            
            // (URL replacements and Image processing moved to after wp_insert_post to allow correct linking)
            
            // Checks for duplications
            if ( $post_data['post_type'] !== 'attachment' ) {
                 global $wpdb;
                 $exists_id = 0;

                 if ( ! empty( $post_data['post_name'] ) ) {
                     // 1. Primary Check: By Slug (post_name)
                     $exists_id = $wpdb->get_var( $wpdb->prepare(
                         "SELECT ID FROM $wpdb->posts WHERE post_name = %s AND post_type = %s AND post_status != 'trash' LIMIT 1",
                         $post_data['post_name'],
                         $post_data['post_type']
                     ) );
                     $found_via = "Slug match";
                 } else {
                     // 2. Fallback for Drafts: By Title + Date
                     // If no slug, we check if title and date match exactly to avoid duplicates on re-import
                     $exists_id = $wpdb->get_var( $wpdb->prepare(
                         "SELECT ID FROM $wpdb->posts WHERE post_title = %s AND post_date = %s AND post_type = %s AND post_status != 'trash' LIMIT 1",
                         $post_data['post_title'],
                         $post_data['post_date'],
                         $post_data['post_type']
                     ) );
                     $found_via = "Title & Date match";
                 }
                 
                 if ( $exists_id ) {
                     $edit_link = get_edit_post_link( $exists_id );
                     $logs[] = "&nbsp;&nbsp;<span style='color:orange;'>- [" . esc_html($post_data['post_title']) . "] already exists ($found_via). Skipped. <a href='$edit_link' target='_blank'>[View ID: $exists_id]</a></span>";
                     $logs[] = "<hr style='margin: 5px 0; border: 0; border-top: 1px dashed #ccc;'>";
                     continue;
                 }
            }
            
            // Handle Attachment Type
            if ( 'attachment' === $post_data['post_type'] ) {
                if ( $download_images && ! empty( $post_data['attachment_url'] ) ) {
                    $logs[] = "&nbsp;&nbsp;- Downloading attachment: " . basename($post_data['attachment_url']);
                    $attach_id = $this->sideload_image_robust( $post_data['attachment_url'], 0, $post_data['post_title'] );
                    if ( is_wp_error( $attach_id ) ) {
                        $logs[] = "&nbsp;&nbsp;<span style='color:red;'>- Failed: " . $attach_id->get_error_message() . "</span>";
                    } else {
                        $logs[] = "&nbsp;&nbsp;<span style='color:green;'>- Success (ID: $attach_id)</span>";
                    }
                }
                continue;
            }
            
            // Insert Post
            $new_post_id = wp_insert_post( $post_args );
            
            if ( ! is_wp_error( $new_post_id ) && $new_post_id > 0 ) {
                $logs[] = "&nbsp;&nbsp;<span style='color:green;'>- Created Post ID: " . $new_post_id . "</span>";

                // Taxonomies
                if ( ! empty( $post_data['terms'] ) ) {
					foreach ( $post_data['terms'] as $taxonomy => $terms ) {
						$term_ids = array();
						foreach ( $terms as $term_info ) {
							if ( ! term_exists( $term_info['name'], $taxonomy ) ) {
								$inserted_term = wp_insert_term( $term_info['name'], $taxonomy, array( 'slug' => $term_info['slug'] ) );
								if ( ! is_wp_error( $inserted_term ) ) {
									$term_ids[] = $inserted_term['term_id'];
                                    $logs[] = "&nbsp;&nbsp;- Created Term: " . $term_info['name'] . " ($taxonomy)";
								}
							} else {
								$existing_term = get_term_by( 'name', $term_info['name'], $taxonomy );
								if ( $existing_term ) {
									$term_ids[] = $existing_term->term_id;
								}
							}
						}
						wp_set_post_terms( $new_post_id, $term_ids, $taxonomy );
					}
				}
                
                // Meta
                if ( ! empty( $post_data['meta'] ) ) {
                    $meta_count = 0;
					foreach ( $post_data['meta'] as $key => $values ) {
						foreach ( $values as $value ) {
                            // 1. Unserialize first to avoid breaking serialized strings during string replace
                            $value = maybe_unserialize( $value );

							if ( $replace_urls && ! empty( $source_url ) ) {
								$value = $this->recursive_url_replace( $value, $source_url, site_url() );
							}
							add_post_meta( $new_post_id, $key, $value );
                            $meta_count++;
						}
					}
                    $logs[] = "&nbsp;&nbsp;- Imported $meta_count Custom Fields (with Serialized Data handling)";
				}
                
                // Comments
                if ( ! empty( $post_data['comments'] ) ) {
                    $comment_count = 0;
					foreach ( $post_data['comments'] as $comment_data ) {
						$comment_data['comment_post_ID'] = $new_post_id;
						unset( $comment_data['comment_ID'] ); 
						wp_insert_comment( $comment_data );
                        $comment_count++;
					}
                    $logs[] = "&nbsp;&nbsp;- Imported $comment_count Comments";
				}
                
                // Post Content Processing (AFTER post insertion)
                $content_needs_update = false;
                $current_content = $post_data['post_content'];

                // 1. Download images found inside content and replace with local URLs
                if ( $download_images && ! empty( $current_content ) ) {
                    $new_content = $this->import_images_from_content( $current_content, $new_post_id );
                    if ( $new_content !== $current_content ) {
                        $current_content = $new_content;
                        $content_needs_update = true;
                        $logs[] = "&nbsp;&nbsp;- Content images processed and attached.";
                    }
                }

                // 2. Global URL Replacement
                if ( $replace_urls && ! empty( $source_url ) && ! empty( $current_content ) ) {
                    $new_content = str_replace( $source_url, site_url(), $current_content );
                    if ( $new_content !== $current_content ) {
                        $current_content = $new_content;
                        $content_needs_update = true;
                        $logs[] = "&nbsp;&nbsp;- Global URL replacement applied to content.";
                    }
                }

                if ( $content_needs_update ) {
                    wp_update_post( array(
                        'ID'           => $new_post_id,
                        'post_content' => $current_content,
                    ) );
                }

                // Featured Image
                if ( $download_images && ! empty( $post_data['featured_image_url'] ) ) {
                    $logs[] = "&nbsp;&nbsp;- Fetching featured image...";
					$img_id = $this->sideload_image_robust( $post_data['featured_image_url'], $new_post_id, null );
					if ( ! is_wp_error( $img_id ) ) {
						set_post_thumbnail( $new_post_id, $img_id );
                        $logs[] = "&nbsp;&nbsp;<span style='color:green;'>- Featured image set (ID: $img_id).</span>";
					} else {
                        $logs[] = "&nbsp;&nbsp;<span style='color:red;'>- Featured image failed: " . $img_id->get_error_message() . "</span>";
                    }
				}
            } else {
                 $msg = is_wp_error( $new_post_id ) ? $new_post_id->get_error_message() : 'Unknown error (returned 0)';
                 $logs[] = "&nbsp;&nbsp;<span style='color:red;'>- Failed to insert post: " . $msg . "</span>";
            }
            
            $logs[] = "<hr style='margin: 5px 0; border: 0; border-top: 1px dashed #ccc;'>"; // Separator
        }
        
        wp_send_json_success( array( 
            'logs' => $logs,
            'processed_count' => $processed_in_batch 
        ) );
    }
    
    public function ajax_import_cleanup() {
        $import_id = isset( $_POST['import_id'] ) ? sanitize_file_name( $_POST['import_id'] ) : '';
        $upload_dir = wp_upload_dir();
        $target_file = $upload_dir['basedir'] . '/wpaie-imports/' . $import_id . '.json';
        if ( file_exists( $target_file ) ) {
            unlink( $target_file );
        }
        wp_send_json_success();
    }
    
    // Custom Robust Image Downloader
    public function sideload_image_robust( $url, $post_id, $desc ) {
        // 0. Clean IDN/Special Chars in URL
        // Simple space replace is not enough for things like 'DALL·E' (%C2%B7)
        // We need to ensure the path is correctly encoded, but preserve scheme/host.
        
        $parts = parse_url( $url );
        if ( ! empty( $parts['path'] ) ) {
            // Explode path by / and encode each segment
            $path_parts = explode( '/', $parts['path'] );
            $encoded_path_parts = array_map( 'rawurlencode', $path_parts );
            $encoded_path = implode( '/', $encoded_path_parts );
            
            // Rebuild URL
            $url = ( isset($parts['scheme']) ? $parts['scheme'] : 'http' ) . '://' 
                 . ( isset($parts['host']) ? $parts['host'] : '' ) 
                 . $encoded_path;
                 
            if ( ! empty( $parts['query'] ) ) {
                $url .= '?' . $parts['query'];
            }
        } else {
             // Fallback for simple space fix if parse_url fails slightly
             $url = str_replace( ' ', '%20', $url );
        }
        
        // 1. Check if valid URL
        if ( ! filter_var( $url, FILTER_VALIDATE_URL ) ) {
             $error_code = empty( $url ) ? 'empty_url' : 'invalid_url';
             $error_msg  = empty( $url ) ? 'URL is empty' : 'Invalid URL format';
             
             if ( $post_id ) {
                update_post_meta( $post_id, '_wpaie_failed_image_url', $url ); // Save the attempted URL
                update_post_meta( $post_id, '_wpaie_image_error', $error_msg );
             }
             return new WP_Error( $error_code, $error_msg );
        }

        // 1.5 PRE-ANALYZE FILENAME (for Deduplication Check)
        $path_only = parse_url( $url, PHP_URL_PATH );
        $raw_filename = basename( $path_only );
        $decoded_filename = urldecode( $raw_filename );
        
        $info = pathinfo( $decoded_filename );
        $original_base = ! empty( $info['filename'] ) ? $info['filename'] : 'image-' . uniqid();
        $original_ext = ! empty( $info['extension'] ) ? strtolower( $info['extension'] ) : 'jpg';

        // Sanitize filename for filesystem safety (preserves original name as much as possible)
        $check_base = sanitize_file_name( $original_base );
        // Keep full original filename, only use a light truncation for extremely long names (255 char filesystem limit)
        if ( strlen( $check_base ) > 200 ) {
            $check_base = substr( $check_base, 0, 200 );
        }
        $final_check_filename = $check_base . '.' . $original_ext;

        if ( ! empty( $final_check_filename ) ) {
            global $wpdb;
            // Search for attachments by exact filename (matching after the last slash in path)
            // This ensures we find 'image.png' whether it's in '2025/01/image.png' or '2026/02/image.png'
            $attachment_id = $wpdb->get_var( $wpdb->prepare(
                "SELECT post_id FROM $wpdb->postmeta 
                 WHERE meta_key = '_wp_attached_file' 
                 AND (meta_value = %s OR meta_value LIKE %s) 
                 LIMIT 1",
                $final_check_filename,
                '%/' . $wpdb->esc_like( $final_check_filename )
            ) );

            if ( $attachment_id ) {
                // Attach to current post if provided
                if ( $post_id ) {
                    wp_update_post( array(
                        'ID'          => $attachment_id,
                        'post_parent' => $post_id,
                    ) );

                    delete_post_meta( $post_id, '_wpaie_failed_image_url' );
                    delete_post_meta( $post_id, '_wpaie_image_error' );
                }
                return (int) $attachment_id;
            }
        }

        // 2. Download to temp file (If not found in DB)
        $tmp = download_url( $url );
        if ( is_wp_error( $tmp ) ) {
            // Save failure meta if post_id is provided
            if ( $post_id ) {
                update_post_meta( $post_id, '_wpaie_failed_image_url', $url );
                update_post_meta( $post_id, '_wpaie_image_error', $tmp->get_error_message() );
            }
            return $tmp;
        }

        // 3. Analyze File Type & Fix Extension (Actual Save)
        $file_path = $tmp;
        $mime_type = mime_content_type( $file_path );
        
        // Map common mimes to extensions
        $mime_to_ext = array(
            'image/jpeg' => 'jpg',
            'image/jpg'  => 'jpg',
            'image/png'  => 'png',
            'image/gif'  => 'gif',
            'image/webp' => 'webp',
            'image/bmp'  => 'bmp',
            'image/tiff' => 'tif',
            'image/x-icon' => 'ico',
            'image/svg+xml' => 'svg',
        );

        $real_ext = isset( $mime_to_ext[ $mime_type ] ) ? $mime_to_ext[ $mime_type ] : false;
        
        // Final filename assembly: Use original extension, but override if MIME type provides a better one
        $save_ext = $original_ext;
        if ( $real_ext && $real_ext !== $original_ext ) {
            // Only override if MIME type is clearly different (e.g., file is actually different format)
            $save_ext = $real_ext;
        }
        // Use the sanitized base name to create file
        $file_name = $check_base . '.' . $save_ext;

        $file_array = array(
            'name' => $file_name,
            'tmp_name' => $tmp,
            'type' => $mime_type
        );

        // 4. Handle Sideload
        // Ensure file exists before proceeding
        if ( ! file_exists( $file_array['tmp_name'] ) ) {
            return new WP_Error( 'file_missing', 'Temporary file disappeared before sideloading.' );
        }

        // Use media_handle_sideload which moves the file to upload dir and creates attachment
        $id = media_handle_sideload( $file_array, $post_id, $desc );

        // If error, delete temp file (media_handle_sideload usually handles this but good to be safe)
        if ( is_wp_error( $id ) ) {
            @unlink( $file_array['tmp_name'] );
             if ( $post_id ) {
                update_post_meta( $post_id, '_wpaie_failed_image_url', $url );
                update_post_meta( $post_id, '_wpaie_image_error', $id->get_error_message() );
            }
        } else {
            // Success: Clean up failure meta if it exists
            if ( $post_id ) {
                delete_post_meta( $post_id, '_wpaie_failed_image_url' );
                delete_post_meta( $post_id, '_wpaie_image_error' );
            }
        }

        return $id;
    }
    
    /**
     * Scan content for <img> tags, download remote images, and replace with local URLs
     */
    private function import_images_from_content( $content, $post_id = 0 ) {
        if ( empty( $content ) ) {
            return $content;
        }

        // Match all img src attributes, including escaped quotes and slashes (Gutenberg/JSON)
        // Patterns: src="URL", src=\"URL\"
        preg_match_all( '/src=\\\\?["\'](.*?)\\\\?["\']/i', $content, $matches );

        if ( ! empty( $matches[1] ) ) {
            $srcs = array_unique( $matches[1] );
            foreach ( $srcs as $old_src_raw ) {
                // Normalize URL for processing (unescape slashes)
                $old_src_clean = str_replace( '\\/', '/', $old_src_raw );
                
                // Skip if already a local URL or relative
                if ( strpos( $old_src_clean, 'http' ) !== 0 || strpos( $old_src_clean, site_url() ) === 0 ) {
                    continue;
                }

                // Sideload the image (This internally checks if filename exists in DB already)
                $attach_id = $this->sideload_image_robust( $old_src_clean, $post_id, 'Imported from content' );

                if ( ! is_wp_error( $attach_id ) && $attach_id > 0 ) {
                    $new_url = wp_get_attachment_url( $attach_id );
                    if ( $new_url ) {
                        // Prepare the new URL for replacement (re-escape if it was escaped)
                        $replacement_url = $new_url;
                        if ( strpos( $old_src_raw, '\\/' ) !== false ) {
                             $replacement_url = str_replace( '/', '\\/', $new_url );
                        }
                        
                        // Replace the URL itself
                        $content = str_replace( $old_src_raw, $replacement_url, $content );
                    }
                }
            }
        }

        return $content;
    }

    /**
     * Recursively replace URLs in strings, arrays, or objects
     */
    private function recursive_url_replace( $data, $old_url, $new_url ) {
        if ( is_string( $data ) ) {
            return str_replace( $old_url, $new_url, $data );
        }

        if ( is_array( $data ) ) {
            foreach ( $data as $key => $value ) {
                $data[ $key ] = $this->recursive_url_replace( $value, $old_url, $new_url );
            }
        } elseif ( is_object( $data ) ) {
            foreach ( $object_vars = get_object_vars( $data ) as $key => $value ) {
                $data->$key = $this->recursive_url_replace( $value, $old_url, $new_url );
            }
        }

        return $data;
    }
}

<?php
// Query posts with failed image meta
$args = array(
    'post_type'      => 'any',
    'posts_per_page' => -1,
    'meta_query'     => array(
        array(
            'key'     => '_wpaie_failed_image_url',
            'compare' => 'EXISTS',
        ),
    ),
);
$failed_posts = get_posts( $args );
?>

<div class="card" style="margin-top: 20px; padding: 20px;">
    <h3>Failed Image Downloads</h3>
    <p>Below is a list of posts where the Featured Image failed to download during import.</p>
    
    <div class="tablenav top">
        <div class="alignleft actions">
            <button type="button" class="button button-primary" id="wpaie-bulk-retry">Bulk Download Images</button>
        </div>
        <div class="alignleft actions">
             <span id="wpaie-report-status" style="line-height: 30px; margin-left: 10px; font-weight: bold;"></span>
        </div>
    </div>

    <table class="wp-list-table widefat fixed striped table-view-list posts wpaie-report-table">
        <thead>
            <tr>
                <td id="cb" class="manage-column column-cb check-column"><input id="cb-select-all-1" type="checkbox"></td>
                <th scope="col" class="manage-column column-title">Post Title</th>
                <th scope="col" class="manage-column column-url">Failed Image URL</th>
                <th scope="col" class="manage-column column-error">Error Message</th>
                <th scope="col" class="manage-column column-action">Action</th>
            </tr>
        </thead>
        <tbody id="the-list">
            <?php if ( empty( $failed_posts ) ) : ?>
                <tr>
                    <td colspan="5">No failed images found. Great job!</td>
                </tr>
            <?php else : ?>
                <?php foreach ( $failed_posts as $post ) : 
                    $failed_url = get_post_meta( $post->ID, '_wpaie_failed_image_url', true );
                    $error_msg = get_post_meta( $post->ID, '_wpaie_image_error', true );
                ?>
                <tr id="post-<?php echo $post->ID; ?>">
                    <th scope="row" class="check-column"><input type="checkbox" name="post[]" value="<?php echo $post->ID; ?>" class="subscriber-check"></th>
                    <td class="title column-title">
                        <strong><a href="<?php echo get_edit_post_link( $post->ID ); ?>" target="_blank"><?php echo esc_html( $post->post_title ); ?></a></strong>
                    </td>
                    <td class="url column-url">
                        <a href="<?php echo esc_url( $failed_url ); ?>" target="_blank" style="display:inline-block; max-width: 300px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;"><?php echo esc_html( $failed_url ); ?></a>
                    </td>
                    <td class="error column-error" style="color: #d63638;">
                        <?php echo esc_html( $error_msg ); ?>
                    </td>
                    <td class="action column-action">
                        <button type="button" class="button wpaie-retry-btn" data-id="<?php echo $post->ID; ?>" data-url="<?php echo esc_attr( $failed_url ); ?>">Download Image</button>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

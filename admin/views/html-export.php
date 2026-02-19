<div class="card" style="max-width: 800px; margin-top: 20px; padding: 20px;">
    <h3>Export Settings</h3>
    <form id="wpaie-export-form" method="post">
        <?php wp_nonce_field( 'wpaie_export_action', 'wpaie_export_nonce' ); ?>

        <table class="form-table">
            <tr>
                <th scope="row">Content Types</th>
                <td>
                    <?php
                    $post_types = get_post_types( array( 'public' => true ), 'objects' );
                    ?>
                    <select name="content_types[]" id="wpaie-content-type">
                        <?php foreach ( $post_types as $post_type ) : ?>
                            <?php if ( 'attachment' === $post_type->name ) continue; ?>
                            <?php 
                            $label = $post_type->label;
                            if ( ! empty( $post_type->labels->menu_name ) ) {
                                $label = $post_type->labels->menu_name;
                            }
                            ?>
                            <option value="<?php echo esc_attr( $post_type->name ); ?>" <?php selected( 'post', $post_type->name ); ?>>
                                <?php echo esc_html( $label ); ?> (<?php echo esc_html( $post_type->name ); ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </td>
            </tr>
            <tr>
                <th scope="row">Include Data</th>
                <td>
                    <fieldset>
                        <label><input type="checkbox" name="include_comments" value="1" checked> Comments</label><br>
                        <label><input type="checkbox" name="include_images" value="1" checked> Featured Images</label><br>
                        <label><input type="checkbox" name="include_terms" value="1" checked> Categories & Tags</label><br>
                        <label><input type="checkbox" name="include_meta" value="1" checked> Custom Fields</label>
                    </fieldset>
                </td>
            </tr>
            <tr>
                <th scope="row">Date Range</th>
                <td>
                    <label>Start Date: <input type="date" name="start_date"></label><br>
                    <label>End Date: <input type="date" name="end_date"></label>
                </td>
            </tr>
            <tr>
                <th scope="row">Status</th>
                <td>
                    <select name="post_status">
                        <option value="any">All Statuses</option>
                        <option value="publish">Published</option>
                        <option value="draft">Draft</option>
                        <option value="private">Private</option>
                    </select>
                </td>
            </tr>
        </table>
        
        <div class="wpaie-progress-container">
            <div class="wpaie-status">Ready to export.</div>
            <div class="wpaie-progress-bar">
                <div style="width: 0%;">0%</div>
            </div>
        </div>

        <p class="submit">
            <input type="submit" name="submit" id="submit" class="button button-primary" value="Generate Export File">
        </p>
    </form>
</div>

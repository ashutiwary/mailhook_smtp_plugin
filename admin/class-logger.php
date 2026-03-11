<?php
/**
 * MailHook — Email Logger
 *
 * Logs every wp_mail() call (success and failure) to a custom database table
 * and provides an admin page to browse, search, and manage the log.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class MailHook_Logger {

    /**
     * Custom table name (without prefix).
     */
    const TABLE = 'mailhook_logs';

    /**
     * DB version for schema upgrades.
     */
    const DB_VERSION = '1.0';

    /**
     * Logs per page on the admin table.
     */
    const PER_PAGE = 20;

    /**
     * Temporarily holds mail args between send and result.
     *
     * @var array|null
     */
    private static $pending_mail = null;

    /* ───────────────────────── Bootstrap ───────────────────────── */

    public function __construct() {
        // Capture outgoing mail arguments
        add_filter( 'wp_mail', array( $this, 'capture_mail' ), 1 );

        // Log results
        add_action( 'wp_mail_succeeded', array( $this, 'log_success' ) );
        add_action( 'wp_mail_failed',    array( $this, 'log_failure' ) );

        // AJAX handlers
        add_action( 'wp_ajax_mailhook_view_log',    array( $this, 'ajax_view_log' ) );
        add_action( 'wp_ajax_mailhook_delete_logs',  array( $this, 'ajax_delete_logs' ) );
        add_action( 'wp_ajax_mailhook_resend_email', array( $this, 'ajax_resend_email' ) );
    }

    /* ───────────────────── Table Installation ──────────────────── */

    /**
     * Create or upgrade the logs table.
     * Called from register_activation_hook.
     */
    public static function install_table() {
        global $wpdb;
        $table   = $wpdb->prefix . self::TABLE;
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id          BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            date_time   DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
            to_email    TEXT                NOT NULL,
            subject     TEXT                NOT NULL,
            message     LONGTEXT            NOT NULL,
            headers     TEXT                NOT NULL,
            attachments TEXT                NOT NULL,
            from_email  VARCHAR(255)        NOT NULL DEFAULT '',
            from_name   VARCHAR(255)        NOT NULL DEFAULT '',
            status      VARCHAR(20)         NOT NULL DEFAULT 'sent',
            error       TEXT                NOT NULL,
            source      VARCHAR(255)        NOT NULL DEFAULT '',
            PRIMARY KEY (id),
            KEY idx_status    (status),
            KEY idx_date_time (date_time)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );

        update_option( 'mailhook_db_version', self::DB_VERSION );
    }

    /* ──────────────────── Mail Capture & Logging ───────────────── */

    /**
     * Capture wp_mail() arguments so we can log them after send.
     *
     * @param  array $args {to, subject, message, headers, attachments}
     * @return array       Unmodified args.
     */
    public function capture_mail( $args ) {
        // Detect calling source via backtrace
        $source = $this->detect_source();

        self::$pending_mail = array(
            'to'          => is_array( $args['to'] ) ? implode( ', ', $args['to'] ) : $args['to'],
            'subject'     => $args['subject'],
            'message'     => $args['message'],
            'headers'     => is_array( $args['headers'] ) ? implode( "\r\n", $args['headers'] ) : $args['headers'],
            'attachments' => is_array( $args['attachments'] ) ? implode( ', ', $args['attachments'] ) : '',
            'source'      => $source,
        );

        return $args;
    }

    /**
     * Log a successfully sent email.
     *
     * @param array $mail_data Data from wp_mail_succeeded action.
     */
    public function log_success( $mail_data ) {
        if ( ! self::$pending_mail ) {
            return;
        }

        $settings = get_option( 'mailhook_settings', array() );

        $this->insert_log( array(
            'to_email'    => self::$pending_mail['to'],
            'subject'     => self::$pending_mail['subject'],
            'message'     => self::$pending_mail['message'],
            'headers'     => self::$pending_mail['headers'],
            'attachments' => self::$pending_mail['attachments'],
            'from_email'  => $settings['from_email'] ?? '',
            'from_name'   => $settings['from_name'] ?? '',
            'status'      => 'sent',
            'error'       => '',
            'source'      => self::$pending_mail['source'],
        ) );

        self::$pending_mail = null;
    }

    /**
     * Log a failed email.
     *
     * @param WP_Error $wp_error The error object from wp_mail_failed.
     */
    public function log_failure( $wp_error ) {
        if ( ! self::$pending_mail ) {
            return;
        }

        $settings = get_option( 'mailhook_settings', array() );
        $error_data = $wp_error->get_error_data();
        $to = self::$pending_mail['to'];
        if ( isset( $error_data['to'] ) ) {
            $to = is_array( $error_data['to'] ) ? implode( ', ', $error_data['to'] ) : $error_data['to'];
        }

        $this->insert_log( array(
            'to_email'    => $to,
            'subject'     => $error_data['subject'] ?? self::$pending_mail['subject'],
            'message'     => self::$pending_mail['message'],
            'headers'     => self::$pending_mail['headers'],
            'attachments' => self::$pending_mail['attachments'],
            'from_email'  => $settings['from_email'] ?? '',
            'from_name'   => $settings['from_name'] ?? '',
            'status'      => 'failed',
            'error'       => $wp_error->get_error_message(),
            'source'      => self::$pending_mail['source'],
        ) );

        self::$pending_mail = null;
    }

    /**
     * Insert a log row into the database.
     *
     * @param array $data Associative array of column => value.
     */
    private function insert_log( $data ) {
        global $wpdb;

        $data['date_time'] = current_time( 'mysql' );

        $wpdb->insert(
            $wpdb->prefix . self::TABLE,
            $data,
            array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
        );
    }

    /**
     * Attempt to detect which plugin/theme triggered the email.
     *
     * @return string
     */
    private function detect_source() {
        $trace  = debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 15 ); // phpcs:ignore
        $source = 'WordPress Core';

        foreach ( $trace as $frame ) {
            if ( empty( $frame['file'] ) ) {
                continue;
            }

            $file = wp_normalize_path( $frame['file'] );

            // Check plugins
            if ( strpos( $file, '/plugins/' ) !== false ) {
                preg_match( '#/plugins/([^/]+)/#', $file, $m );
                if ( ! empty( $m[1] ) && $m[1] !== 'MailHook smtp' ) {
                    $source = 'Plugin: ' . $m[1];
                    break;
                }
            }

            // Check themes
            if ( strpos( $file, '/themes/' ) !== false ) {
                preg_match( '#/themes/([^/]+)/#', $file, $m );
                if ( ! empty( $m[1] ) ) {
                    $source = 'Theme: ' . $m[1];
                    break;
                }
            }
        }

        return $source;
    }

    /* ──────────────────────── Admin Page ───────────────────────── */

    /**
     * Render the Email Logs admin page.
     */
    public static function render_page() {
        // Capability check — even though WP won't show the menu to non-admins,
        // a direct URL could expose the page without this guard.
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have permission to access this page.', 'mailhook' ) );
        }

        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;

        // Filters
        $status_filter = isset( $_GET['status'] ) ? sanitize_text_field( $_GET['status'] ) : '';
        $search        = isset( $_GET['s'] )      ? sanitize_text_field( $_GET['s'] )      : '';
        $paged         = isset( $_GET['paged'] )   ? max( 1, intval( $_GET['paged'] ) )     : 1;
        $offset        = ( $paged - 1 ) * self::PER_PAGE;

        // Build WHERE
        $where  = array( '1=1' );
        $params = array();

        if ( $status_filter && in_array( $status_filter, array( 'sent', 'failed' ), true ) ) {
            $where[]  = 'status = %s';
            $params[] = $status_filter;
        }

        if ( $search ) {
            $like     = '%' . $wpdb->esc_like( $search ) . '%';
            $where[]  = '(to_email LIKE %s OR subject LIKE %s OR source LIKE %s)';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }

        $where_sql = implode( ' AND ', $where );

        // Count
        $count_query = "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}";
        $total_items = $params ? $wpdb->get_var( $wpdb->prepare( $count_query, $params ) ) : $wpdb->get_var( $count_query );
        $total_pages = ceil( $total_items / self::PER_PAGE );

        // Fetch rows
        $query     = "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY date_time DESC LIMIT %d OFFSET %d";
        $all       = array_merge( $params, array( self::PER_PAGE, $offset ) );
        $logs      = $wpdb->get_results( $wpdb->prepare( $query, $all ) );

        // Stats (table name is internal/fixed — safe to use without prepare)
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
        $total_sent   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status = 'sent'" );
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
        $total_failed = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status = 'failed'" );
        $last_24h     = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE date_time >= %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS )
        ) );

        $page_url = admin_url( 'admin.php?page=mailhook-logs' );
        ?>
        <div class="wrap mailhook-wrap">
            <div class="mailhook-header">
                <h1>
                    <?php _e( 'Email Logs', 'mailhook' ); ?>
                </h1>
                <p class="mailhook-tagline"><?php _e( 'Monitor every email sent from your WordPress site.', 'mailhook' ); ?></p>
            </div>

            <!-- Stats Cards -->
            <div class="mailhook-stats-row">
                <div class="mailhook-stat-card mailhook-stat-total">
                    <span class="mailhook-stat-icon"></span>
                    <div class="mailhook-stat-body">
                        <span class="mailhook-stat-number"><?php echo esc_html( $total_sent + $total_failed ); ?></span>
                        <span class="mailhook-stat-label"><?php _e( 'Total Emails', 'mailhook' ); ?></span>
                    </div>
                </div>
                <div class="mailhook-stat-card mailhook-stat-sent">
                    <span class="mailhook-stat-icon"></span>
                    <div class="mailhook-stat-body">
                        <span class="mailhook-stat-number"><?php echo esc_html( $total_sent ); ?></span>
                        <span class="mailhook-stat-label"><?php _e( 'Sent', 'mailhook' ); ?></span>
                    </div>
                </div>
                <div class="mailhook-stat-card mailhook-stat-failed">
                    <span class="mailhook-stat-icon"></span>
                    <div class="mailhook-stat-body">
                        <span class="mailhook-stat-number"><?php echo esc_html( $total_failed ); ?></span>
                        <span class="mailhook-stat-label"><?php _e( 'Failed', 'mailhook' ); ?></span>
                    </div>
                </div>
                <div class="mailhook-stat-card mailhook-stat-recent">
                    <span class="mailhook-stat-icon"></span>
                    <div class="mailhook-stat-body">
                        <span class="mailhook-stat-number"><?php echo esc_html( $last_24h ); ?></span>
                        <span class="mailhook-stat-label"><?php _e( 'Last 24 Hours', 'mailhook' ); ?></span>
                    </div>
                </div>
            </div>

            <!-- Filters Bar -->
            <div class="mailhook-card mailhook-filters-bar">
                <form method="get" action="<?php echo esc_url( $page_url ); ?>">
                    <input type="hidden" name="page" value="mailhook-logs" />
                    <div class="mailhook-filters-inner">
                        <div class="mailhook-filter-group">
                            <label for="mh-filter-status"><?php _e( 'Status:', 'mailhook' ); ?></label>
                            <select id="mh-filter-status" name="status">
                                <option value=""><?php _e( 'All', 'mailhook' ); ?></option>
                                <option value="sent"   <?php selected( $status_filter, 'sent' ); ?>><?php _e( 'Sent', 'mailhook' ); ?></option>
                                <option value="failed" <?php selected( $status_filter, 'failed' ); ?>><?php _e( 'Failed', 'mailhook' ); ?></option>
                            </select>
                        </div>
                        <div class="mailhook-filter-group mailhook-filter-search">
                            <label for="mh-filter-search"><?php _e( 'Search:', 'mailhook' ); ?></label>
                            <input type="text" id="mh-filter-search" name="s" value="<?php echo esc_attr( $search ); ?>"
                                   placeholder="<?php esc_attr_e( 'Recipient, subject, or source…', 'mailhook' ); ?>" />
                        </div>
                        <button type="submit" class="button"><?php _e( 'Filter', 'mailhook' ); ?></button>
                        <?php if ( $status_filter || $search ) : ?>
                            <a href="<?php echo esc_url( $page_url ); ?>" class="button mailhook-btn-clear"><?php _e( 'Clear', 'mailhook' ); ?></a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>

            <!-- Logs Table -->
            <div class="mailhook-card mailhook-logs-table-wrap">
                <?php if ( empty( $logs ) ) : ?>
                    <div class="mailhook-empty-state">
                        <span class="mailhook-empty-icon"></span>
                        <h3><?php _e( 'No emails logged yet', 'mailhook' ); ?></h3>
                        <p><?php _e( 'Emails sent through your site will appear here. Try sending a test email from the Settings page.', 'mailhook' ); ?></p>
                    </div>
                <?php else : ?>
                    <form id="mailhook-logs-form" method="post">
                        <?php wp_nonce_field( 'mailhook_bulk_logs', 'mailhook_logs_nonce' ); ?>
                        <div class="mailhook-bulk-bar">
                            <label class="mailhook-select-all-wrap">
                                <input type="checkbox" id="mh-select-all" />
                                <span><?php _e( 'Select All', 'mailhook' ); ?></span>
                            </label>
                            <button type="button" id="mh-delete-selected" class="button mailhook-btn-danger" disabled>
                                <?php _e( 'Delete Selected', 'mailhook' ); ?>
                            </button>
                            <button type="button" id="mh-delete-all" class="button mailhook-btn-danger-outline">
                                <?php _e( 'Delete All Logs', 'mailhook' ); ?>
                            </button>
                            <span class="mailhook-bulk-count">
                                <?php
                                printf(
                                    /* translators: %s: number of emails */
                                    esc_html__( '%s emails', 'mailhook' ),
                                    '<strong>' . intval( $total_items ) . '</strong>'
                                );
                                ?>
                            </span>
                        </div>

                        <table class="mailhook-logs-table">
                            <thead>
                                <tr>
                                    <th class="mh-col-check"></th>
                                    <th class="mh-col-status"><?php _e( 'Status', 'mailhook' ); ?></th>
                                    <th class="mh-col-to"><?php _e( 'To', 'mailhook' ); ?></th>
                                    <th class="mh-col-subject"><?php _e( 'Subject', 'mailhook' ); ?></th>
                                    <th class="mh-col-source"><?php _e( 'Source', 'mailhook' ); ?></th>
                                    <th class="mh-col-date"><?php _e( 'Date & Time', 'mailhook' ); ?></th>
                                    <th class="mh-col-action"></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ( $logs as $log ) : ?>
                                    <tr class="mailhook-log-row <?php echo $log->status === 'failed' ? 'mailhook-row-failed' : ''; ?>">
                                        <td class="mh-col-check">
                                            <input type="checkbox" name="log_ids[]" value="<?php echo intval( $log->id ); ?>" class="mh-log-check" />
                                        </td>
                                        <td class="mh-col-status">
                                            <span class="mailhook-badge mailhook-badge-<?php echo esc_attr( $log->status ); ?>">
                                                <?php echo $log->status === 'sent' ? '' . __( 'Sent', 'mailhook' ) : '' . __( 'Failed', 'mailhook' ); ?>
                                            </span>
                                        </td>
                                        <td class="mh-col-to"><?php echo esc_html( $log->to_email ); ?></td>
                                        <td class="mh-col-subject"><?php echo esc_html( wp_trim_words( $log->subject, 8, '…' ) ); ?></td>
                                        <td class="mh-col-source"><span class="mailhook-source-tag"><?php echo esc_html( $log->source ); ?></span></td>
                                        <td class="mh-col-date"><?php echo esc_html( date_i18n( 'M j, Y  g:i A', strtotime( $log->date_time ) ) ); ?></td>
                                        <td class="mh-col-action">
                                            <button type="button" class="button button-small mailhook-view-btn" data-id="<?php echo intval( $log->id ); ?>">
                                                <?php _e( 'View', 'mailhook' ); ?>
                                            </button>
                                            <?php if ( $log->status === 'failed' ) : ?>
                                            <button type="button"
                                                class="button button-small mailhook-resend-btn"
                                                data-id="<?php echo intval( $log->id ); ?>"
                                                data-nonce="<?php echo esc_attr( wp_create_nonce( 'mailhook_resend_' . $log->id ) ); ?>"
                                                title="<?php esc_attr_e( 'Resend this failed email', 'mailhook' ); ?>">
                                                <?php _e( 'Resend', 'mailhook' ); ?>
                                            </button>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <?php if ( $log->status === 'failed' && ! empty( $log->error ) ) : ?>
                                        <tr class="mailhook-error-row">
                                            <td colspan="7">
                                                <div class="mailhook-inline-error"><?php echo esc_html( $log->error ); ?></div>
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </form>

                    <!-- Pagination -->
                    <?php if ( $total_pages > 1 ) : ?>
                        <div class="mailhook-pagination">
                            <?php
                            $base_args = array( 'page' => 'mailhook-logs' );
                            if ( $status_filter ) $base_args['status'] = $status_filter;
                            if ( $search )        $base_args['s']      = $search;

                            for ( $i = 1; $i <= $total_pages; $i++ ) :
                                $base_args['paged'] = $i;
                                $url   = add_query_arg( $base_args, admin_url( 'admin.php' ) );
                                $class = $i === $paged ? 'mailhook-page-btn active' : 'mailhook-page-btn';
                            ?>
                                <a href="<?php echo esc_url( $url ); ?>" class="<?php echo esc_attr( $class ); ?>"><?php echo intval( $i ); ?></a>
                            <?php endfor; ?>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>

            <!-- Detail Modal (hidden) -->
            <div id="mailhook-log-modal" class="mailhook-modal" style="display:none;">
                <div class="mailhook-modal-overlay"></div>
                <div class="mailhook-modal-content">
                    <button type="button" class="mailhook-modal-close">&times;</button>
                    <div id="mailhook-modal-body">
                        <div class="mailhook-modal-loading"><?php _e( 'Loading…', 'mailhook' ); ?></div>
                    </div>
                </div>
            </div>

            <div class="mailhook-footer">
                <p><?php printf( esc_html__( 'MailHook v%s &mdash; Lightweight SMTP for WordPress', 'mailhook' ), esc_html( MAILHOOK_VERSION ) ); ?></p>
            </div>
        </div>
        <?php
    }

    /* ──────────────────────── AJAX Handlers ────────────────────── */

    /**
     * Return HTML detail for a single log entry.
     */
    public function ajax_view_log() {
        check_ajax_referer( 'mailhook_view_log', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( __( 'Unauthorized.', 'mailhook' ) );
        }

        global $wpdb;
        $id  = intval( $_POST['id'] ?? 0 );
        $log = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}" . self::TABLE . " WHERE id = %d", $id
        ) );

        if ( ! $log ) {
            wp_send_json_error( __( 'Log entry not found.', 'mailhook' ) );
        }

        $status_badge = $log->status === 'sent'
            ? '<span class="mailhook-badge mailhook-badge-sent">Sent</span>'
            : '<span class="mailhook-badge mailhook-badge-failed">Failed</span>';

        $html  = '<div class="mailhook-detail">';
        $html .= '<h2>' . esc_html( $log->subject ) . ' ' . $status_badge . '</h2>';
        $html .= '<div class="mailhook-detail-grid">';

        // Meta info
        $fields = array(
            'To'         => esc_html( $log->to_email ),
            'From'       => esc_html( $log->from_name . ' <' . $log->from_email . '>' ),
            'Date'       => esc_html( date_i18n( 'F j, Y  g:i:s A', strtotime( $log->date_time ) ) ),
            'Source'     => esc_html( $log->source ),
        );

        foreach ( $fields as $label => $value ) {
            $html .= '<div class="mailhook-detail-item"><span class="mailhook-detail-label">' . $label . '</span><span class="mailhook-detail-value">' . $value . '</span></div>';
        }

        $html .= '</div>';

        // Error
        if ( $log->status === 'failed' && ! empty( $log->error ) ) {
            $html .= '<div class="mailhook-detail-error"><strong>Error:</strong> ' . esc_html( $log->error ) . '</div>';
        }

        // Headers
        if ( ! empty( $log->headers ) ) {
            $html .= '<div class="mailhook-detail-section"><h3>Headers</h3><pre>' . esc_html( $log->headers ) . '</pre></div>';
        }

        // Attachments
        if ( ! empty( $log->attachments ) ) {
            $html .= '<div class="mailhook-detail-section"><h3>Attachments</h3><p>' . esc_html( $log->attachments ) . '</p></div>';
        }

        // Message body
        $html .= '<div class="mailhook-detail-section"><h3>Message Body</h3><div class="mailhook-detail-body-preview">';
        // If the message looks like HTML, render it inside an iframe-safe div
        if ( strpos( $log->message, '<' ) !== false && strpos( $log->message, '>' ) !== false ) {
            $html .= '<div class="mailhook-html-preview">' . wp_kses_post( $log->message ) . '</div>';
        } else {
            $html .= '<pre>' . esc_html( $log->message ) . '</pre>';
        }
        $html .= '</div></div>';

        $html .= '</div>';

        wp_send_json_success( $html );
    }

    /**
     * Delete selected or all log entries.
     */
    public function ajax_delete_logs() {
        check_ajax_referer( 'mailhook_delete_logs', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( __( 'Unauthorized.', 'mailhook' ) );
            wp_die();
        }

        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;

        // Ensure ids is always an array
        $ids = isset( $_POST['ids'] ) ? (array) $_POST['ids'] : array();

        if ( in_array( 'all', $ids, true ) ) {
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
            $wpdb->query( "TRUNCATE TABLE {$table}" );
        } else {
            $ids = array_filter( array_map( 'intval', $ids ) );
            if ( ! empty( $ids ) ) {
                $placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
                // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
                $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE id IN ({$placeholders})", $ids ) );
            }
        }

        wp_send_json_success( __( 'Logs deleted.', 'mailhook' ) );
    }

    /**
     * Resend a previously failed email.
     *
     * Looks up the log row by ID, verifies a per-row nonce,
     * then calls wp_mail() with the stored email data.
     */
    public function ajax_resend_email() {
        $id = intval( $_POST['id'] ?? 0 );
        check_ajax_referer( 'mailhook_resend_' . $id, 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( __( 'Unauthorized.', 'mailhook' ) );
        }

        global $wpdb;
        $table = $wpdb->prefix . self::TABLE;

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $log = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$table} WHERE id = %d",
            $id
        ) );

        if ( ! $log ) {
            wp_send_json_error( __( 'Log entry not found.', 'mailhook' ) );
        }

        if ( $log->status !== 'failed' ) {
            wp_send_json_error( __( 'This email was not failed — no resend needed.', 'mailhook' ) );
        }

        // Build headers array from stored headers string
        $headers = ! empty( $log->headers )
            ? explode( "\r\n", $log->headers )
            : array();

        // Build attachments array (stored as comma-separated paths)
        $attachments = ! empty( $log->attachments )
            ? array_filter( array_map( 'trim', explode( ',', $log->attachments ) ) )
            : array();

        // Attempt resend
        $result = wp_mail(
            $log->to_email,
            $log->subject,
            $log->message,
            $headers,
            $attachments
        );

        if ( $result ) {
            wp_send_json_success( array(
                'message' => __( 'Email resent successfully!', 'mailhook' ),
            ) );
        } else {
            wp_send_json_error( __( 'Failed to resend the email. Please check your SMTP settings.', 'mailhook' ) );
        }
    }
}

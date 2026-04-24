<?php
/**
 * MailHook — Email Reports Page  (v2)
 *
 * Layout inspired by WP Mail SMTP but more informational:
 *  • Inline legend stats above the chart (Total / Sent / Failed / Delivery Rate)
 *  • Interactive Chart.js line chart – dynamically titled "All Emails" or per-subject
 *  • Date-range filter (dropdown + Filter btn) + client-side search
 *  • "All Emails" table: Subject | Source | Total | Sent (%) | Failed (%) | Graph
 *  • Source Breakdown card with progress-bar share column
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MailHook_Email_Report {

	const TABLE = 'mailhook_logs';

	/**
	 * Resolved start of the currently rendered date range.
	 *
	 * @var string
	 */
	private $current_from = '';

	/**
	 * Resolved end of the currently rendered date range.
	 *
	 * @var string
	 */
	private $current_to = '';

	/* ─────────────────────────── Bootstrap ─────────────────────────── */

	public function __construct() {
		add_action( 'admin_menu',            array( $this, 'register_submenu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_ajax_mailhook_get_report_data',    array( $this, 'ajax_get_report_data' ) );
		add_action( 'wp_ajax_mailhook_get_subject_chart',  array( $this, 'ajax_get_subject_chart' ) );
	}

	/* ───────────────────────── Menu Registration ───────────────────── */

	public function register_submenu() {
		add_submenu_page(
			'mailhook',
			__( 'Email Reports', 'mailhook' ),
			__( 'Email Reports', 'mailhook' ),
			'manage_options',
			'mailhook-email-report',
			array( $this, 'render_page' )
		);
	}

	/* ───────────────────────── Asset Enqueuing ─────────────────────── */

	public function enqueue_assets( $hook ) {
		if ( 'mailhook_page_mailhook-email-report' !== $hook ) {
			return;
		}

		wp_register_script(
			'chartjs',
			'https://cdn.jsdelivr.net/npm/chart.js@4.4.2/dist/chart.umd.min.js',
			array(),
			'4.4.2',
			true
		);
		wp_enqueue_script( 'chartjs' );

		wp_enqueue_style(
			'mailhook-admin',
			MAILHOOK_ADMIN_URL . 'css/admin.css',
			array(),
			MAILHOOK_VERSION
		);

		$range = isset( $_GET['range'] ) ? sanitize_text_field( $_GET['range'] ) : '7';
		$dates = $this->resolve_date_range( $range );
		$stats = $this->get_stats( $dates['from'], $dates['to'] );

		$init = array(
			'ajaxurl'     => admin_url( 'admin-ajax.php' ),
			'nonce'       => wp_create_nonce( 'mailhook_report' ),
			'labels'      => $this->get_daily_labels( $dates['from'], $dates['to'] ),
			'totalData'   => $this->get_daily_total( $dates['from'], $dates['to'] ),
			'sentData'    => $this->get_daily_counts( $dates['from'], $dates['to'], 'sent' ),
			'failedData'  => $this->get_daily_counts( $dates['from'], $dates['to'], 'failed' ),
			'stats'       => $stats,
			'activeRange' => $range,
			'resolvedFrom' => gmdate( 'Y-m-d', strtotime( $dates['from'] ) ),
			'resolvedTo'   => gmdate( 'Y-m-d', strtotime( $dates['to'] ) ),
			'i18n'        => array(
				'total'         => __( 'Total', 'mailhook' ),
				'sent'          => __( 'Sent', 'mailhook' ),
				'failed'        => __( 'Failed', 'mailhook' ),
				'allEmails'     => __( 'All Emails', 'mailhook' ),
				'noData'        => __( 'No data', 'mailhook' ),
				'drillTitle'    => __( 'Volume for: ', 'mailhook' ),
			),
		);


		wp_add_inline_script( 'chartjs', 'var mailhookReportData = ' . wp_json_encode( $init ) . ';', 'before' );
		wp_add_inline_script( 'chartjs', $this->get_inline_js(), 'after' );
	}

	/* ──────────────────────────── AJAX ─────────────────────────────── */

	/** Full date-range refresh (stats + chart + tables). */
	public function ajax_get_report_data() {
		check_ajax_referer( 'mailhook_report', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Unauthorized.', 'mailhook' ) );
		}

		$range = isset( $_POST['range'] )     ? sanitize_text_field( $_POST['range'] )     : '7';
		$from  = isset( $_POST['from_date'] ) ? sanitize_text_field( $_POST['from_date'] ) : '';
		$to    = isset( $_POST['to_date'] )   ? sanitize_text_field( $_POST['to_date'] )   : '';

		if ( $range === 'custom' && $from && $to ) {
			$dates = array( 'from' => $from . ' 00:00:00', 'to' => $to . ' 23:59:59' );
		} else {
			$dates = $this->resolve_date_range( $range );
		}

		$stats = $this->get_stats( $dates['from'], $dates['to'] );

		wp_send_json_success( array(
			'labels'     => $this->get_daily_labels( $dates['from'], $dates['to'] ),
			'totalData'  => $this->get_daily_total( $dates['from'], $dates['to'] ),
			'sentData'   => $this->get_daily_counts( $dates['from'], $dates['to'], 'sent' ),
			'failedData' => $this->get_daily_counts( $dates['from'], $dates['to'], 'failed' ),
			'stats'      => $stats,
			'sources'    => $this->get_source_breakdown( $dates['from'], $dates['to'] ),
			'subjects'   => $this->get_subject_breakdown( $dates['from'], $dates['to'] ),
		) );
	}

	/** Per-subject daily chart drilldown. */
	public function ajax_get_subject_chart() {
		check_ajax_referer( 'mailhook_report', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Unauthorized.', 'mailhook' ) );
		}

		$subject = isset( $_POST['subject'] )   ? sanitize_text_field( wp_unslash( $_POST['subject'] ) ) : '';
		$from    = isset( $_POST['from'] )       ? sanitize_text_field( $_POST['from'] ) : '';
		$to      = isset( $_POST['to'] )         ? sanitize_text_field( $_POST['to'] )   : '';

		if ( ! $subject || ! $from || ! $to ) {
			wp_send_json_error( 'Missing params.' );
		}

		global $wpdb;
		$table = $wpdb->prefix . self::TABLE;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT DATE(date_time) AS day,
			        SUM(CASE WHEN status='sent'   THEN 1 ELSE 0 END) AS sent,
			        SUM(CASE WHEN status='failed' THEN 1 ELSE 0 END) AS failed,
			        COUNT(*) AS total
			 FROM {$table}
			 WHERE subject = %s AND date_time BETWEEN %s AND %s
			 GROUP BY DATE(date_time)
			 ORDER BY day ASC",
			$subject, $from . ' 00:00:00', $to . ' 23:59:59'
		), ARRAY_A );

		$indexed_sent   = array();
		$indexed_failed = array();
		$indexed_total  = array();
		foreach ( $rows as $row ) {
			$indexed_sent[ $row['day'] ]   = (int) $row['sent'];
			$indexed_failed[ $row['day'] ] = (int) $row['failed'];
			$indexed_total[ $row['day'] ]  = (int) $row['total'];
		}

		$labels = array();
		$sent   = array();
		$failed = array();
		$total  = array();
		$day    = strtotime( $from );
		$end    = strtotime( $to );
		while ( $day <= $end ) {
			$key      = gmdate( 'Y-m-d', $day );
			$labels[] = gmdate( 'M j', $day );
			$sent[]   = $indexed_sent[ $key ]   ?? 0;
			$failed[] = $indexed_failed[ $key ] ?? 0;
			$total[]  = $indexed_total[ $key ]  ?? 0;
			$day      = strtotime( '+1 day', $day );
		}

		wp_send_json_success( compact( 'labels', 'sent', 'failed', 'total' ) );
	}

	/* ─────────────────────────── Data Queries ──────────────────────── */

	private function resolve_date_range( $range ) {
		$days = in_array( $range, array( '7', '14', '30' ), true ) ? (int) $range : 7;
		return array(
			'from' => gmdate( 'Y-m-d 00:00:00', strtotime( "-{$days} days" ) ),
			'to'   => gmdate( 'Y-m-d 23:59:59' ),
		);
	}

	private function get_stats( $from, $to ) {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sent = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$table} WHERE status='sent' AND date_time BETWEEN %s AND %s",
			$from, $to
		) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$failed = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$table} WHERE status='failed' AND date_time BETWEEN %s AND %s",
			$from, $to
		) );

		$total = $sent + $failed;
		$rate  = $total > 0 ? round( ( $sent / $total ) * 100, 1 ) : 0;

		return compact( 'total', 'sent', 'failed', 'rate' );
	}

	private function get_daily_labels( $from, $to ) {
		$labels = array();
		$day    = strtotime( $from );
		$end    = strtotime( $to );
		while ( $day <= $end ) {
			$labels[] = gmdate( 'M j', $day );
			$day      = strtotime( '+1 day', $day );
		}
		return $labels;
	}

	private function get_daily_counts( $from, $to, $status ) {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT DATE(date_time) AS day, COUNT(*) AS cnt
			 FROM {$table} WHERE status=%s AND date_time BETWEEN %s AND %s
			 GROUP BY DATE(date_time) ORDER BY day ASC",
			$status, $from, $to
		), ARRAY_A );

		$indexed = array();
		foreach ( $rows as $row ) {
			$indexed[ $row['day'] ] = (int) $row['cnt'];
		}

		$counts = array();
		$day    = strtotime( $from );
		$end    = strtotime( $to );
		while ( $day <= $end ) {
			$counts[] = $indexed[ gmdate( 'Y-m-d', $day ) ] ?? 0;
			$day      = strtotime( '+1 day', $day );
		}
		return $counts;
	}

	private function get_daily_total( $from, $to ) {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT DATE(date_time) AS day, COUNT(*) AS cnt
			 FROM {$table} WHERE date_time BETWEEN %s AND %s
			 GROUP BY DATE(date_time) ORDER BY day ASC",
			$from, $to
		), ARRAY_A );

		$indexed = array();
		foreach ( $rows as $row ) {
			$indexed[ $row['day'] ] = (int) $row['cnt'];
		}

		$counts = array();
		$day    = strtotime( $from );
		$end    = strtotime( $to );
		while ( $day <= $end ) {
			$counts[] = $indexed[ gmdate( 'Y-m-d', $day ) ] ?? 0;
			$day      = strtotime( '+1 day', $day );
		}
		return $counts;
	}

	private function get_source_breakdown( $from, $to ) {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_results( $wpdb->prepare(
			"SELECT source,
			        SUM(CASE WHEN status='sent'   THEN 1 ELSE 0 END) AS sent,
			        SUM(CASE WHEN status='failed' THEN 1 ELSE 0 END) AS failed,
			        COUNT(*) AS total
			 FROM {$table} WHERE date_time BETWEEN %s AND %s
			 GROUP BY source ORDER BY total DESC",
			$from, $to
		), ARRAY_A );
	}

	private function get_subject_breakdown( $from, $to ) {
		global $wpdb;
		$table = $wpdb->prefix . self::TABLE;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_results( $wpdb->prepare(
			"SELECT subject,
			        MIN(source)                                       AS source,
			        SUM(CASE WHEN status='sent'   THEN 1 ELSE 0 END) AS sent,
			        SUM(CASE WHEN status='failed' THEN 1 ELSE 0 END) AS failed,
			        COUNT(*)                                          AS total
			 FROM {$table} WHERE date_time BETWEEN %s AND %s
			 GROUP BY subject ORDER BY MAX(date_time) DESC LIMIT 50",
			$from, $to
		), ARRAY_A );
	}

	/* ──────────────────────────── Render ───────────────────────────── */

	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'mailhook' ) );
		}

		$range = isset( $_GET['range'] ) ? sanitize_text_field( $_GET['range'] ) : '7';

		$custom_from = '';
		$custom_to   = '';
		if ( $range === 'custom' ) {
			$custom_from = isset( $_GET['from_date'] ) ? sanitize_text_field( $_GET['from_date'] ) : '';
			$custom_to   = isset( $_GET['to_date'] )   ? sanitize_text_field( $_GET['to_date'] )   : '';
			$dates       = array(
				'from' => $custom_from . ' 00:00:00',
				'to'   => $custom_to . ' 23:59:59',
			);
		} else {
			$dates = $this->resolve_date_range( $range );
		}

		// Store resolved dates on instance so JS can access them for drilldown
		$this->current_from = $dates['from'];
		$this->current_to   = $dates['to'];

		$stats    = $this->get_stats( $dates['from'], $dates['to'] );
		$sources  = $this->get_source_breakdown( $dates['from'], $dates['to'] );
		$subjects = $this->get_subject_breakdown( $dates['from'], $dates['to'] );

		$rate_class = $stats['rate'] >= 85 ? 'mh-rate-good' : ( $stats['rate'] >= 50 ? 'mh-rate-warn' : 'mh-rate-bad' );

		// Build range label
		$range_labels = array(
			'7'  => __( 'Last 7 Days',  'mailhook' ),
			'14' => __( 'Last 14 Days', 'mailhook' ),
			'30' => __( 'Last 30 Days', 'mailhook' ),
			'custom' => __( 'Custom Range', 'mailhook' ),
		);
		?>
		<div class="wrap mailhook-wrap">

			<div class="mailhook-header">
				<h1><?php _e( 'Email Reports', 'mailhook' ); ?></h1>
				<p class="mailhook-tagline"><?php _e( 'Complete email analytics for your WordPress site.', 'mailhook' ); ?></p>
			</div>

			<!-- ════ CHART CARD (WP Mail SMTP-style layout) ════ -->
			<div class="mailhook-card mh-report-main-card">

				<!-- Card header: title + inline legend stats -->
				<div class="mh-report-card-header">
					<h2 class="mh-report-chart-title" id="mh-chart-title">
						<?php _e( 'All Emails', 'mailhook' ); ?>
					</h2>
					<div class="mh-legend-stats" id="mh-legend-stats">
						<span class="mh-leg mh-leg-total">
							<span class="mh-leg-dot mh-dot-total"></span>
							<strong id="mh-stat-total"><?php echo esc_html( $stats['total'] ); ?></strong>
							<?php _e( 'total', 'mailhook' ); ?>
						</span>
						<span class="mh-leg mh-leg-sent">
							<span class="mh-leg-dot mh-dot-sent"></span>
							<strong id="mh-stat-sent"><?php echo esc_html( $stats['sent'] ); ?></strong>
							<?php _e( 'sent', 'mailhook' ); ?>
						</span>
						<span class="mh-leg mh-leg-failed">
							<span class="mh-leg-dot mh-dot-failed"></span>
							<strong id="mh-stat-failed"><?php echo esc_html( $stats['failed'] ); ?></strong>
							<?php _e( 'failed', 'mailhook' ); ?>
						</span>
						<span class="mh-leg mh-leg-rate">
							<span class="mh-leg-dot mh-dot-rate"></span>
							<strong id="mh-stat-rate" class="<?php echo esc_attr( $rate_class ); ?>"><?php echo esc_html( $stats['rate'] ); ?>%</strong>
							<?php _e( 'delivery rate', 'mailhook' ); ?>
						</span>
					</div>
				</div>

				<!-- Chart canvas -->
				<div class="mailhook-chart-wrapper" id="mh-chart-area">
					<?php if ( $stats['total'] === 0 ) : ?>
						<div class="mailhook-empty-state mh-chart-empty">
							<span class="mailhook-empty-icon"></span>
							<h3><?php _e( 'No data for this period', 'mailhook' ); ?></h3>
							<p><?php _e( 'Emails will appear here once sent. Try sending a test email from Settings.', 'mailhook' ); ?></p>
						</div>
					<?php else : ?>
						<canvas id="mh-email-chart"></canvas>
					<?php endif; ?>
				</div>

				<!-- Filter bar & search -->
				<div class="mh-report-filter-bar">
					<div class="mh-filter-left">
						<!-- Back to All Emails button (shown only during drilldown) -->
						<button type="button" id="mh-back-btn" class="button mh-back-btn" style="display:none;">
							&#8592; <?php _e( 'All Emails', 'mailhook' ); ?>
						</button>

						<select id="mh-range-select" class="mh-range-select">
							<option value="7"  <?php selected( $range, '7' ); ?>><?php _e( 'Last 7 days',  'mailhook' ); ?></option>
							<option value="14" <?php selected( $range, '14' ); ?>><?php _e( 'Last 14 days', 'mailhook' ); ?></option>
							<option value="30" <?php selected( $range, '30' ); ?>><?php _e( 'Last 30 days', 'mailhook' ); ?></option>
							<option value="custom" <?php selected( $range, 'custom' ); ?>><?php _e( 'Custom', 'mailhook' ); ?></option>
						</select>

						<!-- Custom date fields (hidden unless "custom" selected) -->
						<div id="mh-custom-range" class="mh-custom-range<?php echo $range === 'custom' ? ' visible' : ''; ?>">
							<input type="date" id="mh-from-date" value="<?php echo esc_attr( $custom_from ); ?>" />
							<span class="mh-to-label"><?php _e( 'to', 'mailhook' ); ?></span>
							<input type="date" id="mh-to-date" value="<?php echo esc_attr( $custom_to ); ?>" />
						</div>

						<button type="button" id="mh-filter-btn" class="button button-primary mh-filter-btn">
							<?php _e( 'Filter', 'mailhook' ); ?>
						</button>
					</div>

					<div class="mh-filter-right">
						<input type="text" id="mh-email-search"
						       placeholder="<?php esc_attr_e( 'Search emails…', 'mailhook' ); ?>"
						       class="mh-search-input" />
					</div>
				</div>

				<!-- ════ All Emails Table (subject × source breakdown) ════ -->
				<div class="mh-all-emails-wrap" id="mh-all-emails-wrap">
					<?php if ( empty( $subjects ) ) : ?>
						<p class="mailhook-no-data"><?php _e( 'No emails logged in this period.', 'mailhook' ); ?></p>
					<?php else : ?>
						<table class="mailhook-report-table mh-emails-table" id="mh-emails-table">
							<thead>
								<tr>
									<th class="mh-th-subject"><?php _e( 'Subject', 'mailhook' ); ?></th>
									<th class="mh-th-source"><?php _e( 'Source', 'mailhook' ); ?></th>
									<th class="mh-th-num"><?php _e( 'Total', 'mailhook' ); ?></th>
									<th class="mh-th-num"><?php _e( 'Sent', 'mailhook' ); ?></th>
									<th class="mh-th-num"><?php _e( 'Failed', 'mailhook' ); ?></th>
									<th class="mh-th-num"><?php _e( 'Open Count', 'mailhook' ); ?></th>
									<th class="mh-th-num"><?php _e( 'Click Count', 'mailhook' ); ?></th>
									<th class="mh-th-graph"><?php _e( 'Graph', 'mailhook' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $subjects as $row ) :
									$sent_pct   = $row['total'] > 0 ? round( ( $row['sent']   / $row['total'] ) * 100 ) : 0;
									$failed_pct = $row['total'] > 0 ? round( ( $row['failed'] / $row['total'] ) * 100 ) : 0;
								?>
								<tr class="mh-row-subject" data-subject="<?php echo esc_attr( $row['subject'] ); ?>">
									<td class="mh-td-subject">
										<?php echo esc_html( $row['subject'] ); ?>
									</td>
									<td class="mh-td-source">
										<span class="mailhook-source-tag"><?php echo esc_html( $row['source'] ); ?></span>
									</td>
									<td class="mh-th-num"><strong><?php echo (int) $row['total']; ?></strong></td>
									<td class="mh-th-num mh-sent-num">
										<?php echo (int) $row['sent']; ?>
										<span class="mh-pct">(<?php echo $sent_pct; ?>%)</span>
									</td>
									<td class="mh-th-num mh-failed-num">
										<?php echo (int) $row['failed']; ?>
										<span class="mh-pct">(<?php echo $failed_pct; ?>%)</span>
									</td>
									<td class="mh-th-num mh-open-num">
										0 <span class="mh-pct">(0%)</span>
									</td>
									<td class="mh-th-num mh-click-num">
										0 <span class="mh-pct">(0%)</span>
									</td>
									<td class="mh-th-graph">
										<button type="button" class="mh-graph-btn" title="<?php esc_attr_e( 'View graph for this subject', 'mailhook' ); ?>"
										        data-subject="<?php echo esc_attr( $row['subject'] ); ?>">
											<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
												<polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/>
											</svg>
										</button>
									</td>
								</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					<?php endif; ?>
				</div>

			</div><!-- /.mh-report-main-card -->

			<!-- ════ Source Breakdown Card ════ -->
			<div class="mailhook-card" id="mh-source-card">
				<div class="mh-card-header-row">
					<h2 class="mailhook-card-title"><?php _e( 'Source Breakdown', 'mailhook' ); ?></h2>
					<p class="description"><?php _e( 'Which plugin, theme, or WordPress core feature triggered each email.', 'mailhook' ); ?></p>
				</div>

				<?php if ( empty( $sources ) ) : ?>
					<p class="mailhook-no-data"><?php _e( 'No data for this period.', 'mailhook' ); ?></p>
				<?php else : ?>
					<table class="mailhook-report-table" id="mh-source-table">
						<thead>
							<tr>
								<th><?php _e( 'Source', 'mailhook' ); ?></th>
								<th class="mh-th-num"><?php _e( 'Total', 'mailhook' ); ?></th>
								<th class="mh-th-num"><?php _e( 'Sent', 'mailhook' ); ?></th>
								<th class="mh-th-num"><?php _e( 'Failed', 'mailhook' ); ?></th>
								<th class="mh-col-bar"><?php _e( 'Share', 'mailhook' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php
							$grand_total = max( 1, array_sum( array_column( $sources, 'total' ) ) );
							foreach ( $sources as $row ) :
								$pct       = round( ( $row['total'] / $grand_total ) * 100 );
								$sent_pct  = $row['total'] > 0 ? round( ( $row['sent']   / $row['total'] ) * 100 ) : 0;
								$fail_pct  = $row['total'] > 0 ? round( ( $row['failed'] / $row['total'] ) * 100 ) : 0;
							?>
							<tr>
								<td><span class="mailhook-source-tag"><?php echo esc_html( $row['source'] ); ?></span></td>
								<td class="mh-th-num"><strong><?php echo (int) $row['total']; ?></strong></td>
								<td class="mh-th-num mh-sent-num">
									<?php echo (int) $row['sent']; ?>
									<span class="mh-pct">(<?php echo $sent_pct; ?>%)</span>
								</td>
								<td class="mh-th-num mh-failed-num">
									<?php echo (int) $row['failed']; ?>
									<span class="mh-pct">(<?php echo $fail_pct; ?>%)</span>
								</td>
								<td class="mh-col-bar">
									<div class="mh-bar-track">
										<div class="mh-bar-fill" style="width:<?php echo esc_attr( $pct ); ?>%"></div>
									</div>
									<span class="mh-bar-pct"><?php echo $pct; ?>%</span>
								</td>
							</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
			</div><!-- /.source-card -->

			<div class="mailhook-footer">
				<p><?php printf( esc_html__( 'MailHook v%s &mdash; Lightweight SMTP for WordPress', 'mailhook' ), esc_html( MAILHOOK_VERSION ) ); ?></p>
			</div>

		</div><!-- /.mailhook-wrap -->
		<?php
	}

	/* ───────────────────── Static Render Alias ─────────────────────── */

	public static function render_page_static() {
		$instance = new self();
		$instance->render_page();
	}

	/* ─────────────────────────── Inline JS ─────────────────────────── */

	private function get_inline_js() {
		return <<<'JS'
(function () {
    'use strict';

    var D = mailhookReportData;
    var chartInstance = null;
    var currentMode   = 'all';   // 'all' | 'subject'
    var currentFrom   = '';
    var currentTo     = '';

    /* ── helpers ── */
    function escHtml(s) {
        return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }
    function el(id) { return document.getElementById(id); }

    /* ════ Chart ════ */
    function renderChart(labels, totalData, sentData, failedData) {
        var canvas = el('mh-email-chart');
        if (!canvas) return;
        if (chartInstance) chartInstance.destroy();

        chartInstance = new Chart(canvas, {
            type: 'line',
            data: {
                labels: labels,
                datasets: [
                    {
                        label: D.i18n.total,
                        data: totalData,
                        borderColor: '#6366f1',
                        backgroundColor: 'rgba(99,102,241,0.08)',
                        borderWidth: 2.5,
                        pointBackgroundColor: '#6366f1',
                        pointRadius: 4,
                        pointHoverRadius: 7,
                        fill: true,
                        tension: 0.35,
                        order: 3,
                    },
                    {
                        label: D.i18n.sent,
                        data: sentData,
                        borderColor: '#22c55e',
                        backgroundColor: 'rgba(34,197,94,0.07)',
                        borderWidth: 2,
                        pointBackgroundColor: '#22c55e',
                        pointRadius: 3,
                        pointHoverRadius: 6,
                        fill: true,
                        tension: 0.35,
                        order: 2,
                    },
                    {
                        label: D.i18n.failed,
                        data: failedData,
                        borderColor: '#ef4444',
                        backgroundColor: 'rgba(239,68,68,0.07)',
                        borderWidth: 2,
                        pointBackgroundColor: '#ef4444',
                        pointRadius: 3,
                        pointHoverRadius: 6,
                        fill: true,
                        tension: 0.35,
                        order: 1,
                    },
                ],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: { display: false }, // we have our own legend
                    tooltip: {
                        usePointStyle: true,
                        callbacks: {
                            label: function(ctx) {
                                return '  ' + ctx.dataset.label + ': ' + ctx.parsed.y;
                            }
                        }
                    },
                },
                scales: {
                    x: {
                        grid: { color: 'rgba(0,0,0,0.05)' },
                        ticks: { font: { size: 12 }, color: '#94a3b8' },
                    },
                    y: {
                        beginAtZero: true,
                        ticks: { precision: 0, font: { size: 12 }, color: '#94a3b8' },
                        grid: { color: 'rgba(0,0,0,0.05)' },
                    },
                },
            },
        });
    }

    /* ════ Legend stats ════ */
    function updateLegend(stats) {
        var fields = { 'mh-stat-total': stats.total, 'mh-stat-sent': stats.sent, 'mh-stat-failed': stats.failed };
        Object.keys(fields).forEach(function(id) {
            var e = el(id);
            if (e) e.textContent = fields[id];
        });
        var re = el('mh-stat-rate');
        if (re) {
            re.textContent = stats.rate + '%';
            re.className   = '';
            re.classList.add(stats.rate >= 85 ? 'mh-rate-good' : (stats.rate >= 50 ? 'mh-rate-warn' : 'mh-rate-bad'));
        }
    }

    /* ════ All-emails table ════ */
    function updateEmailsTable(subjects) {
        var tbody = document.querySelector('#mh-emails-table tbody');
        if (!tbody) return;
        if (!subjects || !subjects.length) {
            tbody.innerHTML = '<tr><td colspan="8" style="text-align:center;padding:20px;color:#94a3b8">No emails in this period.</td></tr>';
            return;
        }
        var html = '';
        subjects.forEach(function(row) {
            var total      = parseInt(row.total) || 0;
            var sent       = parseInt(row.sent)  || 0;
            var failed     = parseInt(row.failed)|| 0;
            var sentPct    = total > 0 ? Math.round(sent   / total * 100) : 0;
            var failedPct  = total > 0 ? Math.round(failed / total * 100) : 0;
            html += '<tr class="mh-row-subject" data-subject="' + escHtml(row.subject) + '">';
            html += '<td class="mh-td-subject">'  + escHtml(row.subject) + '</td>';
            html += '<td class="mh-td-source"><span class="mailhook-source-tag">' + escHtml(row.source || '') + '</span></td>';
            html += '<td class="mh-th-num"><strong>' + total + '</strong></td>';
            html += '<td class="mh-th-num mh-sent-num">'   + sent   + '<span class="mh-pct"> (' + sentPct   + '%)</span></td>';
            html += '<td class="mh-th-num mh-failed-num">' + failed + '<span class="mh-pct"> (' + failedPct + '%)</span></td>';
            html += '<td class="mh-th-num mh-open-num">0<span class="mh-pct"> (0%)</span></td>';
            html += '<td class="mh-th-num mh-click-num">0<span class="mh-pct"> (0%)</span></td>';
            html += '<td class="mh-th-graph"><button type="button" class="mh-graph-btn" data-subject="' + escHtml(row.subject) + '" title="View graph">';
            html += '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>';
            html += '</button></td>';
            html += '</tr>';
        });
        tbody.innerHTML = html;
        bindGraphBtns();
    }

    /* ════ Source Breakdown Table ════ */
    function updateSourceTable(sources) {
        var tbody = document.querySelector('#mh-source-table tbody');
        if (!tbody) return;
        if (!sources || !sources.length) {
            tbody.innerHTML = '<tr><td colspan="5" style="text-align:center;padding:18px;color:#94a3b8">No data.</td></tr>';
            return;
        }
        var grand = sources.reduce(function(a, r) { return a + parseInt(r.total); }, 0) || 1;
        var html  = '';
        sources.forEach(function(row) {
            var total    = parseInt(row.total)  || 0;
            var sent     = parseInt(row.sent)   || 0;
            var failed   = parseInt(row.failed) || 0;
            var pct      = Math.round(total / grand * 100);
            var sPct     = total > 0 ? Math.round(sent   / total * 100) : 0;
            var fPct     = total > 0 ? Math.round(failed / total * 100) : 0;
            html += '<tr>';
            html += '<td><span class="mailhook-source-tag">' + escHtml(row.source) + '</span></td>';
            html += '<td class="mh-th-num"><strong>' + total + '</strong></td>';
            html += '<td class="mh-th-num mh-sent-num">'   + sent   + '<span class="mh-pct"> (' + sPct + '%)</span></td>';
            html += '<td class="mh-th-num mh-failed-num">' + failed + '<span class="mh-pct"> (' + fPct + '%)</span></td>';
            html += '<td class="mh-col-bar"><div class="mh-bar-track"><div class="mh-bar-fill" style="width:' + pct + '%"></div></div><span class="mh-bar-pct">' + pct + '%</span></td>';
            html += '</tr>';
        });
        tbody.innerHTML = html;
    }

    /* ════ Subject drilldown chart ════ */
    function loadSubjectChart(subject) {
        currentMode = 'subject';

        var titleEl  = el('mh-chart-title');
        var backBtn  = el('mh-back-btn');
        if (titleEl) titleEl.textContent = D.i18n.drillTitle + subject;
        if (backBtn) backBtn.style.display = '';

        var fd = new FormData();
        fd.append('action',  'mailhook_get_subject_chart');
        fd.append('nonce',   D.nonce);
        fd.append('subject', subject);
        fd.append('from',    currentFrom);
        fd.append('to',      currentTo);

        fetch(D.ajaxurl, { method: 'POST', body: fd })
            .then(function(r) { return r.json(); })
            .then(function(resp) {
                if (!resp.success) return;
                var d = resp.data;
                renderChart(d.labels, d.total, d.sent, d.failed);
            });
    }

    /* ════ Fetch full range data ════ */
    function fetchRange(range, fromDate, toDate) {
        var fd = new FormData();
        fd.append('action', 'mailhook_get_report_data');
        fd.append('nonce',  D.nonce);
        fd.append('range',  range);
        if (fromDate) { fd.append('from_date', fromDate); currentFrom = fromDate; }
        if (toDate)   { fd.append('to_date',   toDate);   currentTo   = toDate;   }

        fetch(D.ajaxurl, { method: 'POST', body: fd })
            .then(function(r) { return r.json(); })
            .then(function(resp) {
                if (!resp.success) return;
                var d = resp.data;
                renderChart(d.labels, d.totalData, d.sentData, d.failedData);
                updateLegend(d.stats);
                updateEmailsTable(d.subjects);
                updateSourceTable(d.sources);
                resetToAllEmails();
            });
    }

    /* ════ Reset drilldown state ════ */
    function resetToAllEmails() {
        currentMode = 'all';
        var titleEl = el('mh-chart-title');
        var backBtn = el('mh-back-btn');
        if (titleEl) titleEl.textContent = D.i18n.allEmails;
        if (backBtn) backBtn.style.display = 'none';
    }

    /* ════ Client-side search ════ */
    function initSearch() {
        var input = el('mh-email-search');
        if (!input) return;
        input.addEventListener('input', function() {
            var q = input.value.toLowerCase().trim();
            var rows = document.querySelectorAll('#mh-emails-table tbody .mh-row-subject');
            rows.forEach(function(row) {
                var text = row.textContent.toLowerCase();
                row.style.display = q === '' || text.indexOf(q) !== -1 ? '' : 'none';
            });
        });
    }

    /* ════ Bind graph buttons (called after table re-render too) ════ */
    function bindGraphBtns() {
        document.querySelectorAll('.mh-graph-btn').forEach(function(btn) {
            btn.addEventListener('click', function() {
                loadSubjectChart(btn.dataset.subject);
            });
        });
    }

    /* ════ DOMContentLoaded ════ */
    document.addEventListener('DOMContentLoaded', function () {

        // Seed dates from WP (resolved PHP range)
        currentFrom = D.resolvedFrom || '';
        currentTo   = D.resolvedTo   || '';

        // Initial chart
        renderChart(D.labels, D.totalData, D.sentData, D.failedData);
        bindGraphBtns();
        initSearch();

        // Range select + filter button
        var select    = el('mh-range-select');
        var filterBtn = el('mh-filter-btn');
        var custom    = el('mh-custom-range');

        if (select) {
            select.addEventListener('change', function() {
                if (custom) custom.classList.toggle('visible', select.value === 'custom');
            });
        }

        if (filterBtn) {
            filterBtn.addEventListener('click', function() {
                var range = select ? select.value : '7';
                var from  = '';
                var to    = '';
                if (range === 'custom') {
                    from = el('mh-from-date') ? el('mh-from-date').value : '';
                    to   = el('mh-to-date')   ? el('mh-to-date').value   : '';
                    if (!from || !to) { alert('Please select both a start and end date.'); return; }
                    currentFrom = from;
                    currentTo   = to;
                } else {
                    // resolve client-side for drilldown subsequent calls
                    var days = parseInt(range) || 7;
                    var now  = new Date();
                    var past = new Date(now.getTime() - days * 86400000);
                    currentFrom = past.toISOString().slice(0,10);
                    currentTo   = now.toISOString().slice(0,10);
                }
                fetchRange(range, from, to);
                resetToAllEmails();
            });
        }

        // Back button (drilldown → all)
        var backBtn = el('mh-back-btn');
        if (backBtn) {
            backBtn.addEventListener('click', function() {
                resetToAllEmails();
                renderChart(D.labels, D.totalData, D.sentData, D.failedData);
            });
        }
    });
}());
JS;
	}
}

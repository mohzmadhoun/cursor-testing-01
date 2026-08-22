<?php
/**
 * Settings page.
 *
 * @package ChatHearth
 */

declare(strict_types=1);

namespace ChatHearth\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use ChatHearth\Options;
use ChatHearth\Plugin;

/**
 * Registers the ChatHearth - AI Chatbot settings UI.
 */
final class Settings_Page {

	public const PAGE_SLUG = 'chathearth';

	/**
	 * Register hooks.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_filter( 'wp_redirect', array( $this, 'preserve_tab_on_redirect' ), 10, 2 );
	}

	/**
	 * Keep the active tab after settings are saved via options.php.
	 *
	 * @param string $location Redirect URL.
	 * @param int    $status   HTTP status.
	 * @return string
	 */
	public function preserve_tab_on_redirect( string $location, int $status ): string {
		unset( $status );

		if ( ! is_admin() ) {
			return $location;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Reading tab only after options.php save.
		if ( empty( $_POST['option_page'] ) || 'chathearth_settings_group' !== $_POST['option_page'] ) {
			return $location;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$tab = isset( $_POST['chathearth_active_tab'] ) ? sanitize_key( wp_unslash( (string) $_POST['chathearth_active_tab'] ) ) : '';
		if ( ! array_key_exists( $tab, $this->tabs() ) ) {
			return $location;
		}

		return add_query_arg( 'tab', $tab, $location );
	}

	/**
	 * Add Settings submenu page.
	 */
	public function add_menu(): void {
		add_options_page(
			__( 'ChatHearth - AI Chatbot', 'chathearth' ),
			__( 'ChatHearth - AI Chatbot', 'chathearth' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Register setting.
	 */
	public function register_settings(): void {
		register_setting(
			'chathearth_settings_group',
			Options::OPTION_KEY,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( Options::class, 'sanitize' ),
				'default'           => Options::defaults(),
			)
		);
	}

	/**
	 * Enqueue admin assets on our page.
	 *
	 * @param string $hook_suffix Current admin page hook.
	 */
	public function enqueue( string $hook_suffix ): void {
		if ( 'settings_page_' . self::PAGE_SLUG !== $hook_suffix ) {
			return;
		}

		wp_enqueue_style( 'wp-color-picker' );
		wp_enqueue_script( 'wp-color-picker' );

		wp_enqueue_style(
			'chathearth-admin',
			CHATHEARTH_URL . 'assets/css/admin.css',
			array( 'wp-color-picker' ),
			CHATHEARTH_VERSION
		);

		wp_enqueue_script(
			'chathearth-admin',
			CHATHEARTH_URL . 'assets/js/admin.js',
			array( 'jquery', 'wp-color-picker' ),
			CHATHEARTH_VERSION,
			true
		);

		wp_localize_script(
			'chathearth-admin',
			'chatHearthAdmin',
			array(
				'statusUrl'  => esc_url_raw( rest_url( 'chathearth/v1/kb/status' ) ),
				'syncUrl'    => esc_url_raw( rest_url( 'chathearth/v1/kb/sync' ) ),
				'entriesUrl' => esc_url_raw( rest_url( 'chathearth/v1/kb/entries' ) ),
				'pingUrl'    => esc_url_raw( rest_url( 'chathearth/v1/kb/ping' ) ),
				'nonce'      => wp_create_nonce( 'wp_rest' ),
				'i18n'       => array(
					'syncing'    => __( 'Syncing…', 'chathearth' ),
					'synced'     => __( 'Sync finished.', 'chathearth' ),
					'syncFailed' => __( 'Sync failed.', 'chathearth' ),
					'include'    => __( 'Included', 'chathearth' ),
					'exclude'    => __( 'Excluded', 'chathearth' ),
					'pingOk'     => __( 'Knowledge base storage is ready.', 'chathearth' ),
					'pingFail'   => __( 'Knowledge base storage is not ready.', 'chathearth' ),
					'empty'      => __( 'No knowledge-base entries yet. Save settings, then click Sync now.', 'chathearth' ),
				),
			)
		);
	}

	/**
	 * Available settings tabs.
	 *
	 * @return array<string, string> Tab id => label.
	 */
	private function tabs(): array {
		return array(
			'welcome'        => __( 'Welcome', 'chathearth' ),
			'protection'     => __( 'Protection', 'chathearth' ),
			'appearance'     => __( 'Appearance', 'chathearth' ),
			'ai-settings'    => __( 'AI Settings', 'chathearth' ),
			'knowledge-base' => __( 'Knowledge Base', 'chathearth' ),
		);
	}

	/**
	 * Current tab from request (defaults to welcome).
	 */
	private function current_tab(): string {
		$tabs = $this->tabs();
		$tab  = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( (string) $_GET['tab'] ) ) : 'welcome'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		return array_key_exists( $tab, $tabs ) ? $tab : 'welcome';
	}

	/**
	 * Admin URL for a settings tab.
	 *
	 * @param string $tab Tab id.
	 */
	private function tab_url( string $tab ): string {
		return add_query_arg(
			array(
				'page' => self::PAGE_SLUG,
				'tab'  => $tab,
			),
			admin_url( 'options-general.php' )
		);
	}

	/**
	 * Render settings page.
	 */
	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$settings    = Options::all();
		$tabs        = $this->tabs();
		$opt         = Options::OPTION_KEY;
		$current_tab = $this->current_tab();
		?>
		<div class="wrap chathearth-settings" data-current-tab="<?php echo esc_attr( $current_tab ); ?>">
			<h1><?php echo esc_html__( 'ChatHearth - AI Chatbot Settings', 'chathearth' ); ?></h1>

			<p class="description">
				<?php
				echo esc_html__(
					'Configure OpenAI API key under WordPress Connectors. This plugin does not store the API key.',
					'chathearth'
				);
				?>
				<a href="<?php echo esc_url( admin_url( 'options-connectors.php' ) ); ?>"><?php echo esc_html__( 'Open Connectors', 'chathearth' ); ?></a>
			</p>

			<nav class="nav-tab-wrapper chathearth-tabs" aria-label="<?php echo esc_attr__( 'Settings sections', 'chathearth' ); ?>">
				<?php foreach ( $tabs as $tab_id => $label ) : ?>
					<a href="<?php echo esc_url( $this->tab_url( $tab_id ) ); ?>"
						class="nav-tab<?php echo $current_tab === $tab_id ? ' nav-tab-active' : ''; ?>"
						data-tab="<?php echo esc_attr( $tab_id ); ?>"
						role="tab"
						aria-selected="<?php echo $current_tab === $tab_id ? 'true' : 'false'; ?>">
						<?php echo esc_html( $label ); ?>
					</a>
				<?php endforeach; ?>
			</nav>

			<form method="post" action="options.php">
				<?php settings_fields( 'chathearth_settings_group' ); ?>
				<input type="hidden" name="chathearth_active_tab" id="chathearth_active_tab" value="<?php echo esc_attr( $current_tab ); ?>" />

				<div class="chathearth-tab-panel" id="chathearth-tab-welcome" data-tab-panel="welcome" role="tabpanel"<?php echo 'welcome' !== $current_tab ? ' hidden' : ''; ?>>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="chathearth_welcome"><?php echo esc_html__( 'Welcome message', 'chathearth' ); ?></label></th>
							<td><textarea name="<?php echo esc_attr( $opt ); ?>[welcome_message]" id="chathearth_welcome" rows="3" class="large-text"><?php echo esc_textarea( (string) $settings['welcome_message'] ); ?></textarea></td>
						</tr>
						<tr>
							<th scope="row"><label for="chathearth_starters"><?php echo esc_html__( 'Starter phrases', 'chathearth' ); ?></label></th>
							<td>
								<textarea name="<?php echo esc_attr( $opt ); ?>[starter_phrases]" id="chathearth_starters" rows="5" class="large-text"><?php echo esc_textarea( (string) $settings['starter_phrases'] ); ?></textarea>
								<p class="description"><?php echo esc_html__( 'One suggested prompt per line. Shown as clickable chips in the chat.', 'chathearth' ); ?></p>
							</td>
						</tr>
					</table>
				</div>

				<div class="chathearth-tab-panel" id="chathearth-tab-protection" data-tab-panel="protection" role="tabpanel"<?php echo 'protection' !== $current_tab ? ' hidden' : ''; ?>>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><?php echo esc_html__( 'Enable chatbot', 'chathearth' ); ?></th>
							<td>
								<label>
									<input type="checkbox" name="<?php echo esc_attr( $opt ); ?>[chat_enabled]" value="1" <?php checked( ! empty( $settings['chat_enabled'] ) ); ?> />
									<?php echo esc_html__( 'Show the chatbot on the front end and allow chat requests', 'chathearth' ); ?>
								</label>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="chathearth_rate_min"><?php echo esc_html__( 'Max requests per minute (per IP)', 'chathearth' ); ?></label></th>
							<td><input name="<?php echo esc_attr( $opt ); ?>[rate_limit_per_minute]" id="chathearth_rate_min" type="number" min="1" max="120" value="<?php echo esc_attr( (string) $settings['rate_limit_per_minute'] ); ?>" class="small-text" /></td>
						</tr>
						<tr>
							<th scope="row"><label for="chathearth_rate_hour"><?php echo esc_html__( 'Max requests per hour (per IP)', 'chathearth' ); ?></label></th>
							<td><input name="<?php echo esc_attr( $opt ); ?>[rate_limit_per_hour]" id="chathearth_rate_hour" type="number" min="1" max="1000" value="<?php echo esc_attr( (string) $settings['rate_limit_per_hour'] ); ?>" class="small-text" /></td>
						</tr>
						<tr>
							<th scope="row"><label for="chathearth_global_rate_min"><?php echo esc_html__( 'Max requests per minute (entire site)', 'chathearth' ); ?></label></th>
							<td>
								<input name="<?php echo esc_attr( $opt ); ?>[global_rate_limit_per_minute]" id="chathearth_global_rate_min" type="number" min="1" max="1000" value="<?php echo esc_attr( (string) $settings['global_rate_limit_per_minute'] ); ?>" class="small-text" />
								<p class="description"><?php echo esc_html__( 'Applies across all visitors. Helps stop multi-IP floods.', 'chathearth' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="chathearth_global_rate_hour"><?php echo esc_html__( 'Max requests per hour (entire site)', 'chathearth' ); ?></label></th>
							<td><input name="<?php echo esc_attr( $opt ); ?>[global_rate_limit_per_hour]" id="chathearth_global_rate_hour" type="number" min="1" max="10000" value="<?php echo esc_attr( (string) $settings['global_rate_limit_per_hour'] ); ?>" class="small-text" /></td>
						</tr>
						<tr>
							<th scope="row"><label for="chathearth_global_incidents"><?php echo esc_html__( 'Escalate after global limit hits', 'chathearth' ); ?></label></th>
							<td>
								<input name="<?php echo esc_attr( $opt ); ?>[global_limit_incident_threshold]" id="chathearth_global_incidents" type="number" min="1" max="50" value="<?php echo esc_attr( (string) $settings['global_limit_incident_threshold'] ); ?>" class="small-text" />
								<p class="description"><?php echo esc_html__( 'Number of site-wide limit blocks within an hour before emailing the admin.', 'chathearth' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php echo esc_html__( 'Auto-disable on escalation', 'chathearth' ); ?></th>
							<td>
								<label>
									<input type="checkbox" name="<?php echo esc_attr( $opt ); ?>[auto_disable_on_global_escalation]" value="1" <?php checked( ! empty( $settings['auto_disable_on_global_escalation'] ) ); ?> />
									<?php echo esc_html__( 'Turn off the chatbot when the escalation threshold is reached', 'chathearth' ); ?>
								</label>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="chathearth_max_len"><?php echo esc_html__( 'Max message length', 'chathearth' ); ?></label></th>
							<td><input name="<?php echo esc_attr( $opt ); ?>[max_message_length]" id="chathearth_max_len" type="number" min="100" max="8000" value="<?php echo esc_attr( (string) $settings['max_message_length'] ); ?>" class="small-text" /></td>
						</tr>
						<tr>
							<th scope="row"><?php echo esc_html__( 'Google reCAPTCHA v2', 'chathearth' ); ?></th>
							<td>
								<?php if ( Options::is_recaptcha_enabled() ) : ?>
									<div class="notice notice-success inline chathearth-recaptcha-status">
										<p><?php echo esc_html__( 'CAPTCHA is enabled. Visitors complete the reCAPTCHA v2 checkbox once; further messages in the next hour stay unlocked.', 'chathearth' ); ?></p>
									</div>
								<?php else : ?>
									<p class="description"><?php echo esc_html__( 'CAPTCHA is disabled until both site key and secret key are set. Create a reCAPTCHA v2 (“I’m not a robot” Checkbox) key for your domain.', 'chathearth' ); ?></p>
								<?php endif; ?>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="chathearth_recaptcha_site"><?php echo esc_html__( 'reCAPTCHA site key', 'chathearth' ); ?></label></th>
							<td>
								<input name="<?php echo esc_attr( $opt ); ?>[recaptcha_site_key]" id="chathearth_recaptcha_site" type="text" class="regular-text" value="<?php echo esc_attr( (string) $settings['recaptcha_site_key'] ); ?>" autocomplete="off" />
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="chathearth_recaptcha_secret"><?php echo esc_html__( 'reCAPTCHA secret key', 'chathearth' ); ?></label></th>
							<td>
								<input name="<?php echo esc_attr( $opt ); ?>[recaptcha_secret_key]" id="chathearth_recaptcha_secret" type="password" class="regular-text" value="" autocomplete="new-password" placeholder="<?php echo esc_attr( '' !== (string) $settings['recaptcha_secret_key'] ? __( '•••••••• (saved — leave blank to keep)', 'chathearth' ) : '' ); ?>" />
								<p class="description">
									<label>
										<input type="checkbox" name="<?php echo esc_attr( $opt ); ?>[recaptcha_clear_secret]" value="1" />
										<?php echo esc_html__( 'Clear saved secret key', 'chathearth' ); ?>
									</label>
								</p>
								<p class="description">
									<?php
									echo esc_html__( 'Get keys from Google reCAPTCHA admin. Leave the secret blank when saving to keep the current value.', 'chathearth' );
									?>
								</p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php echo esc_html__( 'Content moderation', 'chathearth' ); ?></th>
							<td>
								<label>
									<input type="checkbox" name="<?php echo esc_attr( $opt ); ?>[content_moderation_enabled]" value="1" <?php checked( ! empty( $settings['content_moderation_enabled'] ) ); ?> />
									<?php echo esc_html__( 'Check visitor messages before they reach the AI model', 'chathearth' ); ?>
								</label>
								<p class="description"><?php echo esc_html__( 'Uses a keyword list and, optionally, OpenAI’s Moderations API. Flagged messages are blocked with your custom reply below.', 'chathearth' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php echo esc_html__( 'OpenAI Moderations API', 'chathearth' ); ?></th>
							<td>
								<label>
									<input type="checkbox" name="<?php echo esc_attr( $opt ); ?>[moderation_use_openai]" value="1" <?php checked( ! empty( $settings['moderation_use_openai'] ) ); ?> />
									<?php echo esc_html__( 'Send message text to OpenAI Moderations before generating a reply', 'chathearth' ); ?>
								</label>
								<?php if ( Plugin::is_openai_ready() ) : ?>
									<p class="description"><?php echo esc_html__( 'Uses the same OpenAI API key from WordPress Connectors. No second key is stored in this plugin.', 'chathearth' ); ?></p>
								<?php else : ?>
									<p class="description">
										<?php echo esc_html__( 'OpenAI is not ready yet. Configure the API key under Connectors; until then only the keyword list applies when moderation is enabled.', 'chathearth' ); ?>
										<a href="<?php echo esc_url( admin_url( 'options-connectors.php' ) ); ?>"><?php echo esc_html__( 'Open Connectors', 'chathearth' ); ?></a>
									</p>
								<?php endif; ?>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="chathearth_moderation_phrases"><?php echo esc_html__( 'Disallowed phrases', 'chathearth' ); ?></label></th>
							<td>
								<textarea name="<?php echo esc_attr( $opt ); ?>[moderation_disallowed_phrases]" id="chathearth_moderation_phrases" rows="5" class="large-text"><?php echo esc_textarea( (string) $settings['moderation_disallowed_phrases'] ); ?></textarea>
								<p class="description"><?php echo esc_html__( 'One phrase per line. Case-insensitive substring match against the current message and prior user turns. Leave empty to skip the keyword layer.', 'chathearth' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="chathearth_moderation_block_msg"><?php echo esc_html__( 'Blocked-message reply', 'chathearth' ); ?></label></th>
							<td>
								<input name="<?php echo esc_attr( $opt ); ?>[moderation_block_message]" id="chathearth_moderation_block_msg" type="text" class="large-text" value="<?php echo esc_attr( (string) $settings['moderation_block_message'] ); ?>" />
								<p class="description"><?php echo esc_html__( 'Shown in the chat widget when a message is blocked.', 'chathearth' ); ?></p>
							</td>
						</tr>
					</table>
				</div>

				<div class="chathearth-tab-panel" id="chathearth-tab-appearance" data-tab-panel="appearance" role="tabpanel"<?php echo 'appearance' !== $current_tab ? ' hidden' : ''; ?>>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="chathearth_icon_shape"><?php echo esc_html__( 'Icon shape', 'chathearth' ); ?></label></th>
							<td>
								<select name="<?php echo esc_attr( $opt ); ?>[icon_shape]" id="chathearth_icon_shape">
									<option value="circle" <?php selected( $settings['icon_shape'], 'circle' ); ?>><?php echo esc_html__( 'Circle', 'chathearth' ); ?></option>
									<option value="square" <?php selected( $settings['icon_shape'], 'square' ); ?>><?php echo esc_html__( 'Square', 'chathearth' ); ?></option>
								</select>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="chathearth_position"><?php echo esc_html__( 'Position', 'chathearth' ); ?></label></th>
							<td>
								<select name="<?php echo esc_attr( $opt ); ?>[position]" id="chathearth_position">
									<option value="bottom-right" <?php selected( $settings['position'], 'bottom-right' ); ?>><?php echo esc_html__( 'Bottom right', 'chathearth' ); ?></option>
									<option value="bottom-left" <?php selected( $settings['position'], 'bottom-left' ); ?>><?php echo esc_html__( 'Bottom left', 'chathearth' ); ?></option>
								</select>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="chathearth_popup_size"><?php echo esc_html__( 'Popup size', 'chathearth' ); ?></label></th>
							<td>
								<select name="<?php echo esc_attr( $opt ); ?>[popup_size]" id="chathearth_popup_size">
									<option value="small" <?php selected( $settings['popup_size'], 'small' ); ?>><?php echo esc_html__( 'Small', 'chathearth' ); ?></option>
									<option value="medium" <?php selected( $settings['popup_size'], 'medium' ); ?>><?php echo esc_html__( 'Medium', 'chathearth' ); ?></option>
									<option value="large" <?php selected( $settings['popup_size'], 'large' ); ?>><?php echo esc_html__( 'Large', 'chathearth' ); ?></option>
								</select>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="chathearth_icon_size"><?php echo esc_html__( 'Icon size (px)', 'chathearth' ); ?></label></th>
							<td><input name="<?php echo esc_attr( $opt ); ?>[icon_size]" id="chathearth_icon_size" type="number" min="40" max="96" value="<?php echo esc_attr( (string) $settings['icon_size'] ); ?>" class="small-text" /></td>
						</tr>
						<tr>
							<th scope="row"><label for="chathearth_header_title"><?php echo esc_html__( 'Header title', 'chathearth' ); ?></label></th>
							<td><input name="<?php echo esc_attr( $opt ); ?>[header_title]" id="chathearth_header_title" type="text" class="regular-text" value="<?php echo esc_attr( (string) $settings['header_title'] ); ?>" /></td>
						</tr>
						<?php
						$colors = array(
							'icon_background_color'  => __( 'Icon background color', 'chathearth' ),
							'icon_border_color'      => __( 'Icon border color', 'chathearth' ),
							'icon_color'             => __( 'Icon (glyph) color', 'chathearth' ),
							'user_bubble_color'      => __( 'User bubble color', 'chathearth' ),
							'assistant_bubble_color' => __( 'Assistant bubble color', 'chathearth' ),
						);
						foreach ( $colors as $key => $label ) :
							?>
							<tr>
								<th scope="row"><label for="chathearth_<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label></th>
								<td><input name="<?php echo esc_attr( $opt ); ?>[<?php echo esc_attr( $key ); ?>]" id="chathearth_<?php echo esc_attr( $key ); ?>" type="text" class="chathearth-color-field" value="<?php echo esc_attr( (string) $settings[ $key ] ); ?>" data-default-color="<?php echo esc_attr( (string) Options::defaults()[ $key ] ); ?>" /></td>
							</tr>
						<?php endforeach; ?>
					</table>
				</div>

				<div class="chathearth-tab-panel" id="chathearth-tab-ai-settings" data-tab-panel="ai-settings" role="tabpanel"<?php echo 'ai-settings' !== $current_tab ? ' hidden' : ''; ?>>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="chathearth_ai_provider"><?php echo esc_html__( 'AI provider', 'chathearth' ); ?></label></th>
							<td>
								<select name="<?php echo esc_attr( $opt ); ?>[ai_provider]" id="chathearth_ai_provider">
									<?php foreach ( Options::available_providers() as $provider_id => $provider_label ) : ?>
										<option value="<?php echo esc_attr( $provider_id ); ?>" <?php selected( $settings['ai_provider'], $provider_id ); ?>><?php echo esc_html( $provider_label ); ?></option>
									<?php endforeach; ?>
								</select>
								<p class="description"><?php echo esc_html__( 'More providers can be added in future releases. Configure the API key under Connectors.', 'chathearth' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="chathearth_ai_model"><?php echo esc_html__( 'Chat model', 'chathearth' ); ?></label></th>
							<td>
								<select name="<?php echo esc_attr( $opt ); ?>[ai_model]" id="chathearth_ai_model">
									<?php foreach ( Options::available_openai_models() as $model_id => $model_label ) : ?>
										<option value="<?php echo esc_attr( $model_id ); ?>" <?php selected( $settings['ai_model'], $model_id ); ?>><?php echo esc_html( $model_label ); ?></option>
									<?php endforeach; ?>
								</select>
								<p class="description"><?php echo esc_html__( 'Select an OpenAI model for chat replies.', 'chathearth' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="chathearth_system"><?php echo esc_html__( 'System prompt', 'chathearth' ); ?></label></th>
							<td>
								<textarea name="<?php echo esc_attr( $opt ); ?>[system_prompt]" id="chathearth_system" rows="6" class="large-text"><?php echo esc_textarea( (string) $settings['system_prompt'] ); ?></textarea>
								<p class="description"><?php echo esc_html__( 'Extra instructions for the model. ChatHearth always adds website-only grounding (and retrieved knowledge when RAG is enabled) around this text.', 'chathearth' ); ?></p>
							</td>
						</tr>
					</table>
				</div>

				<?php $this->render_knowledge_base_tab( $settings, $opt, $current_tab ); ?>

				<?php submit_button( __( 'Save settings', 'chathearth' ) ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Knowledge Base tab.
	 *
	 * @param array<string, mixed> $settings Settings.
	 * @param string               $opt      Option key.
	 * @param string               $current_tab Current tab.
	 */
	private function render_knowledge_base_tab( array $settings, string $opt, string $current_tab ): void {
		$post_types  = Options::available_content_post_types();
		$taxonomies  = Options::available_content_taxonomies();
		$selected_pt = Options::rag_post_types();
		$selected_tx = Options::rag_taxonomies();
		?>
		<div class="chathearth-tab-panel" id="chathearth-tab-knowledge-base" data-tab-panel="knowledge-base" role="tabpanel"<?php echo 'knowledge-base' !== $current_tab ? ' hidden' : ''; ?>>
			<input type="hidden" name="<?php echo esc_attr( $opt ); ?>[rag_vector_store]" value="builtin" />
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php echo esc_html__( 'Enable RAG', 'chathearth' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="<?php echo esc_attr( $opt ); ?>[rag_enabled]" value="1" <?php checked( ! empty( $settings['rag_enabled'] ) ); ?> />
							<?php echo esc_html__( 'Retrieve matching website content for each question and add it to the model context', 'chathearth' ); ?>
						</label>
						<p class="description"><?php echo esc_html__( 'The assistant stays limited to this website even when RAG is off. Turn this on after selecting sources and running Sync now.', 'chathearth' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php echo esc_html__( 'Storage', 'chathearth' ); ?></th>
					<td>
						<p><?php echo esc_html__( "Indexed passages are stored in this site's WordPress database. Installing ChatHearth is enough. No Python, Chroma, Pinecone, or other extra software is required.", 'chathearth' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php echo esc_html__( 'Post types', 'chathearth' ); ?></th>
					<td>
						<?php foreach ( $post_types as $slug => $label ) : ?>
							<label class="chathearth-check-row">
								<input type="checkbox" name="<?php echo esc_attr( $opt ); ?>[rag_post_types][]" value="<?php echo esc_attr( $slug ); ?>" <?php checked( in_array( $slug, $selected_pt, true ) ); ?> />
								<?php echo esc_html( $label ); ?>
								<code><?php echo esc_html( $slug ); ?></code>
							</label>
						<?php endforeach; ?>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php echo esc_html__( 'Taxonomies', 'chathearth' ); ?></th>
					<td>
						<?php foreach ( $taxonomies as $slug => $label ) : ?>
							<label class="chathearth-check-row">
								<input type="checkbox" name="<?php echo esc_attr( $opt ); ?>[rag_taxonomies][]" value="<?php echo esc_attr( $slug ); ?>" <?php checked( in_array( $slug, $selected_tx, true ) ); ?> />
								<?php echo esc_html( $label ); ?>
								<code><?php echo esc_html( $slug ); ?></code>
							</label>
						<?php endforeach; ?>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php echo esc_html__( 'Other site data', 'chathearth' ); ?></th>
					<td>
						<label class="chathearth-check-row">
							<input type="checkbox" name="<?php echo esc_attr( $opt ); ?>[rag_include_site_identity]" value="1" <?php checked( ! empty( $settings['rag_include_site_identity'] ) ); ?> />
							<?php echo esc_html__( 'Site name, tagline, URL, and public page list', 'chathearth' ); ?>
						</label>
						<label class="chathearth-check-row">
							<input type="checkbox" name="<?php echo esc_attr( $opt ); ?>[rag_include_woocommerce]" value="1" <?php checked( ! empty( $settings['rag_include_woocommerce'] ) ); ?> />
							<?php echo esc_html__( 'WooCommerce shop URLs, currency, and categories (when WooCommerce is active)', 'chathearth' ); ?>
						</label>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="chathearth_rag_top_k"><?php echo esc_html__( 'Passages per question', 'chathearth' ); ?></label></th>
					<td>
						<input name="<?php echo esc_attr( $opt ); ?>[rag_top_k]" id="chathearth_rag_top_k" type="number" min="1" max="12" value="<?php echo esc_attr( (string) $settings['rag_top_k'] ); ?>" class="small-text" />
						<label for="chathearth_rag_chunk" class="chathearth-inline-label"><?php echo esc_html__( 'Chunk size', 'chathearth' ); ?></label>
						<input name="<?php echo esc_attr( $opt ); ?>[rag_chunk_size]" id="chathearth_rag_chunk" type="number" min="400" max="4000" value="<?php echo esc_attr( (string) $settings['rag_chunk_size'] ); ?>" class="small-text" />
					</td>
				</tr>
			</table>

			<div class="chathearth-kb-toolbar">
				<button type="button" class="button button-primary" id="chathearth-kb-sync"><?php echo esc_html__( 'Sync now', 'chathearth' ); ?></button>
				<button type="button" class="button" id="chathearth-kb-ping"><?php echo esc_html__( 'Test knowledge base', 'chathearth' ); ?></button>
				<span class="chathearth-kb-status" id="chathearth-kb-status" aria-live="polite"></span>
			</div>
			<p class="description" id="chathearth-kb-counts"></p>

			<div class="chathearth-kb-entries-wrap">
				<p>
					<label for="chathearth-kb-search"><?php echo esc_html__( 'Search entries', 'chathearth' ); ?></label>
					<input type="search" id="chathearth-kb-search" class="regular-text" />
				</p>
				<table class="widefat striped" id="chathearth-kb-table">
					<thead>
						<tr>
							<th><?php echo esc_html__( 'Title', 'chathearth' ); ?></th>
							<th><?php echo esc_html__( 'Source', 'chathearth' ); ?></th>
							<th><?php echo esc_html__( 'Status', 'chathearth' ); ?></th>
							<th><?php echo esc_html__( 'In RAG', 'chathearth' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<tr><td colspan="4"><?php echo esc_html__( 'Loading…', 'chathearth' ); ?></td></tr>
					</tbody>
				</table>
				<p class="chathearth-kb-pager" id="chathearth-kb-pager"></p>
			</div>
		</div>
		<?php
	}
}

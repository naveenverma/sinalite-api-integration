<?php
/**
 * WP-CLI commands.
 *
 * @package SinaliteWooCommerce
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_CLI_Command' ) ) {
	return;
}

class Sinalite_WC_CLI extends WP_CLI_Command {
	/**
	 * Settings helper.
	 *
	 * @var Sinalite_WC_Settings
	 */
	private Sinalite_WC_Settings $settings;

	/**
	 * API client.
	 *
	 * @var Sinalite_WC_API_Client
	 */
	private Sinalite_WC_API_Client $api_client;

	/**
	 * Importer.
	 *
	 * @var Sinalite_WC_Importer
	 */
	private Sinalite_WC_Importer $importer;

	/**
	 * Register command.
	 *
	 * @param Sinalite_WC_Settings   $settings Settings.
	 * @param Sinalite_WC_API_Client $api_client API.
	 * @param Sinalite_WC_Importer   $importer Importer.
	 * @return void
	 */
	public static function register( Sinalite_WC_Settings $settings, Sinalite_WC_API_Client $api_client, Sinalite_WC_Importer $importer ): void {
		$command = new self();
		$command->settings   = $settings;
		$command->api_client = $api_client;
		$command->importer   = $importer;
		WP_CLI::add_command( 'sinalite-wc', $command );
	}

	/**
	 * Ensure DB tables exist.
	 *
	 * @subcommand db-install
	 *
	 * ## EXAMPLES
	 *
	 *     wp sinalite-wc db-install
	 *
	 * @return void
	 */
	public function db_install(): void {
		Sinalite_WC_Installer::activate();
		WP_CLI::success( 'Sinalite staging tables created/updated.' );
	}

	/**
	 * Test auth token request.
	 *
	 * @subcommand test-auth
	 *
	 * ## EXAMPLES
	 *
	 *     wp sinalite-wc test-auth
	 *
	 * @return void
	 */
	public function test_auth(): void {
		$token = $this->api_client->get_access_token( true );
		if ( is_wp_error( $token ) ) {
			WP_CLI::error( $token->get_error_message() );
			return;
		}
		WP_CLI::success( 'Token request succeeded.' );
	}

	/**
	 * Fetch products/options/variants from API to staging tables.
	 *
	 * @subcommand fetch
	 *
	 * ## OPTIONS
	 *
	 * [--limit=<limit>]
	 * : Number of products to fetch.
	 *
	 * [--offset=<offset>]
	 * : Product list offset.
	 *
	 * [--max-variant-pages=<pages>]
	 * : Maximum variant pages per product (1000 rows per page).
	 *
	 * ## EXAMPLES
	 *
	 *     wp sinalite-wc fetch --limit=50 --offset=0 --max-variant-pages=1
	 *
	 * @param array<int, string>          $args Positional args.
	 * @param array<string, string|bool>  $assoc_args Named args.
	 * @return void
	 */
	public function fetch( array $args, array $assoc_args ): void {
		$limit             = isset( $assoc_args['limit'] ) ? max( 1, (int) $assoc_args['limit'] ) : 50;
		$offset            = isset( $assoc_args['offset'] ) ? max( 0, (int) $assoc_args['offset'] ) : 0;
		$max_variant_pages = isset( $assoc_args['max-variant-pages'] ) ? max( 1, (int) $assoc_args['max-variant-pages'] ) : 1;

		try {
			$result = $this->importer->fetch_to_staging( $limit, $offset, $max_variant_pages );
		} catch ( Throwable $e ) {
			WP_CLI::error( $e->getMessage() );
			return;
		}

		WP_CLI::success(
			sprintf(
				'Staged products=%d, options=%d, variants=%d, errors=%d',
				$result['products'],
				$result['options'],
				$result['variants'],
				$result['errors']
			)
		);
	}

	/**
	 * Process staged rows into WooCommerce products.
	 *
	 * @subcommand process
	 *
	 * ## OPTIONS
	 *
	 * [--batch=<batch>]
	 * : Number of staged products to process.
	 *
	 * [--max-variations=<max>]
	 * : Max variations created per variable product.
	 *
	 * ## EXAMPLES
	 *
	 *     wp sinalite-wc process --batch=20 --max-variations=200
	 *
	 * @param array<int, string>         $args Positional args.
	 * @param array<string, string|bool> $assoc_args Named args.
	 * @return void
	 */
	public function process( array $args, array $assoc_args ): void {
		$batch          = isset( $assoc_args['batch'] ) ? max( 1, (int) $assoc_args['batch'] ) : (int) $this->settings->get( 'batch_size', 20 );
		$max_variations = isset( $assoc_args['max-variations'] ) ? max( 1, (int) $assoc_args['max-variations'] ) : 200;

		$result = $this->importer->process_products( $batch, $max_variations );
		WP_CLI::success(
			sprintf(
				'Processed simple=%d, variable=%d, errors=%d',
				$result['simple'],
				$result['variable'],
				$result['errors']
			)
		);
	}

	/**
	 * Run one fetch + process cycle.
	 *
	 * @subcommand run-batch
	 *
	 * ## OPTIONS
	 *
	 * [--fetch-limit=<n>]
	 * : Products to stage this run.
	 *
	 * [--fetch-offset=<n>]
	 * : Start offset for fetch list.
	 *
	 * [--process-batch=<n>]
	 * : Staged product rows to process.
	 *
	 * [--max-variant-pages=<n>]
	 * : Max variant pages per fetched product.
	 *
	 * [--max-variations=<n>]
	 * : Max variations to build per product.
	 *
	 * ## EXAMPLES
	 *
	 *     wp sinalite-wc run-batch --fetch-limit=20 --process-batch=20
	 *
	 * @param array<int, string>         $args Positional args.
	 * @param array<string, string|bool> $assoc_args Named args.
	 * @return void
	 */
	public function run_batch( array $args, array $assoc_args ): void {
		$fetch_limit      = isset( $assoc_args['fetch-limit'] ) ? max( 1, (int) $assoc_args['fetch-limit'] ) : 20;
		$fetch_offset     = isset( $assoc_args['fetch-offset'] ) ? max( 0, (int) $assoc_args['fetch-offset'] ) : 0;
		$process_batch    = isset( $assoc_args['process-batch'] ) ? max( 1, (int) $assoc_args['process-batch'] ) : 20;
		$max_variant_page = isset( $assoc_args['max-variant-pages'] ) ? max( 1, (int) $assoc_args['max-variant-pages'] ) : 1;
		$max_variations   = isset( $assoc_args['max-variations'] ) ? max( 1, (int) $assoc_args['max-variations'] ) : 200;

		try {
			$fetched = $this->importer->fetch_to_staging( $fetch_limit, $fetch_offset, $max_variant_page );
			WP_CLI::log( sprintf( 'Fetch done: products=%d options=%d variants=%d errors=%d', $fetched['products'], $fetched['options'], $fetched['variants'], $fetched['errors'] ) );
		} catch ( Throwable $e ) {
			WP_CLI::error( 'Fetch failed: ' . $e->getMessage() );
			return;
		}

		$processed = $this->importer->process_products( $process_batch, $max_variations );
		WP_CLI::success( sprintf( 'Process done: simple=%d variable=%d errors=%d', $processed['simple'], $processed['variable'], $processed['errors'] ) );
	}
}

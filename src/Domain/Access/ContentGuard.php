<?php
declare( strict_types=1 );

namespace WitsValley\Members\Domain\Access;

use WitsValley\Members\Domain\Notification\Urls;
use WitsValley\Members\Support\Db;
use WitsValley\Members\Support\Settings;

/**
 * Enforces access on: the_content, excerpts, REST, RSS, search, sitemap, comments, cache headers, body class.
 */
final class ContentGuard {

	public const META_LEVEL  = '_wvm_level';
	public const META_PLANS  = '_wvm_plans';
	public const META_TEASER = '_wvm_teaser_paragraphs';
	public const TERM_LEVEL  = '_wvm_level';

	/** @var array<string,bool> */
	private array $decisions = array();

	public function __construct( private LevelResolver $levels, private Settings $settings ) {}

	public function register(): void {
		add_filter( 'the_content', array( $this, 'filterContent' ), 999 );
		add_filter( 'get_the_excerpt', array( $this, 'filterExcerpt' ), 999, 2 );
		add_filter( 'the_content_feed', array( $this, 'filterFeed' ), 999 );
		add_filter( 'the_excerpt_rss', array( $this, 'filterFeed' ), 999 );
		add_action( 'pre_get_posts', array( $this, 'filterQuery' ) );
		add_filter( 'wp_sitemaps_posts_query_args', array( $this, 'filterSitemap' ), 20 );
		add_filter( 'comments_array', array( $this, 'filterComments' ), 999, 2 );
		add_filter( 'comments_open', array( $this, 'filterCommentsOpen' ), 999, 2 );
		add_filter( 'body_class', array( $this, 'bodyClass' ) );
		add_action( 'template_redirect', array( $this, 'templateRedirect' ), 5 );
		add_action( 'rest_api_init', array( $this, 'registerRestFilters' ) );
	}

	public function registerRestFilters(): void {
		foreach ( $this->postTypes() as $type ) {
			add_filter( 'rest_prepare_' . $type, array( $this, 'filterRest' ), 999, 3 );
		}
	}

	/** @return list<string> */
	public function postTypes(): array {
		return array_values( array_filter( (array) $this->settings->get( 'access', 'post_types', array( 'post', 'page' ) ), 'post_type_exists' ) );
	}

	/**
	 * Effective rule for a post.
	 *
	 * @return array{level:int, plans:list<string>, orgs:list<int>, teaser:int, source:string}
	 */
	public function ruleFor( int $postId ): array {
		$post_level = get_post_meta( $postId, self::META_LEVEL, true );
		$plans      = array_values( array_filter( (array) get_post_meta( $postId, self::META_PLANS, true ) ) );
		$teaser     = get_post_meta( $postId, self::META_TEASER, true );
		$source     = 'default';
		if ( '' !== $post_level && null !== $post_level ) {
			$level  = (int) $post_level;
			$source = 'post';
		} else {
			$level = 0;
			$terms = wp_get_object_terms( $postId, get_object_taxonomies( (string) get_post_type( $postId ) ), array( 'fields' => 'ids' ) );
			if ( ! is_wp_error( $terms ) ) {
				foreach ( $terms as $term_id ) {
					$tl = get_term_meta( (int) $term_id, self::TERM_LEVEL, true );
					if ( '' !== $tl && (int) $tl > $level ) {
						$level  = (int) $tl;
						$source = 'term';
					}
				}
			}
			if ( 'default' === $source ) {
				$level = in_array( get_post_type( $postId ), $this->postTypes(), true ) ? (int) $this->settings->get( 'access', 'default_level', 0 ) : 0;
			}
		}
		global $wpdb;
		$orgs = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( 'SELECT organisation_id FROM ' . Db::table( 'organisation_content' ) . ' WHERE post_id = %d', $postId ) ) ); // phpcs:ignore WordPress.DB
		return array(
			'level'  => $level,
			'plans'  => array_map( 'strval', $plans ),
			'orgs'   => $orgs,
			'teaser' => '' === $teaser ? (int) $this->settings->get( 'access', 'teaser_paragraphs', 2 ) : (int) $teaser,
			'source' => $source,
		);
	}

	public function isProtected( int $postId ): bool {
		$rule = $this->ruleFor( $postId );
		return $rule['level'] > 0 || $rule['plans'] || $rule['orgs'];
	}

	public function canView( int $postId, ?int $userId = null ): bool {
		$userId = $userId ?? get_current_user_id();
		$key    = $postId . ':' . $userId;
		if ( isset( $this->decisions[ $key ] ) ) {
			return $this->decisions[ $key ];
		}
		$rule = $this->ruleFor( $postId );
		if ( ! $rule['level'] && ! $rule['plans'] && ! $rule['orgs'] ) {
			$this->decisions[ $key ] = true;
			return $this->decisions[ $key ];
		}
		if ( $userId && ( user_can( $userId, 'edit_post', $postId ) || user_can( $userId, 'wvm_manage_content_access' ) ) ) {
			$this->decisions[ $key ] = true;
			return $this->decisions[ $key ];
		}
		$ent     = $this->levels->entitlements( $userId );
		$allowed = $ent['level'] >= $rule['level'];
		if ( $allowed && $rule['plans'] ) {
			$allowed = (bool) array_intersect( $rule['plans'], $ent['plan_slugs'] );
		}
		if ( $allowed && $rule['orgs'] ) {
			$allowed = (bool) array_intersect( $rule['orgs'], $ent['org_ids'] );
		}
		$allowed                 = (bool) apply_filters( 'wvm_can_view', $allowed, $postId, $userId, $rule );
		$this->decisions[ $key ] = $allowed;
		return $this->decisions[ $key ];
	}

	public function flush(): void {
		$this->decisions = array();
	}

	public function filterContent( string $content ): string {
		$post = get_post();
		if ( ! $post || $this->canView( (int) $post->ID ) ) {
			return $content;
		}
		return $this->teaser( $content, (int) $post->ID ) . $this->paywall( (int) $post->ID );
	}

	public function filterExcerpt( string $excerpt, $post = null ): string {
		$post = get_post( $post );
		if ( ! $post || $this->canView( (int) $post->ID ) ) {
			return $excerpt;
		}
		if ( has_excerpt( $post ) ) {
			return wp_strip_all_tags( (string) $post->post_excerpt );
		}
		return wp_trim_words( wp_strip_all_tags( $this->teaser( (string) $post->post_content, (int) $post->ID, 1 ) ), 40 );
	}

	public function filterFeed( string $content ): string {
		$post = get_post();
		if ( ! $post || ! $this->isProtected( (int) $post->ID ) ) {
			return $content;
		}
		// Feeds are always treated as anonymous.
		if ( $this->canView( (int) $post->ID, 0 ) ) {
			return $content;
		}
		return wp_trim_words( wp_strip_all_tags( $this->teaser( (string) $post->post_content, (int) $post->ID, 1 ) ), 55 ) . ' <a href="' . esc_url( get_permalink( $post ) ) . '">' . esc_html__( 'Members only — read on WitsValley', 'wv-members' ) . '</a>';
	}

	/**
	 * @param \WP_REST_Response $response
	 * @param \WP_Post          $post
	 */
	public function filterRest( $response, $post, $request ) {
		unset( $request );
		if ( ! $post instanceof \WP_Post || $this->canView( (int) $post->ID ) ) {
			return $response;
		}
		$data = $response->get_data();
		if ( isset( $data['content'] ) && is_array( $data['content'] ) ) {
			$data['content']['rendered'] = $this->teaser( (string) $post->post_content, (int) $post->ID ) . $this->paywall( (int) $post->ID );
			unset( $data['content']['raw'] );
			$data['content']['protected'] = true;
		}
		if ( isset( $data['excerpt'] ) && is_array( $data['excerpt'] ) ) {
			$data['excerpt']['rendered'] = '<p>' . esc_html( $this->filterExcerpt( '', $post ) ) . '</p>';
			unset( $data['excerpt']['raw'] );
		}
		$data['wvm_protected']      = true;
		$data['wvm_required_level'] = $this->ruleFor( (int) $post->ID )['level'];
		$response->set_data( $data );
		$response->header( 'Cache-Control', 'private, no-store' );
		return $response;
	}

	public function filterQuery( \WP_Query $query ): void {
		if ( ( is_admin() && ! wp_doing_ajax() ) || ! $query->is_search() ) {
			return;
		}
		$hidden = $this->hiddenPostIds( get_current_user_id() );
		if ( $hidden ) {
			$query->set( 'post__not_in', array_merge( (array) $query->get( 'post__not_in' ), $hidden ) );
		}
	}

	/**
	 * @param array<string,mixed> $args
	 * @return array<string,mixed>
	 */
	public function filterSitemap( array $args ): array {
		$hidden = $this->hiddenPostIds( 0 );
		if ( $hidden ) {
			$args['post__not_in'] = array_merge( (array) ( $args['post__not_in'] ?? array() ), $hidden );
		}
		return $args;
	}

	/**
	 * @param array<int,mixed> $comments
	 * @return array<int,mixed>
	 */
	public function filterComments( array $comments, int $postId ): array {
		return $this->canView( $postId ) ? $comments : array();
	}

	public function filterCommentsOpen( bool $open, $postId ): bool {
		return $open && ( ! $postId || $this->canView( (int) $postId ) );
	}

	/**
	 * @param list<string> $classes
	 * @return list<string>
	 */
	public function bodyClass( array $classes ): array {
		$classes[] = 'wvm-level-' . $this->levels->levelFor( get_current_user_id() );
		if ( is_singular() && ! $this->canView( (int) get_queried_object_id() ) ) {
			$classes[] = 'wvm-locked';
		}
		return $classes;
	}

	public function templateRedirect(): void {
		if ( ! is_singular() ) {
			return;
		}
		$id = (int) get_queried_object_id();
		if ( ! $id || ! $this->isProtected( $id ) ) {
			return;
		}
		// Protected pages are never cached publicly, whether or not the viewer may see them.
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
		nocache_headers();
		header( 'Cache-Control: private, no-store, max-age=0' );
		header( 'Vary: Cookie', false );
		if ( $this->canView( $id ) ) {
			return;
		}
		$mode = (string) $this->settings->get( 'access', 'mode', 'teaser' );
		if ( 'redirect' === $mode ) {
			wp_safe_redirect( add_query_arg( 'from', rawurlencode( (string) get_permalink( $id ) ), Urls::pricing() ) );
			exit;
		}
		if ( 'login' === $mode && ! is_user_logged_in() ) {
			wp_safe_redirect( wp_login_url( (string) get_permalink( $id ) ) );
			exit;
		}
	}

	/**
	 * Post IDs the user may not see (for search/sitemap exclusion).
	 *
	 * @return list<int>
	 */
	public function hiddenPostIds( int $userId ): array {
		global $wpdb;
		$level    = $this->levels->levelFor( $userId );
		$ids      = array();
		$explicit = $wpdb->get_col( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND CAST(meta_value AS UNSIGNED) > %d", self::META_LEVEL, $level ) ); // phpcs:ignore WordPress.DB
		$ids      = array_merge( $ids, array_map( 'intval', $explicit ) );
		$terms    = $wpdb->get_col( $wpdb->prepare( "SELECT term_id FROM {$wpdb->termmeta} WHERE meta_key = %s AND CAST(meta_value AS UNSIGNED) > %d", self::TERM_LEVEL, $level ) ); // phpcs:ignore WordPress.DB
		if ( $terms ) {
			$tt = $wpdb->get_col( "SELECT term_taxonomy_id FROM {$wpdb->term_taxonomy} WHERE term_id IN (" . implode( ',', array_map( 'intval', $terms ) ) . ')' ); // phpcs:ignore WordPress.DB
			if ( $tt ) {
				$by_term = $wpdb->get_col( $wpdb->prepare( "SELECT tr.object_id FROM {$wpdb->term_relationships} tr WHERE tr.term_taxonomy_id IN (" . implode( ',', array_map( 'intval', $tt ) ) . ") AND NOT EXISTS (SELECT 1 FROM {$wpdb->postmeta} pm WHERE pm.post_id = tr.object_id AND pm.meta_key = %s AND CAST(pm.meta_value AS UNSIGNED) <= %d)", self::META_LEVEL, $level ) ); // phpcs:ignore WordPress.DB
				$ids     = array_merge( $ids, array_map( 'intval', $by_term ) );
			}
		}
		$default = (int) $this->settings->get( 'access', 'default_level', 0 );
		if ( $default > $level ) {
			$types = $this->postTypes();
			if ( $types ) {
				$in     = "'" . implode( "','", array_map( 'esc_sql', $types ) ) . "'";
				$by_def = $wpdb->get_col( $wpdb->prepare( "SELECT p.ID FROM {$wpdb->posts} p WHERE p.post_type IN ({$in}) AND p.post_status = 'publish' AND NOT EXISTS (SELECT 1 FROM {$wpdb->postmeta} pm WHERE pm.post_id = p.ID AND pm.meta_key = %s AND CAST(pm.meta_value AS UNSIGNED) <= %d)", self::META_LEVEL, $level ) ); // phpcs:ignore WordPress.DB
				$ids    = array_merge( $ids, array_map( 'intval', $by_def ) );
			}
		}
		$planned = $wpdb->get_col( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value <> '' AND meta_value <> 'a:0:{}'", self::META_PLANS ) ); // phpcs:ignore WordPress.DB
		foreach ( $planned as $pid ) {
			if ( ! $this->canView( (int) $pid, $userId ) ) {
				$ids[] = (int) $pid;
			}
		}
		$org = $wpdb->get_col( 'SELECT DISTINCT post_id FROM ' . Db::table( 'organisation_content' ) ); // phpcs:ignore WordPress.DB
		foreach ( $org as $pid ) {
			if ( ! $this->canView( (int) $pid, $userId ) ) {
				$ids[] = (int) $pid;
			}
		}
		if ( $userId ) {
			$ids = array_filter( $ids, fn( $id ) => ! $this->canView( (int) $id, $userId ) );
		}
		return array_values( array_unique( array_map( 'intval', $ids ) ) );
	}

	public function teaser( string $content, int $postId, ?int $paragraphs = null ): string {
		$rule       = $this->ruleFor( $postId );
		$paragraphs = $paragraphs ?? $rule['teaser'];
		if ( $paragraphs <= 0 ) {
			return '';
		}
		$content = (string) preg_replace( '/<!--\s*more\s*-->.*$/s', '', $content );
		$content = (string) preg_replace( '/<!--\s*\/?wp:[^>]*-->/', '', $content );
		preg_match_all( '#<p[^>]*>.*?</p>#is', $content, $m );
		$paras = $m[0] ?: array_filter( array_map( 'trim', preg_split( "/\n\s*\n/", wp_strip_all_tags( $content ) ) ?: array() ) );
		$out   = array();
		foreach ( array_slice( $paras, 0, $paragraphs ) as $p ) {
			$out[] = str_starts_with( trim( (string) $p ), '<p' ) ? wp_kses_post( (string) $p ) : '<p>' . esc_html( (string) $p ) . '</p>';
		}
		return '<div class="wvm-teaser">' . implode( '', $out ) . '</div>';
	}

	public function paywall( int $postId ): string {
		$rule    = $this->ruleFor( $postId );
		$message = (string) $this->settings->get( 'access', 'paywall_message', '' );
		$level   = LevelResolver::label( max( 1, $rule['level'] ) );
		$logged  = is_user_logged_in();
		$cta_url = $logged ? Urls::dashboard() : add_query_arg( 'from', rawurlencode( (string) get_permalink( $postId ) ), Urls::pricing() );
		$cta     = $logged ? __( 'Upgrade membership', 'wv-members' ) : __( 'See membership plans', 'wv-members' );
		$login   = $logged ? '' : '<a class="wvm-paywall__login" href="' . esc_url( wp_login_url( (string) get_permalink( $postId ) ) ) . '">' . esc_html__( 'Already a member? Log in', 'wv-members' ) . '</a>';
		return '<div class="wvm-paywall" role="region" aria-label="' . esc_attr__( 'Members-only content', 'wv-members' ) . '">'
			. '<div class="wvm-paywall__badge">' . esc_html( sprintf( /* translators: %s level name */ __( '%s members', 'wv-members' ), $level ) ) . '</div>'
			. '<h3 class="wvm-paywall__title">' . esc_html__( 'Keep reading with WitsValley', 'wv-members' ) . '</h3>'
			. '<p class="wvm-paywall__text">' . esc_html( $message ) . '</p>'
			. '<a class="wvm-paywall__cta" href="' . esc_url( $cta_url ) . '">' . esc_html( $cta ) . '</a>' . $login
			. '</div>';
	}
}

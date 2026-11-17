<?php
declare( strict_types=1 );

namespace WitsValley\Members\Admin;

use WitsValley\Members\Domain\Access\ContentGuard;
use WitsValley\Members\Domain\Access\LevelResolver;
use WitsValley\Members\Domain\Audit\Audit;
use WitsValley\Members\Domain\Plan\PlanRepository;

/**
 * Per-post access meta box, list column, quick/bulk edit, and term defaults.
 */
final class ContentMetaBox {

	public function __construct( private ContentGuard $guard, private PlanRepository $plans, private Audit $audit ) {}

	public function register(): void {
		add_action( 'add_meta_boxes', array( $this, 'addBox' ) );
		add_action( 'save_post', array( $this, 'save' ), 10, 2 );
		add_action( 'admin_init', array( $this, 'registerColumnsAndTerms' ) );
		add_action( 'bulk_edit_custom_box', array( $this, 'bulkBox' ), 10, 2 );
		add_action( 'quick_edit_custom_box', array( $this, 'bulkBox' ), 10, 2 );
		add_action( 'admin_footer-edit.php', array( $this, 'quickEditScript' ) );
	}

	public function addBox(): void {
		if ( ! current_user_can( 'wvm_manage_content_access' ) ) {
			return;
		}
		foreach ( $this->guard->postTypes() as $type ) {
			add_meta_box( 'wvm-access', __( 'WitsValley access', 'wv-members' ), array( $this, 'renderBox' ), $type, 'side', 'high' );
		}
	}

	public function renderBox( \WP_Post $post ): void {
		wp_nonce_field( 'wvm_access_save', 'wvm_access_nonce' );
		$level  = get_post_meta( $post->ID, ContentGuard::META_LEVEL, true );
		$plans  = (array) get_post_meta( $post->ID, ContentGuard::META_PLANS, true );
		$teaser = get_post_meta( $post->ID, ContentGuard::META_TEASER, true );
		$rule   = $this->guard->ruleFor( (int) $post->ID );
		echo '<p><label for="wvm_level"><strong>' . esc_html__( 'Required level', 'wv-members' ) . '</strong></label><br><select name="wvm_level" id="wvm_level" style="width:100%">';
		echo '<option value="">' . esc_html( sprintf( /* translators: %s level */ __( 'Inherit (currently %s)', 'wv-members' ), LevelResolver::label( 'post' === $rule['source'] ? 0 : $rule['level'] ) ) ) . '</option>';
		foreach ( LevelResolver::LEVELS as $lvl => $label ) {
			echo '<option value="' . (int) $lvl . '"' . selected( (string) $level, (string) $lvl, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select></p>';
		$all = array_filter( $this->plans->all(), static fn( $p ) => ! $p['is_free'] );
		if ( $all ) {
			echo '<p><strong>' . esc_html__( 'Only these plans (optional)', 'wv-members' ) . '</strong></p>';
			foreach ( $all as $p ) {
				echo '<label style="display:block"><input type="checkbox" name="wvm_plans[]" value="' . esc_attr( (string) $p['slug'] ) . '"' . checked( in_array( $p['slug'], $plans, true ), true, false ) . '> ' . esc_html( (string) $p['name'] ) . '</label>';
			}
		}
		echo '<p><label for="wvm_teaser"><strong>' . esc_html__( 'Teaser paragraphs', 'wv-members' ) . '</strong></label><br><input type="number" min="0" max="20" name="wvm_teaser" id="wvm_teaser" value="' . esc_attr( (string) $teaser ) . '" placeholder="' . esc_attr__( 'Default', 'wv-members' ) . '" style="width:100%"></p>';
		echo '<p class="description">' . esc_html__( 'Members below the required level see the teaser and a join/upgrade card. Content stays out of REST, RSS, search and sitemaps.', 'wv-members' ) . '</p>';
	}

	public function save( int $postId, \WP_Post $post ): void {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! current_user_can( 'wvm_manage_content_access' ) || ! current_user_can( 'edit_post', $postId ) || ! in_array( $post->post_type, $this->guard->postTypes(), true ) ) {
			return;
		}
		$single = isset( $_POST['wvm_access_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wvm_access_nonce'] ) ), 'wvm_access_save' );
		$bulk   = isset( $_REQUEST['wvm_bulk_level'] ) && isset( $_REQUEST['_wpnonce'] ) && ( wp_verify_nonce( sanitize_text_field( wp_unslash( $_REQUEST['_wpnonce'] ) ), 'bulk-posts' ) || ( isset( $_POST['_inline_edit'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_inline_edit'] ) ), 'inlineeditnonce' ) ) );
		if ( ! $single && ! $bulk && ! ( isset( $_POST['_inline_edit'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_inline_edit'] ) ), 'inlineeditnonce' ) && isset( $_POST['wvm_bulk_level'] ) ) ) {
			return;
		}
		$before = $this->guard->ruleFor( $postId );
		if ( $single ) {
			$level = isset( $_POST['wvm_level'] ) ? sanitize_text_field( wp_unslash( $_POST['wvm_level'] ) ) : '';
			'' === $level ? delete_post_meta( $postId, ContentGuard::META_LEVEL ) : update_post_meta( $postId, ContentGuard::META_LEVEL, max( 0, min( 9, (int) $level ) ) );
			$plans = isset( $_POST['wvm_plans'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['wvm_plans'] ) ) : array();
			$plans ? update_post_meta( $postId, ContentGuard::META_PLANS, $plans ) : delete_post_meta( $postId, ContentGuard::META_PLANS );
			$teaser = isset( $_POST['wvm_teaser'] ) ? sanitize_text_field( wp_unslash( $_POST['wvm_teaser'] ) ) : '';
			'' === $teaser ? delete_post_meta( $postId, ContentGuard::META_TEASER ) : update_post_meta( $postId, ContentGuard::META_TEASER, max( 0, (int) $teaser ) );
		} else {
			$level = sanitize_text_field( wp_unslash( (string) $_REQUEST['wvm_bulk_level'] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			if ( '__nochange' === $level ) {
				return;
			}
			'' === $level ? delete_post_meta( $postId, ContentGuard::META_LEVEL ) : update_post_meta( $postId, ContentGuard::META_LEVEL, max( 0, min( 9, (int) $level ) ) );
		}
		$this->guard->flush();
		$after = $this->guard->ruleFor( $postId );
		if ( $before !== $after ) {
			$this->audit->log( 'access.post_updated', 'post', $postId, $before, $after );
		}
	}

	public function registerColumnsAndTerms(): void {
		foreach ( $this->guard->postTypes() as $type ) {
			add_filter( "manage_{$type}_posts_columns", array( $this, 'column' ) );
			add_action( "manage_{$type}_posts_custom_column", array( $this, 'columnValue' ), 10, 2 );
			if ( 'page' === $type ) {
				add_action( 'manage_pages_custom_column', array( $this, 'columnValue' ), 10, 2 );
			}
			foreach ( get_object_taxonomies( $type ) as $tax ) {
				add_action( "{$tax}_add_form_fields", array( $this, 'termAddField' ) );
				add_action( "{$tax}_edit_form_fields", array( $this, 'termEditField' ) );
				add_action( "created_{$tax}", array( $this, 'termSave' ) );
				add_action( "edited_{$tax}", array( $this, 'termSave' ) );
			}
		}
	}

	/** @param array<string,string> $cols */
	public function column( array $cols ): array {
		$cols['wvm_access'] = __( 'Access', 'wv-members' );
		return $cols;
	}

	public function columnValue( string $col, int $postId ): void {
		if ( 'wvm_access' !== $col ) {
			return;
		}
		$rule  = $this->guard->ruleFor( $postId );
		$label = LevelResolver::label( $rule['level'] ) . ( $rule['plans'] ? ' · ' . implode( ', ', $rule['plans'] ) : '' ) . ( $rule['orgs'] ? ' · org' : '' );
		$raw   = get_post_meta( $postId, ContentGuard::META_LEVEL, true );
		echo '<span class="wvm-access-col" data-level="' . esc_attr( (string) $raw ) . '">' . esc_html( $label ) . ( 'term' === $rule['source'] ? ' <em>(category)</em>' : '' ) . '</span>';
	}

	public function bulkBox( string $col, string $type ): void {
		if ( 'wvm_access' !== $col || ! current_user_can( 'wvm_manage_content_access' ) ) {
			return;
		}
		unset( $type );
		echo '<fieldset class="inline-edit-col-right"><div class="inline-edit-col"><label class="alignleft"><span class="title">' . esc_html__( 'Access', 'wv-members' ) . '</span><select name="wvm_bulk_level"><option value="__nochange">' . esc_html__( '— No change —', 'wv-members' ) . '</option><option value="">' . esc_html__( 'Inherit', 'wv-members' ) . '</option>';
		foreach ( LevelResolver::LEVELS as $lvl => $label ) {
			echo '<option value="' . (int) $lvl . '">' . esc_html( $label ) . '</option>';
		}
		echo '</select></label></div></fieldset>';
	}

	public function quickEditScript(): void {
		echo "<script>jQuery(function($){if(!window.inlineEditPost)return;var o=inlineEditPost.edit;inlineEditPost.edit=function(id){o.apply(this,arguments);var pid=typeof id==='object'?parseInt(this.getId(id)):id;var lv=$('#post-'+pid+' .wvm-access-col').data('level');$('#edit-'+pid+' select[name=wvm_bulk_level]').val(lv===''||lv===undefined?'':String(lv));};});</script>";
	}

	public function termAddField(): void {
		if ( ! current_user_can( 'wvm_manage_content_access' ) ) {
			return;
		}
		wp_nonce_field( 'wvm_term_save', 'wvm_term_nonce' );
		echo '<div class="form-field"><label for="wvm_term_level">' . esc_html__( 'WitsValley default access', 'wv-members' ) . '</label><select name="wvm_term_level" id="wvm_term_level">';
		foreach ( LevelResolver::LEVELS as $lvl => $label ) {
			echo '<option value="' . (int) $lvl . '">' . esc_html( $label ) . '</option>';
		}
		echo '</select><p>' . esc_html__( 'Posts in this term require at least this level unless the post overrides it.', 'wv-members' ) . '</p></div>';
	}

	public function termEditField( \WP_Term $term ): void {
		if ( ! current_user_can( 'wvm_manage_content_access' ) ) {
			return;
		}
		$current = (int) get_term_meta( $term->term_id, ContentGuard::TERM_LEVEL, true );
		echo '<tr class="form-field"><th scope="row"><label for="wvm_term_level">' . esc_html__( 'WitsValley default access', 'wv-members' ) . '</label></th><td>';
		wp_nonce_field( 'wvm_term_save', 'wvm_term_nonce' );
		echo '<select name="wvm_term_level" id="wvm_term_level">';
		foreach ( LevelResolver::LEVELS as $lvl => $label ) {
			echo '<option value="' . (int) $lvl . '"' . selected( $current, $lvl, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select><p class="description">' . esc_html__( 'Posts in this term require at least this level unless the post overrides it.', 'wv-members' ) . '</p></td></tr>';
	}

	public function termSave( int $termId ): void {
		if ( ! isset( $_POST['wvm_term_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wvm_term_nonce'] ) ), 'wvm_term_save' ) || ! current_user_can( 'wvm_manage_content_access' ) ) {
			return;
		}
		$level  = isset( $_POST['wvm_term_level'] ) ? max( 0, min( 9, (int) $_POST['wvm_term_level'] ) ) : 0;
		$before = (int) get_term_meta( $termId, ContentGuard::TERM_LEVEL, true );
		$level ? update_term_meta( $termId, ContentGuard::TERM_LEVEL, $level ) : delete_term_meta( $termId, ContentGuard::TERM_LEVEL );
		if ( $before !== $level ) {
			$this->audit->log( 'access.term_updated', 'term', $termId, array( 'level' => $before ), array( 'level' => $level ) );
		}
	}
}

<?php
declare( strict_types=1 );

namespace WitsValley\Members\Domain\Access;

use WitsValley\Members\Domain\Audit\Audit;
use WitsValley\Members\Support\DomainError;
use WitsValley\Members\Support\Pagination;
use WitsValley\Members\Support\Settings;

/**
 * Admin operations on content-access rules (post meta + term meta + settings).
 */
final class AccessService {

	public function __construct(
		private ContentGuard $guard,
		private MediaGuard $media,
		private LevelResolver $levels,
		private Settings $settings,
		private Audit $audit
	) {}

	/**
	 * Protected posts list.
	 *
	 * @param array<string,mixed> $f post_type, level
	 * @return array{items:list<array<string,mixed>>, meta:array<string,int>}
	 */
	public function rules( array $f, Pagination $p ): array {
		$types = ! empty( $f['post_type'] ) ? array( sanitize_key( (string) $f['post_type'] ) ) : array_merge( $this->guard->postTypes(), array( 'attachment' ) );
		$meta  = array(
			'relation' => 'OR',
			array(
				'key'     => ContentGuard::META_LEVEL,
				'value'   => isset( $f['level'] ) && '' !== $f['level'] ? (int) $f['level'] : 0,
				'compare' => isset( $f['level'] ) && '' !== $f['level'] ? '=' : '>',
				'type'    => 'NUMERIC',
			),
		);
		if ( ! isset( $f['level'] ) || '' === $f['level'] ) {
			$meta[] = array(
				'key'     => ContentGuard::META_PLANS,
				'compare' => 'EXISTS',
			);
		}
		$q = new \WP_Query(
			array(
				'post_type'      => $types,
				'post_status'    => array( 'publish', 'draft', 'private', 'future', 'inherit' ),
				'posts_per_page' => $p->perPage,
				'paged'          => $p->page,
				'meta_query'     => $meta, // phpcs:ignore WordPress.DB.SlowDBQuery
				's'              => (string) ( $f['q'] ?? '' ),
				'orderby'        => 'modified',
				'order'          => 'DESC',
			)
		);
		$items = array();
		foreach ( $q->posts as $post ) {
			$items[] = $this->presentPost( $post );
		}
		return array(
			'items' => $items,
			'meta'  => $p->meta( (int) $q->found_posts ),
		);
	}

	/**
	 * Search any posts (for content pickers).
	 *
	 * @return list<array<string,mixed>>
	 */
	public function searchPosts( string $q, int $limit = 20 ): array {
		$query = new \WP_Query(
			array(
				'post_type'      => $this->guard->postTypes(),
				'post_status'    => array( 'publish', 'draft', 'private', 'future' ),
				's'              => $q,
				'posts_per_page' => $limit,
			)
		);
		return array_map( array( $this, 'presentPost' ), $query->posts );
	}

	/** @return array<string,mixed> */
	public function presentPost( \WP_Post $post ): array {
		$rule = $this->guard->ruleFor( (int) $post->ID );
		return array(
			'id'                => (int) $post->ID,
			'title'             => get_the_title( $post ) ?: '(no title)',
			'post_type'         => $post->post_type,
			'status'            => $post->post_status,
			'level'             => $rule['level'],
			'level_label'       => LevelResolver::label( $rule['level'] ),
			'plans'             => $rule['plans'],
			'orgs'              => $rule['orgs'],
			'teaser_paragraphs' => '' === get_post_meta( (int) $post->ID, ContentGuard::META_TEASER, true ) ? null : (int) get_post_meta( (int) $post->ID, ContentGuard::META_TEASER, true ),
			'source'            => $rule['source'],
			'edit_url'          => (string) get_edit_post_link( (int) $post->ID, 'raw' ),
			'view_url'          => (string) get_permalink( $post ),
		);
	}

	/**
	 * @param array<string,mixed> $dto level, plans[], teaser_paragraphs
	 * @return array<string,mixed>
	 */
	public function updatePost( int $postId, array $dto ): array {
		$post = get_post( $postId );
		if ( ! $post ) {
			throw DomainError::notFound( 'post' );
		}
		$before = $this->presentPost( $post );
		if ( array_key_exists( 'level', $dto ) ) {
			if ( null === $dto['level'] || '' === $dto['level'] ) {
				delete_post_meta( $postId, ContentGuard::META_LEVEL );
			} elseif ( 'attachment' === $post->post_type ) {
				$this->media->setLevel( $postId, max( 0, min( 9, (int) $dto['level'] ) ) );
			} else {
				update_post_meta( $postId, ContentGuard::META_LEVEL, max( 0, min( 9, (int) $dto['level'] ) ) );
			}
		}
		if ( array_key_exists( 'plans', $dto ) ) {
			$plans = array_values( array_filter( array_map( 'sanitize_key', (array) $dto['plans'] ) ) );
			$plans ? update_post_meta( $postId, ContentGuard::META_PLANS, $plans ) : delete_post_meta( $postId, ContentGuard::META_PLANS );
		}
		if ( array_key_exists( 'teaser_paragraphs', $dto ) ) {
			null === $dto['teaser_paragraphs'] || '' === $dto['teaser_paragraphs'] ? delete_post_meta( $postId, ContentGuard::META_TEASER ) : update_post_meta( $postId, ContentGuard::META_TEASER, max( 0, (int) $dto['teaser_paragraphs'] ) );
		}
		$this->guard->flush();
		$after = $this->presentPost( get_post( $postId ) );
		$this->audit->log( 'access.post_updated', 'post', $postId, $before, $after );
		return $after;
	}

	/** @return list<array<string,mixed>> */
	public function terms(): array {
		$out = array();
		foreach ( $this->guard->postTypes() as $type ) {
			foreach ( get_object_taxonomies( $type, 'objects' ) as $tax ) {
				if ( ! $tax->public || 'post_format' === $tax->name ) {
					continue;
				}
				$terms = get_terms(
					array(
						'taxonomy'   => $tax->name,
						'hide_empty' => false,
						'number'     => 200,
					)
				);
				if ( is_wp_error( $terms ) ) {
					continue;
				}
				foreach ( $terms as $term ) {
					$lvl                    = get_term_meta( $term->term_id, ContentGuard::TERM_LEVEL, true );
					$out[ $term->term_id ] = array(
						'id'          => (int) $term->term_id,
						'name'        => $term->name,
						'taxonomy'    => $tax->name,
						'taxonomy_label' => $tax->labels->singular_name,
						'count'       => (int) $term->count,
						'level'       => '' === $lvl ? 0 : (int) $lvl,
						'level_label' => LevelResolver::label( '' === $lvl ? 0 : (int) $lvl ),
					);
				}
			}
		}
		return array_values( $out );
	}

	/** @return array<string,mixed> */
	public function updateTerm( int $termId, int $level ): array {
		$term = get_term( $termId );
		if ( ! $term || is_wp_error( $term ) ) {
			throw DomainError::notFound( 'term' );
		}
		$before = (int) get_term_meta( $termId, ContentGuard::TERM_LEVEL, true );
		$level <= 0 ? delete_term_meta( $termId, ContentGuard::TERM_LEVEL ) : update_term_meta( $termId, ContentGuard::TERM_LEVEL, min( 9, $level ) );
		$this->guard->flush();
		$this->audit->log( 'access.term_updated', 'term', $termId, array( 'level' => $before ), array( 'level' => $level ) );
		return array(
			'id'    => $termId,
			'name'  => $term->name,
			'level' => max( 0, $level ),
		);
	}

	/** @return array<string,mixed> */
	public function check( int $postId, int $userId ): array {
		$this->levels->flush();
		$this->guard->flush();
		return array(
			'post_id'      => $postId,
			'user_id'      => $userId,
			'rule'         => $this->guard->ruleFor( $postId ),
			'entitlements' => $this->levels->entitlements( $userId ),
			'can_view'     => $this->guard->canView( $postId, $userId ),
		);
	}
}
